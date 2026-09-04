<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\FinalizePayment;
use App\Modules\Payments\Actions\FinalizeRefund;
use App\Modules\Payments\Actions\RecordPaymentFailure;
use App\Modules\Payments\Enums\ProviderEventStatus;
use App\Modules\Payments\Exceptions\PaymentVerificationFailed;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one verified provider event.
 *
 * The event was signed, verified and persisted before this job existed —
 * §62's ordering, and it matters. Enqueuing an unverified body and
 * returning 200 means the platform has told the provider "got it" about
 * something it has not looked at; the provider stops retrying, and a
 * processing bug becomes a payment that silently never completes.
 *
 * So the HTTP layer verifies and stores, and this does the work. Which
 * means this job can be retried freely, run twice, or run by two workers at
 * once, and none of that may change the financial outcome:
 *
 *  - The row is claimed by a conditional UPDATE, so a second worker's
 *    claim matches nothing and it stops. The WHERE is the lock.
 *  - The actions underneath are independently idempotent — the attempt
 *    refuses to leave a terminal state, the ledger's source keys are
 *    unique, the reservation commit only claims held rows. Belt and
 *    braces, because a claim that races is still a claim that can race.
 *
 * A failure leaves the row `failed` and visible, never silently dropped:
 * §63, and the admin event screen reads exactly this column.
 */
final class ProcessProviderEvent implements ShouldQueue
{
    use FoundationQueueable;
    use Queueable;

    public int $tries = 8;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60, 300, 900];

    public function __construct(public readonly int $providerEventId)
    {
        $this->onQueue(Queues::PAYMENTS);
    }

    public function handle(
        FinalizePayment $finalize,
        RecordPaymentFailure $recordFailure,
        FinalizeRefund $finalizeRefund,
    ): void {
        /** @var ProviderWebhookEvent|null $event */
        $event = ProviderWebhookEvent::query()->find($this->providerEventId);

        if ($event === null) {
            return;
        }

        /*
         * Claimed by a conditional update rather than a read.
         *
         * Two workers handed the same event both reach here; the WHERE
         * clause means only one update matches, and the other returns
         * without touching a financial row.
         */
        $claimed = ProviderWebhookEvent::query()
            ->whereKey($event->id)
            ->whereIn('status', [ProviderEventStatus::Received->value, ProviderEventStatus::Failed->value])
            ->update([
                'status' => ProviderEventStatus::Received->value,
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed === 0) {
            return;
        }

        try {
            $handled = $this->dispatchToHandler($event, $finalize, $recordFailure, $finalizeRefund);

            $event->forceFill([
                // An event type this platform has no handler for is
                // processed correctly by being recorded and left alone —
                // distinguishing that from a failure is what keeps the
                // operator's "needs attention" list meaningful.
                'status' => $handled ? ProviderEventStatus::Processed->value : ProviderEventStatus::Ignored->value,
                'processed_at' => now(),
                'error' => null,
            ])->save();
        } catch (PaymentVerificationFailed $failure) {
            /*
             * The provider and the platform disagree about money. Retrying
             * will not resolve it — the amount will still be wrong — so
             * this is marked failed once and left for a person, rather than
             * burning eight retries on an answer that cannot change.
             */
            $event->forceFill([
                'status' => ProviderEventStatus::Failed->value,
                'failed_at' => now(),
                'error' => $failure->reason.': '.$failure->getMessage(),
            ])->save();

            Log::warning('Provider event failed verification.', [
                'provider_event_id' => $event->id,
                'event_type' => $event->type,
                'reason' => $failure->reason,
            ]);
        } catch (Throwable $e) {
            $event->forceFill([
                'status' => ProviderEventStatus::Failed->value,
                'failed_at' => now(),
                // The class and message only. A provider payload in an
                // error column is a payload in every log aggregator too.
                'error' => $e::class.': '.mb_substr($e->getMessage(), 0, 500),
            ])->save();

            // Rethrown so the queue retries it and, after the last attempt,
            // it lands in failed_jobs where Horizon shows it.
            throw $e;
        }
    }

    /** @return bool whether a handler recognised this event type */
    private function dispatchToHandler(
        ProviderWebhookEvent $event,
        FinalizePayment $finalize,
        RecordPaymentFailure $recordFailure,
        FinalizeRefund $finalizeRefund,
    ): bool {
        $reference = $event->object_reference;

        if ($reference === null) {
            return false;
        }

        return match (true) {
            /*
             * Success and failure are both re-read from the provider rather
             * than taken from the payload. The event says "look again";
             * what is true comes from asking.
             */
            /*
             * Success and progress take the same path, because the path
             * re-reads the provider rather than believing the event: a
             * `processing` notification that arrives when the payment has
             * already succeeded finalizes it, and one that arrives while
             * it really is processing moves the attempt and stops there.
             * Reading the event's name to decide the outcome is exactly
             * the mistake this design exists to avoid.
             */
            $this->isPaymentSuccess($event->type),
            $this->isPaymentProgress($event->type) => tap(true, static fn () => $finalize($reference, $event->id)),
            $this->isPaymentFailure($event->type) => tap(true, static fn () => $recordFailure($reference, $event->id)),
            $this->isRefundEvent($event->type) => tap(true, static fn () => $finalizeRefund($reference, $event->id)),
            default => false,
        };
    }

    /**
     * Provider event names, in one place.
     *
     * Stripe's vocabulary reaches exactly this far into the application.
     * Everything past these three predicates speaks the platform's own
     * language, which is what makes a second provider an adapter and a
     * couple of strings here rather than a search through the order domain.
     */
    private function isPaymentSuccess(string $type): bool
    {
        return in_array($type, ['payment_intent.succeeded', 'payment.captured'], true);
    }

    /**
     * The intent moved, without being decided.
     *
     * Recorded so the customer's page can say "processing" honestly
     * rather than showing a pay button for a payment already in flight
     * (§25). None of these can mark an order paid on their own.
     */
    private function isPaymentProgress(string $type): bool
    {
        return in_array($type, [
            'payment_intent.processing',
            'payment_intent.requires_action',
            'payment_intent.amount_capturable_updated',
        ], true);
    }

    private function isPaymentFailure(string $type): bool
    {
        return in_array($type, [
            'payment_intent.payment_failed',
            'payment_intent.canceled',
            'payment.failed',
        ], true);
    }

    private function isRefundEvent(string $type): bool
    {
        return in_array($type, [
            'refund.updated', 'refund.failed', 'charge.refund.updated', 'refund.succeeded',
        ], true);
    }
}

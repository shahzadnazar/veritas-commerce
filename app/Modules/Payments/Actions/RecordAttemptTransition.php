<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentAttemptEvent;

/**
 * Moves an attempt to a new state, or declines to.
 *
 * The single place a payment attempt's status changes, so the rules about
 * which changes are legal live in one readable spot rather than in each
 * handler that remembers to check.
 *
 * §14 is the reason it returns a bool rather than throwing. Provider events
 * arrive out of order — a `processing` notification can be delivered after
 * the `succeeded` one that followed it — and a stale event is not an error
 * to be escalated, it is an event to be dropped. The caller records that it
 * dropped one; nothing regresses.
 */
final class RecordAttemptTransition
{
    /** @return bool whether the attempt actually moved */
    public function __invoke(
        PaymentAttempt $attempt,
        PaymentAttemptStatus $to,
        string $source,
        ?string $providerStatus = null,
        ?int $providerEventId = null,
        ?string $note = null,
    ): bool {
        $from = $attempt->status;

        if ($from === $to) {
            // Not a transition and not a failure: a provider that sends the
            // same state twice is a provider behaving normally.
            return false;
        }

        if (! in_array($to, $from->allowedTransitions(), true)) {
            /*
             * A terminal attempt refuses everything, which is what stops a
             * late `processing` event pulling a succeeded payment back into
             * limbo — and stops a stale `failed` event un-paying an order.
             */
            return false;
        }

        $attempt->forceFill([
            'status' => $to->value,
            'provider_status' => $providerStatus ?? $attempt->provider_status,
            'event_sequence' => $attempt->event_sequence + 1,
            ...match ($to) {
                PaymentAttemptStatus::Succeeded => ['succeeded_at' => now()],
                PaymentAttemptStatus::Failed => ['failed_at' => now()],
                PaymentAttemptStatus::Cancelled => ['cancelled_at' => now()],
                default => [],
            },
        ])->save();

        PaymentAttemptEvent::query()->create([
            'payment_attempt_id' => $attempt->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'provider_status' => $providerStatus,
            'source' => $source,
            'provider_webhook_event_id' => $providerEventId,
            'note' => $note,
            'created_at' => now(),
        ]);

        return true;
    }
}

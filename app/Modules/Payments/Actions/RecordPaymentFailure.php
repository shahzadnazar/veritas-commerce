<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Records that a payment did not go through.
 *
 * §20's policy, and the part that matters is what this does NOT do: it
 * does not cancel the order, and it does not release the reservation. A
 * declined card is not an abandoned purchase — it is a customer reaching
 * for a different card, and destroying their order at that moment would
 * lose the sale and put their held stock back on the shelf while they were
 * still typing.
 *
 * So the attempt is closed and the order stays pending payment with its
 * hold intact, until the existing M4 expiry sweep closes it on the ordinary
 * schedule. Nothing new decides when an order dies; the mechanism that
 * already did keeps doing it.
 *
 * The failure reason is stored for operators. What the customer sees is
 * written elsewhere, in the platform's own words (§53).
 */
final class RecordPaymentFailure
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly RecordAttemptTransition $transition,
    ) {}

    public function __invoke(string $providerReference, ?int $providerEventId = null): bool
    {
        // Re-read rather than trusting the payload, for the same reason
        // success is re-read: a payload is a notification, not the truth.
        $providerPayment = $this->provider->retrievePayment($providerReference);

        /** @var PaymentAttempt|null $attempt */
        $attempt = PaymentAttempt::query()
            ->where('provider', $providerPayment->provider)
            ->where('provider_reference', $providerReference)
            ->first();

        if ($attempt === null) {
            return false;
        }

        return DB::transaction(function () use ($attempt, $providerPayment, $providerEventId): bool {
            /** @var PaymentAttempt $locked */
            $locked = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            $to = match ($providerPayment->status) {
                PaymentAttemptStatus::Cancelled => PaymentAttemptStatus::Cancelled,
                PaymentAttemptStatus::Succeeded => PaymentAttemptStatus::Succeeded,
                // A provider that returns the intent to "requires a payment
                // method" after a decline is describing a retryable state,
                // and the platform records it as the failure it was.
                default => PaymentAttemptStatus::Failed,
            };

            if ($to === PaymentAttemptStatus::Succeeded) {
                // A "failed" event about a payment that actually succeeded
                // is a stale event. Finalization is not this action's job.
                return false;
            }

            $moved = ($this->transition)(
                $locked,
                $to,
                source: 'provider_event',
                providerStatus: $providerPayment->providerStatus,
                providerEventId: $providerEventId,
            );

            if ($moved) {
                $locked->forceFill([
                    'failure_code' => $providerPayment->failure?->code,
                    // The provider's own wording, for operators. The
                    // customer is told something else entirely.
                    'failure_message' => $providerPayment->failure?->message === null
                        ? null
                        : mb_substr($providerPayment->failure->message, 0, 500),
                ])->save();
            }

            return $moved;
        });
    }
}

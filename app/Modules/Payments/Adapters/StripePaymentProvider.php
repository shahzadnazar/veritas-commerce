<?php

declare(strict_types=1);

namespace App\Modules\Payments\Adapters;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Data\PreparedPayment;
use App\Modules\Payments\Data\ProviderEvent;
use App\Modules\Payments\Data\ProviderFailure;
use App\Modules\Payments\Data\ProviderPayment;
use App\Modules\Payments\Data\ProviderRefund;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\ProviderSignatureInvalid;
use App\Modules\Payments\Exceptions\ProviderUnavailable;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * The one file in the application that knows Stripe exists.
 *
 * Everything Stripe-shaped stops here: PaymentIntent, Refund, the SDK's
 * exceptions, its status vocabulary, its event names. What leaves this
 * class is the platform's own vocabulary, so the order domain can be read
 * — and replaced — without knowing which company is moving the money.
 *
 * Three things this class is careful about.
 *
 * The status translation is explicit and total. Stripe's strings are an API
 * detail; an unrecognised one is treated as "processing" rather than
 * guessed at, because inventing a terminal state from a string nobody has
 * seen before is how an order gets marked paid on a status that meant the
 * opposite.
 *
 * Failures are split in two. A declined card is an answer and becomes a
 * ProviderFailure on the payment; an unreachable API is the absence of an
 * answer and becomes ProviderUnavailable. §66 turns on the difference: one
 * is recorded against the attempt, the other must leave the order exactly
 * as it was.
 *
 * And nothing here decides anything. It reports what Stripe says. Whether
 * that means an order is paid is settled by code that also knows what the
 * order cost.
 */
final class StripePaymentProvider implements PaymentProvider
{
    public const NAME = 'stripe';

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $webhookSecret,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function preparePayment(
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        array $metadata = [],
    ): PreparedPayment {
        try {
            $intent = $this->stripe->paymentIntents->create([
                'amount' => $amountMinor,
                'currency' => strtolower($currency),
                // Stripe's own idempotency, as the second belt: the
                // platform's unique index is the first, and neither is
                // relied on alone (§5).
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => $metadata,
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (ApiErrorException $e) {
            throw new ProviderUnavailable('Stripe could not prepare the payment.', 0, $e);
        }

        return new PreparedPayment(
            provider: self::NAME,
            reference: (string) $intent->id,
            status: $this->translateIntentStatus((string) $intent->status),
            amountMinor: (int) $intent->amount,
            currency: strtoupper((string) $intent->currency),
            clientSecret: $intent->client_secret === null ? null : (string) $intent->client_secret,
            providerStatus: (string) $intent->status,
        );
    }

    public function retrievePayment(string $reference): ProviderPayment
    {
        try {
            $intent = $this->stripe->paymentIntents->retrieve($reference, []);
        } catch (ApiErrorException $e) {
            throw new ProviderUnavailable("Stripe could not read payment {$reference}.", 0, $e);
        }

        return $this->toProviderPayment($intent);
    }

    public function cancelPayment(string $reference): ProviderPayment
    {
        try {
            $intent = $this->stripe->paymentIntents->cancel($reference, []);
        } catch (ApiErrorException $e) {
            throw new ProviderUnavailable("Stripe could not cancel payment {$reference}.", 0, $e);
        }

        return $this->toProviderPayment($intent);
    }

    public function refundPayment(
        string $paymentReference,
        int $amountMinor,
        string $idempotencyKey,
        string $reason,
    ): ProviderRefund {
        try {
            $refund = $this->stripe->refunds->create([
                'payment_intent' => $paymentReference,
                'amount' => $amountMinor,
                // Stripe's `reason` is an enum of three values; the
                // platform's reason is free text an admin wrote, so it
                // goes in metadata where it cannot be rejected.
                'metadata' => ['veritas_reason' => mb_substr($reason, 0, 490)],
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (ApiErrorException $e) {
            throw new ProviderUnavailable('Stripe could not process the refund.', 0, $e);
        }

        return $this->toProviderRefund($refund);
    }

    public function retrieveRefund(string $reference): ProviderRefund
    {
        try {
            $refund = $this->stripe->refunds->retrieve($reference, []);
        } catch (ApiErrorException $e) {
            throw new ProviderUnavailable("Stripe could not read refund {$reference}.", 0, $e);
        }

        return $this->toProviderRefund($refund);
    }

    public function parseEvent(string $payload, string $signature): ProviderEvent
    {
        try {
            // Stripe's own verifier: HMAC over the raw body with a
            // timestamp tolerance, which is also the replay window. Writing
            // this by hand is a well-known way to get it subtly wrong.
            $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new ProviderSignatureInvalid('The event signature is not valid.', 0, $e);
        }

        // `data.object` is always present on a Stripe event; the SDK
        // types it as non-nullable, so reading it directly is honest.
        $array = $event->data->object->toArray();

        return new ProviderEvent(
            provider: self::NAME,
            eventId: (string) $event->id,
            type: (string) $event->type,
            objectReference: isset($array['id']) ? (string) $array['id'] : null,
            payload: $array,
            occurredAt: (int) $event->created,
        );
    }

    private function toProviderPayment(PaymentIntent $intent): ProviderPayment
    {
        $error = $intent->last_payment_error;

        /** @var array<string, string> $metadata */
        $metadata = [];

        // Metadata is what the platform put on the intent, echoed back.
        // Only scalars are kept: a nested structure here would mean Stripe
        // returned something this code did not write.
        foreach ($intent->metadata->toArray() as $key => $value) {
            if (is_scalar($value)) {
                $metadata[(string) $key] = (string) $value;
            }
        }

        return new ProviderPayment(
            provider: self::NAME,
            reference: (string) $intent->id,
            status: $this->translateIntentStatus((string) $intent->status),
            amountMinor: (int) $intent->amount,
            currency: strtoupper((string) $intent->currency),
            // What was actually captured, which is what an order is
            // verified against — not what was authorised.
            capturedAmountMinor: (int) ($intent->amount_received ?? 0),
            providerStatus: (string) $intent->status,
            chargeReference: $intent->latest_charge === null ? null : (string) $intent->latest_charge,
            failure: $error === null ? null : new ProviderFailure(
                code: isset($error->code) ? (string) $error->code : null,
                declineCode: isset($error->decline_code) ? (string) $error->decline_code : null,
                message: isset($error->message) ? (string) $error->message : null,
                retryable: ($error->type ?? '') === 'card_error',
            ),
            methodDescription: null,
            metadata: $metadata,
        );
    }

    private function toProviderRefund(Refund $refund): ProviderRefund
    {
        return new ProviderRefund(
            provider: self::NAME,
            reference: (string) $refund->id,
            status: $this->translateRefundStatus((string) $refund->status),
            amountMinor: (int) $refund->amount,
            currency: strtoupper((string) $refund->currency),
            providerStatus: (string) $refund->status,
            failure: $refund->failure_reason === null ? null : new ProviderFailure(
                code: (string) $refund->failure_reason,
                message: (string) $refund->failure_reason,
                retryable: false,
            ),
        );
    }

    /**
     * Stripe's PaymentIntent vocabulary, mapped to the platform's.
     *
     * `requires_capture` is deliberately Processing rather than Succeeded:
     * an authorised-but-uncaptured payment is money the platform has not
     * received, and treating it as received is how goods ship against an
     * authorisation that later expires.
     */
    private function translateIntentStatus(string $status): PaymentAttemptStatus
    {
        return match ($status) {
            'requires_payment_method' => PaymentAttemptStatus::RequiresPaymentMethod,
            'requires_action', 'requires_confirmation' => PaymentAttemptStatus::RequiresAction,
            'processing', 'requires_capture' => PaymentAttemptStatus::Processing,
            'succeeded' => PaymentAttemptStatus::Succeeded,
            'canceled' => PaymentAttemptStatus::Cancelled,
            // An unrecognised status is not a licence to guess. Processing
            // is the state that asks the platform to look again rather than
            // to conclude anything.
            default => PaymentAttemptStatus::Processing,
        };
    }

    private function translateRefundStatus(string $status): RefundStatus
    {
        return match ($status) {
            'succeeded' => RefundStatus::Succeeded,
            'failed', 'canceled' => RefundStatus::Failed,
            'pending', 'requires_action' => RefundStatus::Processing,
            default => RefundStatus::Processing,
        };
    }
}

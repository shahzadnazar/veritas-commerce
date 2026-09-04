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
use Illuminate\Support\Str;

/**
 * A provider that behaves like a provider, without a network.
 *
 * §67: the test suite must not depend on Stripe being reachable. A payment
 * domain whose tests need somebody else's staging environment is a payment
 * domain nobody dares change on a Friday, and a flaky external call in CI
 * teaches a team to re-run red builds — which is how a real failure gets
 * ignored.
 *
 * So this is the driver almost every test uses. It holds payments in
 * memory, honours the same idempotency contract, and can be told to
 * decline, to require a further step, or to be unreachable — because the
 * paths that matter most are the ones a happy-path double never exercises.
 *
 * It signs its own events with the same HMAC shape the real adapter
 * verifies, so a test can prove that an unsigned payload is refused
 * without needing a Stripe secret to do it.
 */
final class FakePaymentProvider implements PaymentProvider
{
    public const NAME = 'fake';

    /** @var array<string, ProviderPayment> */
    private array $payments = [];

    /** @var array<string, ProviderRefund> */
    private array $refunds = [];

    /** @var array<string, string> idempotency key => payment reference */
    private array $keys = [];

    private bool $unavailable = false;

    private ?PaymentAttemptStatus $nextStatus = null;

    private ?ProviderFailure $nextFailure = null;

    private RefundStatus $refundStatus = RefundStatus::Succeeded;

    public function __construct(private readonly string $signingSecret = 'fake-signing-secret') {}

    public function name(): string
    {
        return self::NAME;
    }

    /* ---------------------------------------------------------------- *
     * Test controls. Nothing below this point runs in production.
     * ---------------------------------------------------------------- */

    /** The provider stops answering, as providers do. */
    public function goOffline(): void
    {
        $this->unavailable = true;
    }

    public function comeBackOnline(): void
    {
        $this->unavailable = false;
    }

    /** The next prepared payment lands in this state rather than the default. */
    public function willPrepareAs(PaymentAttemptStatus $status): void
    {
        $this->nextStatus = $status;
    }

    public function willFailWith(ProviderFailure $failure): void
    {
        $this->nextFailure = $failure;
    }

    public function refundsResolveAs(RefundStatus $status): void
    {
        $this->refundStatus = $status;
    }

    /**
     * Move a payment to a new state, the way a real provider would after
     * the customer acts in the browser.
     */
    public function settle(
        string $reference,
        PaymentAttemptStatus $status = PaymentAttemptStatus::Succeeded,
        ?int $capturedMinor = null,
        ?ProviderFailure $failure = null,
    ): ProviderPayment {
        $existing = $this->payments[$reference] ?? throw new ProviderUnavailable("No fake payment {$reference}.");

        return $this->payments[$reference] = new ProviderPayment(
            provider: self::NAME,
            reference: $reference,
            status: $status,
            amountMinor: $existing->amountMinor,
            currency: $existing->currency,
            capturedAmountMinor: $status === PaymentAttemptStatus::Succeeded
                ? ($capturedMinor ?? $existing->amountMinor)
                : null,
            providerStatus: $status->value,
            chargeReference: $status === PaymentAttemptStatus::Succeeded ? 'fake_ch_'.Str::lower((string) Str::ulid()) : null,
            failure: $failure,
            methodDescription: $status === PaymentAttemptStatus::Succeeded ? 'Visa ending 4242' : null,
            metadata: $existing->metadata,
        );
    }

    /** Rewrite a payment's amount, to prove verification catches a mismatch. */
    public function tamperAmount(string $reference, int $amountMinor): void
    {
        $p = $this->payments[$reference];

        $this->payments[$reference] = new ProviderPayment(
            provider: $p->provider, reference: $p->reference, status: $p->status,
            amountMinor: $amountMinor, currency: $p->currency,
            capturedAmountMinor: $amountMinor, providerStatus: $p->providerStatus,
            chargeReference: $p->chargeReference, failure: $p->failure,
            methodDescription: $p->methodDescription, metadata: $p->metadata,
        );
    }

    public function tamperCurrency(string $reference, string $currency): void
    {
        $p = $this->payments[$reference];

        $this->payments[$reference] = new ProviderPayment(
            provider: $p->provider, reference: $p->reference, status: $p->status,
            amountMinor: $p->amountMinor, currency: $currency,
            capturedAmountMinor: $p->capturedAmountMinor, providerStatus: $p->providerStatus,
            chargeReference: $p->chargeReference, failure: $p->failure,
            methodDescription: $p->methodDescription, metadata: $p->metadata,
        );
    }

    /**
     * An event, signed the way the real one is.
     *
     * @param  array<string, mixed>  $object
     * @return array{payload: string, signature: string, event: array<string, mixed>}
     */
    public function signedEvent(string $type, array $object, ?string $eventId = null, ?int $occurredAt = null): array
    {
        $event = [
            'id' => $eventId ?? 'fake_evt_'.Str::lower((string) Str::ulid()),
            'type' => $type,
            'created' => $occurredAt ?? time(),
            'data' => ['object' => $object],
        ];

        $payload = (string) json_encode($event);

        return [
            'payload' => $payload,
            'signature' => hash_hmac('sha256', $payload, $this->signingSecret),
            'event' => $event,
        ];
    }

    /**
     * The provider's view of a payment, as an event object.
     *
     * @return array<string, mixed>
     */
    public function paymentObject(string $reference): array
    {
        $p = $this->payments[$reference];

        return [
            'id' => $p->reference,
            'status' => $p->providerStatus ?? $p->status->value,
            'amount' => $p->amountMinor,
            'amount_received' => $p->capturedAmountMinor ?? 0,
            'currency' => strtolower($p->currency),
            'metadata' => $p->metadata,
        ];
    }

    /* ---------------------------------------------------------------- *
     * The contract.
     * ---------------------------------------------------------------- */

    public function preparePayment(
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        array $metadata = [],
    ): PreparedPayment {
        $this->guardAvailable();

        // The same key returns the same payment, exactly as a real
        // provider's idempotency does.
        if (isset($this->keys[$idempotencyKey])) {
            $existing = $this->payments[$this->keys[$idempotencyKey]];

            return new PreparedPayment(
                provider: self::NAME,
                reference: $existing->reference,
                status: $existing->status,
                amountMinor: $existing->amountMinor,
                currency: $existing->currency,
                clientSecret: $existing->reference.'_secret',
                providerStatus: $existing->providerStatus,
            );
        }

        $reference = 'fake_pi_'.Str::lower((string) Str::ulid());
        $status = $this->nextStatus ?? PaymentAttemptStatus::RequiresPaymentMethod;
        $this->nextStatus = null;

        $this->payments[$reference] = new ProviderPayment(
            provider: self::NAME,
            reference: $reference,
            status: $status,
            amountMinor: $amountMinor,
            currency: $currency,
            providerStatus: $status->value,
            metadata: $metadata,
        );

        $this->keys[$idempotencyKey] = $reference;

        return new PreparedPayment(
            provider: self::NAME,
            reference: $reference,
            status: $status,
            amountMinor: $amountMinor,
            currency: $currency,
            clientSecret: $reference.'_secret',
            providerStatus: $status->value,
        );
    }

    public function retrievePayment(string $reference): ProviderPayment
    {
        $this->guardAvailable();

        return $this->payments[$reference]
            ?? throw new ProviderUnavailable("No payment {$reference} at the provider.");
    }

    public function cancelPayment(string $reference): ProviderPayment
    {
        $this->guardAvailable();

        return $this->settle($reference, PaymentAttemptStatus::Cancelled);
    }

    public function refundPayment(
        string $paymentReference,
        int $amountMinor,
        string $idempotencyKey,
        string $reason,
    ): ProviderRefund {
        $this->guardAvailable();

        if (isset($this->keys['refund:'.$idempotencyKey])) {
            return $this->refunds[$this->keys['refund:'.$idempotencyKey]];
        }

        $payment = $this->retrievePayment($paymentReference);
        $reference = 'fake_re_'.Str::lower((string) Str::ulid());

        $refund = new ProviderRefund(
            provider: self::NAME,
            reference: $reference,
            status: $this->refundStatus,
            amountMinor: $amountMinor,
            currency: $payment->currency,
            providerStatus: $this->refundStatus->value,
            failure: $this->refundStatus === RefundStatus::Failed ? $this->nextFailure : null,
        );

        $this->refunds[$reference] = $refund;
        $this->keys['refund:'.$idempotencyKey] = $reference;

        return $refund;
    }

    public function retrieveRefund(string $reference): ProviderRefund
    {
        $this->guardAvailable();

        return $this->refunds[$reference]
            ?? throw new ProviderUnavailable("No refund {$reference} at the provider.");
    }

    public function parseEvent(string $payload, string $signature): ProviderEvent
    {
        // Constant-time, like the real one: a comparison that returns early
        // on the first wrong byte leaks how much of a forged signature was
        // correct.
        if (! hash_equals(hash_hmac('sha256', $payload, $this->signingSecret), $signature)) {
            throw new ProviderSignatureInvalid('Signature does not match the configured secret.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true) ?: [];
        $object = $decoded['data']['object'] ?? [];

        return new ProviderEvent(
            provider: self::NAME,
            eventId: (string) ($decoded['id'] ?? ''),
            type: (string) ($decoded['type'] ?? ''),
            objectReference: is_array($object) && isset($object['id']) ? (string) $object['id'] : null,
            payload: is_array($object) ? $object : [],
            occurredAt: isset($decoded['created']) ? (int) $decoded['created'] : null,
        );
    }

    private function guardAvailable(): void
    {
        if ($this->unavailable) {
            throw new ProviderUnavailable('The payment provider is not reachable.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Data\PreparedPayment;
use App\Modules\Payments\Data\ProviderEvent;
use App\Modules\Payments\Data\ProviderPayment;
use App\Modules\Payments\Data\ProviderRefund;
use App\Modules\Payments\Exceptions\ProviderSignatureInvalid;
use App\Modules\Payments\Exceptions\ProviderUnavailable;

/**
 * The payment port.
 *
 * Everything on the other side of this interface is somebody else's API.
 * Nothing on this side may know that. No method takes or returns a Stripe
 * object, a Stripe status string or a Stripe event name — the adapter
 * translates in both directions, so replacing the provider is a binding
 * change and a new adapter rather than a search through the order domain.
 *
 * The methods are named for what the marketplace wants, not for what a
 * provider calls it: `preparePayment` rather than `createPaymentIntent`,
 * because the next provider will not have PaymentIntents and the domain
 * should not have to care.
 *
 * `retrievePayment` is the load-bearing one. It exists so that no code path
 * ever has to believe a payload it was handed — the platform asks the
 * provider directly what the state of a payment is, over an authenticated
 * connection, and acts on that answer alone.
 */
interface PaymentProvider
{
    /** Which provider this is, as recorded on every row it produces. */
    public function name(): string;

    /**
     * Ask the provider to prepare a payment for an amount the caller has
     * already established from the order.
     *
     * The idempotency key makes a retried call return the same provider
     * payment rather than a second one; the platform's own uniqueness is
     * the other half of that guarantee and does not depend on this.
     *
     * @param  array<string, string>  $metadata  safe references only — no PII
     *
     * @throws ProviderUnavailable
     */
    public function preparePayment(
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        array $metadata = [],
    ): PreparedPayment;

    /**
     * The provider's own current record of a payment.
     *
     * The authority for every "did it succeed" decision in the platform.
     *
     * @throws ProviderUnavailable
     */
    public function retrievePayment(string $reference): ProviderPayment;

    /**
     * Stop a payment that has not been captured.
     *
     * @throws ProviderUnavailable
     */
    public function cancelPayment(string $reference): ProviderPayment;

    /**
     * Return money against a captured payment.
     *
     * @throws ProviderUnavailable
     */
    public function refundPayment(
        string $paymentReference,
        int $amountMinor,
        string $idempotencyKey,
        string $reason,
    ): ProviderRefund;

    /**
     * The provider's own record of a refund.
     *
     * @throws ProviderUnavailable
     */
    public function retrieveRefund(string $reference): ProviderRefund;

    /**
     * Verify an inbound event's signature and translate it.
     *
     * Returning a ProviderEvent is the assertion that this really came from
     * the provider. Anything else throws, and nothing is recorded — an
     * unsigned payload is not an event, it is a stranger's HTTP request.
     *
     * @throws ProviderSignatureInvalid
     */
    public function parseEvent(string $payload, string $signature): ProviderEvent;
}

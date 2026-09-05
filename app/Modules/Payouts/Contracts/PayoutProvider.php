<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Contracts;

use App\Modules\Payouts\Data\ProviderTransfer;

/**
 * The payout port.
 *
 * §15. M7 implements exactly one adapter — ManualPayoutProvider, which
 * records what a person did and calls nothing — but the seam is drawn now,
 * because drawing it later would mean unpicking Stripe Connect out of the
 * settlement action rather than writing an adapter beside it.
 *
 * The rules are the same as the payment port's. No method takes or returns
 * a provider object; no Stripe type, status string or SDK class appears on
 * this side of the interface; the method names say what the marketplace
 * wants ("send a payout") rather than what one provider calls it ("create
 * a transfer to a connected account"). A Connect adapter belongs in
 * infrastructure, translating in both directions.
 *
 * Note what is NOT here: nothing that decides whether a payout may happen.
 * Eligibility, reservations and the ledger debit are the platform's own
 * business and stay in the domain — a provider moves money, it does not
 * authorise it.
 */
interface PayoutProvider
{
    /** Which provider this is, recorded on every attempt it produces. */
    public function name(): string;

    /**
     * Send money to a seller's destination.
     *
     * The idempotency key makes a retried call return the same transfer
     * rather than a second one. The platform's own uniqueness — one
     * successful settlement attempt per payout, by partial unique index —
     * is the other half of that guarantee and does not depend on it.
     *
     * @param  array<string, string>  $metadata  safe references only, no PII
     */
    public function createTransfer(
        string $destinationReference,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        array $metadata = [],
    ): ProviderTransfer;

    /**
     * Ask the provider what actually happened to a transfer.
     *
     * The load-bearing method, for the same reason `retrievePayment` is on
     * the payment port: no code path should have to believe a payload it
     * was handed when it can ask over an authenticated connection instead.
     */
    public function retrieveTransfer(string $reference): ProviderTransfer;

    /**
     * Stop a transfer that has not left yet, where the provider allows it.
     *
     * Returns false when this provider cannot — which is the honest answer
     * for a manual rail, where the money has either gone or was never sent.
     */
    public function cancelTransfer(string $reference): bool;
}

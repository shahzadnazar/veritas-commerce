<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Data;

use App\Support\Money;

/**
 * One seller's money, in the five states it can be in, plus what that
 * means for a withdrawal right now.
 *
 * Every figure is a signed count of minor units in ONE currency. Signed,
 * because a seller's position can genuinely be below zero once a refund
 * lands behind a payout that has already left the building (§42), and a
 * type that refused to represent that would force the one number that
 * matters to be rounded up to nothing.
 *
 * The vocabulary, precisely — these words are used with these meanings
 * everywhere in the codebase and on every screen:
 *
 * PENDING     Payment verified, delivery requirement not yet met. The
 *             money exists as an obligation; nothing has started clearing.
 *
 * CLEARING    The seller order was delivered and the clearing period is
 *             running. Earned, not yet spendable.
 *
 * AVAILABLE   Money that has finished clearing, NET OF PAYOUTS ALREADY
 *             SETTLED. This is the subtle one: an earning of $500 that has
 *             been paid out leaves $0 available, not $500, because the
 *             payout debit sits in the same pool. Summing only entries in
 *             the `available` status would show sellers money they have
 *             already been sent.
 *
 * RESERVED    Available money an open payout request is holding. Comes
 *             from `payout_allocations`, never from the ledger — a hold is
 *             a claim on money, not a movement of it.
 *
 * PAID OUT    Lifetime total actually settled to the seller, as a positive
 *             figure. Reporting only; it is already inside AVAILABLE as a
 *             negative, and subtracting it again would double-count.
 *
 * NET BALANCE Everything the platform owes this seller: pending +
 *             clearing + available. The platform's liability.
 *
 * RAW PAYOUT CAPACITY
 *             min(available, net balance) - reserved. SIGNED: below zero
 *             it says how far short the seller is.
 *
 * WITHDRAWABLE What the seller may ask for right now: max(0, raw payout
 *             capacity). NEVER NEGATIVE — a position may be below zero,
 *             but the amount that may be withdrawn from it is nothing
 *             rather than a negative number.
 */
final readonly class SellerFinancialPosition
{
    public function __construct(
        public string $currency,
        public int $pendingMinor,
        public int $clearingMinor,
        /** Net of settled payouts — see AVAILABLE above. */
        public int $availableMinor,
        /** Always >= 0: an allocation cannot hold a negative amount. */
        public int $reservedMinor,
        /** Lifetime settled, as a positive figure. */
        public int $paidOutMinor,
    ) {}

    /** The platform's total liability to this seller. */
    public function netBalanceMinor(): int
    {
        return $this->pendingMinor + $this->clearingMinor + $this->availableMinor;
    }

    /**
     * How much payout capacity there is, SIGNED.
     *
     *   min(available, net balance) - reserved
     *
     * The cap on available is the §48 rule. A seller whose OVERALL
     * position is negative may not withdraw, even if some individual
     * bucket looks healthy — a refund sitting against money that is still
     * clearing is money the platform is about to be owed back, and paying
     * out against it turns a bookkeeping entry into a debt collection
     * problem. In the ordinary case, where pending and clearing are
     * positive, the cap does nothing and this is plainly
     * available - reserved.
     *
     * Reserved is subtracted exactly once, here. It is deliberately NOT
     * also a ledger entry: that double subtraction, on settlement, is the
     * specific bug §29 exists to prevent.
     *
     * This figure CAN be negative, and that is the point of it existing
     * separately from `withdrawableMinor()`. Below zero it says how far
     * short the seller is — how much has to arrive before a withdrawal is
     * possible again — which is exactly what an operator answering "when
     * can I withdraw" needs and what a screen showing a clamped zero
     * cannot tell them.
     */
    public function rawPayoutCapacityMinor(): int
    {
        return min($this->availableMinor, $this->netBalanceMinor()) - $this->reservedMinor;
    }

    /**
     * What the seller may request right now. NEVER NEGATIVE.
     *
     *   max(0, raw payout capacity)
     *
     * The clamp is a definition rather than a safety net. "Withdrawable"
     * answers one question — how much may be asked for — and the only
     * honest answers are a positive amount or nothing. A negative
     * withdrawable balance is a category error: it invites a screen to
     * print "-$39.60 available to withdraw", and it invites arithmetic
     * like `min($requested, $withdrawable)` to produce a negative payout.
     *
     * A seller's POSITION may legitimately be below zero (§42), and it is
     * still reported as such by `netBalanceMinor()` and
     * `rawPayoutCapacityMinor()`. What may be withdrawn from a position
     * below zero is nothing, and this says nothing rather than saying a
     * negative amount.
     */
    public function withdrawableMinor(): int
    {
        return max(0, $this->rawPayoutCapacityMinor());
    }

    /** Whether any withdrawal at all is arithmetically possible. */
    public function hasWithdrawableFunds(): bool
    {
        return $this->withdrawableMinor() > 0;
    }

    /**
     * Whether the seller is short — the capacity is below zero.
     *
     * Distinct from `isNegative()`, which asks about the platform's
     * liability. A store can owe nothing overall and still be short: a
     * refund landing while a payout is open leaves the hold standing
     * against money that is no longer there.
     */
    public function isShort(): bool
    {
        return $this->rawPayoutCapacityMinor() < 0;
    }

    public function isNegative(): bool
    {
        return $this->netBalanceMinor() < 0;
    }

    /** A non-negative figure is safe to wrap; the signed ones are not. */
    public function reserved(): Money
    {
        return Money::of($this->reservedMinor, $this->currency);
    }

    public function paidOut(): Money
    {
        return Money::of($this->paidOutMinor, $this->currency);
    }

    /**
     * The shape React reads. Every amount is minor units plus the
     * currency, and every formatted string was formatted here — the
     * browser never does money arithmetic (§80).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'pendingMinor' => $this->pendingMinor,
            'clearingMinor' => $this->clearingMinor,
            'availableMinor' => $this->availableMinor,
            'reservedMinor' => $this->reservedMinor,
            'paidOutMinor' => $this->paidOutMinor,
            'netBalanceMinor' => $this->netBalanceMinor(),
            'rawPayoutCapacityMinor' => $this->rawPayoutCapacityMinor(),
            'withdrawableMinor' => $this->withdrawableMinor(),
            'pending' => Money::formatSigned($this->pendingMinor, $this->currency),
            'clearing' => Money::formatSigned($this->clearingMinor, $this->currency),
            'available' => Money::formatSigned($this->availableMinor, $this->currency),
            'reserved' => Money::formatSigned($this->reservedMinor, $this->currency),
            'paidOut' => Money::formatSigned($this->paidOutMinor, $this->currency),
            'netBalance' => Money::formatSigned($this->netBalanceMinor(), $this->currency),
            'rawPayoutCapacity' => Money::formatSigned($this->rawPayoutCapacityMinor(), $this->currency),
            // Never negative, so the plain formatter is correct here and
            // a screen can print it beside "available to withdraw"
            // without a minus sign ever appearing.
            'withdrawable' => Money::of($this->withdrawableMinor(), $this->currency)->format(),
            'isNegative' => $this->isNegative(),
            'isShort' => $this->isShort(),
        ];
    }
}

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
 * WITHDRAWABLE What the seller may ask for right now. See the formula on
 *             the property itself — it is not simply available - reserved.
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
     * What the seller may request right now.
     *
     * Two subtractions and a cap:
     *
     *   min(available, net balance) - reserved
     *
     * The cap is §48. A seller whose OVERALL position is negative may not
     * withdraw, even if some individual bucket looks healthy — a refund
     * sitting against money that is still clearing is money the platform
     * is about to be owed back, and paying out against it turns a
     * bookkeeping entry into a debt collection problem. In the ordinary
     * case, where pending and clearing are positive, the cap does nothing
     * and this is plainly available - reserved.
     *
     * Reserved is subtracted exactly once, here. It is deliberately NOT
     * also a ledger entry: that double subtraction, on settlement, is the
     * specific bug §29 exists to prevent.
     */
    public function withdrawableMinor(): int
    {
        return min($this->availableMinor, $this->netBalanceMinor()) - $this->reservedMinor;
    }

    /** Whether any withdrawal at all is arithmetically possible. */
    public function hasWithdrawableFunds(): bool
    {
        return $this->withdrawableMinor() > 0;
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
            'withdrawableMinor' => $this->withdrawableMinor(),
            'pending' => Money::formatSigned($this->pendingMinor, $this->currency),
            'clearing' => Money::formatSigned($this->clearingMinor, $this->currency),
            'available' => Money::formatSigned($this->availableMinor, $this->currency),
            'reserved' => Money::formatSigned($this->reservedMinor, $this->currency),
            'paidOut' => Money::formatSigned($this->paidOutMinor, $this->currency),
            'netBalance' => Money::formatSigned($this->netBalanceMinor(), $this->currency),
            'withdrawable' => Money::formatSigned($this->withdrawableMinor(), $this->currency),
            'isNegative' => $this->isNegative(),
        ];
    }
}

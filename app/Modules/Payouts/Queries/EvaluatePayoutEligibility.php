<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Queries;

use App\Modules\Payouts\Data\PayoutEligibility;
use App\Modules\Payouts\Data\SellerFinancialPosition;
use App\Modules\Payouts\Enums\PayoutIneligibility;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Support\PayoutPolicy;
use App\Modules\Sellers\Models\SellerAccount;

/**
 * The one place that decides whether a payout may be requested.
 *
 * Used twice, deliberately: once by the screen, to show the seller a
 * button or an explanation, and once inside RequestPayout's transaction,
 * to decide. Sharing it is what stops the two from disagreeing — a screen
 * that computes eligibility differently from the action either offers a
 * button that fails or hides one that would have worked.
 *
 * Checks run worst-first (§18). A suspended seller who is also below the
 * minimum is told about the suspension, because that is the fact that
 * actually needs dealing with.
 */
final class EvaluatePayoutEligibility
{
    public function __construct(private readonly GetSellerFinancialPosition $position) {}

    /**
     * @param  bool  $actorMayRequest  whether the person asking holds payouts.request
     * @param  SellerFinancialPosition|null  $position  a position already read under a lock
     */
    public function __invoke(
        SellerAccount $seller,
        string $currency,
        bool $actorMayRequest = true,
        ?SellerFinancialPosition $position = null,
    ): PayoutEligibility {
        $currency = strtoupper($currency);
        $position ??= ($this->position)($seller->id, $currency);

        $minimum = PayoutPolicy::minimumMinor();
        $withdrawable = $position->withdrawableMinor();

        $refuse = static fn (PayoutIneligibility $reason, ?string $openReference = null): PayoutEligibility => PayoutEligibility::refused($reason, $withdrawable, $minimum, $currency, $openReference);

        // A suspended or closed store keeps every read — §19 is explicit
        // that finance history is not hidden — and loses this one write.
        if (! $seller->status->canRequestPayout()) {
            return $refuse(PayoutIneligibility::SellerNotEligible);
        }

        if (! $actorMayRequest) {
            return $refuse(PayoutIneligibility::PermissionRequired);
        }

        if (! PayoutPolicy::supports($currency)) {
            return $refuse(PayoutIneligibility::CurrencyNotSupported);
        }

        $open = $this->openRequestFor($seller->id);

        if ($open !== null) {
            return $refuse(PayoutIneligibility::OpenPayoutExists, $open->reference);
        }

        if (PayoutPolicy::requiresDestination() && ! $this->hasDestination($seller->id, $currency)) {
            return $refuse(PayoutIneligibility::PayoutAccountRequired);
        }

        /*
         * Negative before empty. "You have nothing available" and "you owe
         * the platform money after a refund" are different situations and
         * a seller who is told the first when the second is true will
         * spend a week waiting for a balance that is not coming.
         */
        if ($position->isNegative()) {
            return $refuse(PayoutIneligibility::NegativeBalance);
        }

        if ($withdrawable <= 0) {
            return $refuse(PayoutIneligibility::NoAvailableBalance);
        }

        if ($withdrawable < $minimum) {
            return $refuse(PayoutIneligibility::BelowMinimum);
        }

        return PayoutEligibility::allowed($withdrawable, $minimum, $currency);
    }

    /** The seller's one open request, if they have one. */
    public function openRequestFor(int $sellerAccountId): ?PayoutRequest
    {
        /** @var PayoutRequest|null $open */
        $open = PayoutRequest::query()
            ->withoutGlobalScopes()
            ->where('seller_account_id', $sellerAccountId)
            ->whereIn('status', PayoutStatus::openValues())
            ->orderByDesc('id')
            ->first();

        return $open;
    }

    private function hasDestination(int $sellerAccountId, string $currency): bool
    {
        return PayoutAccount::query()
            ->withoutGlobalScopes()
            ->where('seller_account_id', $sellerAccountId)
            ->where('status', PayoutAccount::STATUS_ACTIVE)
            ->where('currency', $currency)
            ->exists();
    }
}

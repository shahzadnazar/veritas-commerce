<?php

declare(strict_types=1);

namespace App\Modules\Commission\Actions;

use App\Modules\Commission\Data\CommissionSnapshot;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The one place in the codebase that computes a commission.
 *
 * Everywhere else reads the snapshot columns written here. Nothing anywhere
 * multiplies a stored total by a current rate — that is the single mistake
 * that would make every historical figure in the marketplace wrong the next
 * time the rate changes.
 */
final class BuildCommissionSnapshot
{
    public function __construct(private readonly ResolveCommissionRule $resolveRule) {}

    public function __invoke(
        Money $lineTotal,
        ?int $sellerAccountId = null,
        ?int $categoryId = null,
        ?string $campaignCode = null,
        ?Carbon $at = null,
    ): CommissionSnapshot {
        $at ??= Carbon::now();
        $rule = ($this->resolveRule)($sellerAccountId, $categoryId, $campaignCode, $at);

        // The earning is the remainder, never computed independently, so
        // commission + earning always equals the line total exactly.
        [$commission, $earning] = $lineTotal->splitPercentage($rule->ratePercent());

        return new CommissionSnapshot(
            ratePercent: $rule->ratePercent(),
            ruleId: $rule->id,
            scope: $rule->scope,
            commission: $commission,
            sellerEarning: $earning,
            snapshottedAt: $at,
        );
    }
}

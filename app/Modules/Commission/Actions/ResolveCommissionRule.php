<?php

declare(strict_types=1);

namespace App\Modules\Commission\Actions;

use App\Modules\Commission\Models\CommissionRule;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Finds the commission rule in force for a seller and category at a moment.
 *
 * Phase 1 only ever has a global rule, but resolution already walks the
 * precedence ladder most-specific-first, so adding a category or seller
 * rate later is inserting a row rather than changing this code.
 *
 *   campaign > seller+category > seller > category > global
 */
final class ResolveCommissionRule
{
    public function __invoke(
        ?int $sellerAccountId = null,
        ?int $categoryId = null,
        ?string $campaignCode = null,
        ?Carbon $at = null,
    ): CommissionRule {
        $at ??= now();

        $candidates = CommissionRule::query()
            ->where('effective_from', '<=', $at)
            ->where(function ($query) use ($at): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $at);
            })
            ->where(function ($query) use ($sellerAccountId, $categoryId, $campaignCode): void {
                $query
                    ->where('scope', 'global')
                    ->orWhere(fn ($q) => $q->where('scope', 'category')->where('category_id', $categoryId))
                    ->orWhere(fn ($q) => $q->where('scope', 'seller')->where('seller_account_id', $sellerAccountId))
                    ->orWhere(fn ($q) => $q->where('scope', 'seller_category')
                        ->where('seller_account_id', $sellerAccountId)
                        ->where('category_id', $categoryId))
                    ->orWhere(fn ($q) => $q->where('scope', 'campaign')->where('campaign_code', $campaignCode));
            })
            ->get();

        if ($candidates->isEmpty()) {
            throw new RuntimeException('No commission rule is in force. The platform default must be seeded.');
        }

        // Highest precedence wins; the most recently effective breaks a tie.
        return $candidates
            ->sortByDesc(fn (CommissionRule $rule): array => [
                $rule->scope->precedence(),
                $rule->effective_from->getTimestamp(),
            ])
            ->first();
    }
}

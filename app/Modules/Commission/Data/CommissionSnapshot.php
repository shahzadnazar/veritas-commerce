<?php

declare(strict_types=1);

namespace App\Modules\Commission\Data;

use App\Modules\Commission\Enums\CommissionScope;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The four values written onto an order item and never recalculated:
 * the rate, the rule that produced it, the platform's cut and the seller's
 * earning — plus when it was frozen, so the UI can label it.
 */
final readonly class CommissionSnapshot
{
    public function __construct(
        public string $ratePercent,
        public int $ruleId,
        public CommissionScope $scope,
        public Money $commission,
        public Money $sellerEarning,
        public Carbon $snapshottedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toOrderItemColumns(): array
    {
        return [
            'commission_rate_snapshot' => $this->ratePercent,
            'commission_rule_id' => $this->ruleId,
            'commission_scope_snapshot' => $this->scope->value,
            'commission_amount_minor' => $this->commission->minor,
            'seller_earning_amount_minor' => $this->sellerEarning->minor,
            'snapshotted_at' => $this->snapshottedAt,
        ];
    }
}

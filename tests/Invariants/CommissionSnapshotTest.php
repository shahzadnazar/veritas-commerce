<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Commission\Actions\BuildCommissionSnapshot;
use App\Modules\Commission\Enums\CommissionScope;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Invariant 2 — changing commission configuration never changes a
 * historical order item.
 *
 * This is the rule the whole marketplace's auditability rests on. If it
 * fails, every revenue figure, seller statement and past order silently
 * becomes wrong the next time the rate moves.
 */
final class CommissionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function changing_the_platform_rate_does_not_alter_a_historical_order_item(): void
    {
        CommissionRule::factory()->rate('12.00')->create();
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();

        $sellerOrder = SellerOrder::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
        ]);

        $snapshot = app(BuildCommissionSnapshot::class)(
            lineTotal: Money::of(32_800),
            sellerAccountId: $seller->id,
        );

        $item = OrderItem::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'line_total_minor' => 32_800,
            ...$snapshot->toOrderItemColumns(),
        ]);

        $this->assertSame('12.00', (string) $item->commission_rate_snapshot);
        $this->assertSame(3_936, $item->commission_amount_minor);
        $this->assertSame(28_864, $item->seller_earning_amount_minor);

        // The platform raises the rate to 20% from today.
        CommissionRule::create([
            'scope' => CommissionScope::Global->value,
            'rate_percent' => '20.00',
            'effective_from' => now(),
            'created_at' => now(),
        ]);

        $item->refresh();

        $this->assertSame('12.00', (string) $item->commission_rate_snapshot, 'The stored rate must not move.');
        $this->assertSame(3_936, $item->commission_amount_minor, 'The stored commission must not move.');
        $this->assertSame(28_864, $item->seller_earning_amount_minor, 'The stored earning must not move.');
    }

    #[Test]
    public function a_new_order_uses_the_new_rate_while_the_old_one_keeps_its_own(): void
    {
        CommissionRule::factory()->rate('12.00')->create();
        ['seller' => $seller] = $this->makeSeller();

        $old = app(BuildCommissionSnapshot::class)(Money::of(10_000), $seller->id);
        $this->assertSame(1_200, $old->commission->minor);

        CommissionRule::create([
            'scope' => CommissionScope::Global->value,
            'rate_percent' => '20.00',
            'effective_from' => now()->subMinute(),
            'created_at' => now(),
        ]);

        $new = app(BuildCommissionSnapshot::class)(Money::of(10_000), $seller->id);

        $this->assertSame(2_000, $new->commission->minor, 'A new order takes the new rate.');
        $this->assertSame(1_200, $old->commission->minor, 'The earlier snapshot is untouched.');
    }

    #[Test]
    public function snapshot_columns_cannot_be_updated_through_the_model(): void
    {
        CommissionRule::factory()->create();
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();

        $item = OrderItem::factory()->create([
            'seller_order_id' => SellerOrder::factory()->create([
                'seller_account_id' => $seller->id,
                'store_id' => $store->id,
            ])->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('financial snapshot');

        $item->update(['commission_amount_minor' => 1]);
    }

    #[Test]
    public function commission_plus_earning_always_equals_the_line_total(): void
    {
        CommissionRule::factory()->rate('12.00')->create();

        // Amounts chosen to land on rounding boundaries.
        foreach ([1, 7, 99, 101, 333, 1_000, 32_800, 99_999, 1_000_003] as $total) {
            $snapshot = app(BuildCommissionSnapshot::class)(Money::of($total));

            $this->assertSame(
                $total,
                $snapshot->commission->minor + $snapshot->sellerEarning->minor,
                "Split of {$total} minor units must sum back exactly.",
            );
        }
    }

    #[Test]
    public function a_seller_specific_rule_outranks_the_global_one(): void
    {
        CommissionRule::factory()->rate('12.00')->create();
        ['seller' => $seller] = $this->makeSeller();

        CommissionRule::factory()
            ->rate('8.00')
            ->scoped(CommissionScope::Seller, sellerAccountId: $seller->id)
            ->create();

        $forSeller = app(BuildCommissionSnapshot::class)(Money::of(10_000), $seller->id);
        $forAnyone = app(BuildCommissionSnapshot::class)(Money::of(10_000));

        $this->assertSame('8.00', $forSeller->ratePercent);
        $this->assertSame(CommissionScope::Seller, $forSeller->scope);
        $this->assertSame('12.00', $forAnyone->ratePercent, 'Other sellers still take the global rate.');
    }

    #[Test]
    public function commission_rules_are_append_only(): void
    {
        $rule = CommissionRule::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $rule->update(['rate_percent' => '99.00']);
    }
}

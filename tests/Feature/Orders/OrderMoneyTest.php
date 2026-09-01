<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * §28: the arithmetic, at order level, on prices that do not divide.
 *
 * Money is integers throughout, so there is no float to blame — but a
 * percentage of a prime number of pennies still has to land somewhere, and
 * where it lands has to be decided once and be the same every time. The
 * policy is: commission is rounded half-up, the seller's earning is the
 * REMAINDER, and the two therefore sum to the line exactly by
 * construction rather than by luck.
 *
 * Everything here goes through a real checkout, because the claim is about
 * placed orders and not about a helper.
 */
final class OrderMoneyTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function awkwardPrices(): iterable
    {
        // 12% of each of these is a fraction of a penny.
        yield 'one penny' => [1, 1, 0];
        yield 'three pennies' => [3, 1, 0];
        yield 'a prime' => [9_973, 1, 1_197];
        yield 'rounds up at exactly half' => [1_250, 1, 150];
        yield 'quantity multiplies before the split' => [3_333, 3, 1_200];
        yield 'large' => [99_999, 7, 83_999];
    }

    #[Test]
    #[DataProvider('awkwardPrices')]
    public function the_split_is_deterministic_and_exact(int $unit, int $quantity, int $expectedCommission): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: $unit, stock: 20);

        $order = $this->placeOrder([[$offer, $quantity]]);

        /** @var OrderItem $item */
        $item = OrderItem::query()->firstOrFail();

        $this->assertSame($unit * $quantity, $item->line_total_minor);
        $this->assertSame($expectedCommission, $item->commission_amount_minor);

        // The earning is never computed independently — it is what is
        // left, which is why these always sum.
        $this->assertSame(
            $item->line_total_minor,
            $item->commission_amount_minor + $item->seller_earning_amount_minor,
        );

        $this->assertSame($order->grand_total_minor, $item->line_total_minor);
    }

    #[Test]
    public function the_split_on_the_same_price_is_the_same_every_time(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 9_973, stock: 30);

        $first = $this->placeOrder([$offer]);
        $second = $this->placeOrder([$offer]);

        $commissions = OrderItem::query()->pluck('commission_amount_minor')->unique();

        $this->assertCount(1, $commissions, 'The same price must not split two different ways.');
        $this->assertSame($first->grand_total_minor, $second->grand_total_minor);
    }

    #[Test]
    public function a_multi_seller_order_reconciles_exactly_on_awkward_prices(): void
    {
        config()->set('veritas.checkout.shipping_per_seller_order_minor', 337);

        ['offer' => $a] = $this->sellableOffer('Kettle', priceMinor: 9_973);
        ['offer' => $b] = $this->sellableOffer('Lamp', priceMinor: 1);
        ['offer' => $c] = $this->sellableOffer('Rug', priceMinor: 3_333);

        $order = $this->placeOrder([[$a, 3], [$b, 7], [$c, 1]]);

        $children = SellerOrder::query()->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)->get();
        $items = OrderItem::query()->get();

        // Parent equals the sum of the children, in minor units, with no
        // rounding step anywhere in between.
        $this->assertSame((int) $children->sum('items_total_minor'), $order->items_total_minor);
        $this->assertSame((int) $children->sum('shipping_total_minor'), $order->shipping_total_minor);
        $this->assertSame((int) $children->sum('order_total_minor'), $order->grand_total_minor);
        $this->assertSame(3 * 337, $order->shipping_total_minor);

        // And each child equals the sum of its own items.
        foreach ($children as $child) {
            $childItems = $items->where('seller_order_id', $child->id);

            $this->assertSame((int) $childItems->sum('line_total_minor'), $child->items_total_minor);
            $this->assertSame(
                (int) $childItems->sum('commission_amount_minor'),
                $child->commission_total_minor,
            );
            $this->assertSame(
                $child->items_total_minor,
                $child->commission_total_minor + $child->seller_earning_total_minor,
            );
        }
    }

    #[Test]
    public function reconciliation_is_the_databases_rule_not_the_applications(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 9_973);
        $order = $this->placeOrder([$offer]);

        // Every identity is a CHECK, so no code path anywhere — a job, a
        // console command, a future refactor — can write a set of totals
        // that do not add up.
        $this->expectExceptionMessage('marketplace_orders_total_is_exact');

        DB::table('marketplace_orders')->where('id', $order->id)->update(['grand_total_minor' => 1]);
    }

    #[Test]
    public function every_order_is_written_in_one_currency(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');

        $order = $this->placeOrder([$a, $b]);

        $this->assertSame('USD', $order->currency);
        $this->assertSame(
            [$order->currency],
            SellerOrder::query()->withoutGlobalScopes()->pluck('currency')->unique()->all(),
        );
        $this->assertSame([$order->currency], OrderItem::query()->pluck('currency')->unique()->all());
    }

    #[Test]
    public function no_order_total_is_ever_negative(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 1);
        $this->placeOrder([$offer]);

        $this->expectExceptionMessage('marketplace_orders_money_is_not_negative');

        DB::table('marketplace_orders')->update([
            'items_total_minor' => -1,
            'grand_total_minor' => -1,
        ]);
    }

    #[Test]
    public function the_reference_of_every_order_is_unique(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 40);

        $references = [];

        for ($i = 0; $i < 5; $i++) {
            $references[] = $this->placeOrder([$offer])->reference;
        }

        // Gapless and dense, from the sequence table rather than the
        // identity column — a customer quoting VC-24081 should not be
        // quoting a number with holes in it.
        $this->assertSame($references, array_unique($references));
        $this->assertSame(5, MarketplaceOrder::query()->count());
        $this->assertSame(
            5,
            SellerOrder::query()->withoutGlobalScopes()->distinct()->count('reference'),
        );
    }
}

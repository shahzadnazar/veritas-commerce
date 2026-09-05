<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Ledger\Actions\ReleaseClearedEarnings;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Ledger\Queries\SellerBalance;
use App\Modules\Orders\Actions\CompleteDeliveredSellerOrders;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Actions\RequestRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * When a seller's money becomes theirs to spend.
 *
 * The sequence is the whole point: payment records it, delivery starts a
 * clock, and only the clock makes it available. Every shortcut through
 * that sequence is a way to pay a seller for goods the customer never got
 * or has already had their money back for.
 *
 * Nothing here recalculates an amount. Every figure comes from the entries
 * M5 wrote at payment, from the purchase snapshot.
 */
final class EarningsClearingTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    #[Test]
    public function payment_alone_leaves_the_earning_pending_forever(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $entry = SellerLedgerEntry::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(LedgerEntryStatus::Pending, $entry->status);
        $this->assertNull($entry->available_at);

        // Running the sweep changes nothing: there is no clock to expire.
        $this->travel(90)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(LedgerEntryStatus::Pending, $entry->refresh()->status);
        $this->assertSame(0, app(SellerBalance::class)($seller->id)['available']->minor);
    }

    #[Test]
    public function delivery_moves_the_earning_to_clearing_with_the_configured_period(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $entry = SellerLedgerEntry::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(LedgerEntryStatus::Clearing, $entry->status);
        $this->assertNotNull($entry->available_at);

        // Seven days from delivery, from the platform default.
        $this->assertSame(7, (int) config('veritas.payouts.seller_clearing_period_days'));
        $this->assertEquals(
            $sellerOrder->refresh()->delivered_at?->copy()->addDays(7)->timestamp,
            $entry->available_at->timestamp,
        );

        // Clearing is not available: the balance says so.
        $balance = app(SellerBalance::class)($seller->id);

        $this->assertSame(8_800, $balance['clearing']->minor);
        $this->assertSame(0, $balance['available']->minor);
    }

    #[Test]
    public function a_seller_override_replaces_the_platform_period(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 5);

        // A seller the platform has agreed different terms with.
        $seller->forceFill(['clearing_period_days' => 2])->save();

        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $entry = SellerLedgerEntry::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertEquals(
            $sellerOrder->refresh()->delivered_at?->copy()->addDays(2)->timestamp,
            $entry->available_at?->timestamp,
        );
    }

    #[Test]
    public function the_earning_is_not_available_before_its_date(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);
        $this->deliver($this->shipEverything($this->sellerOrderFor($order->id)));

        // A day short of the deadline.
        $this->travel(6)->days();

        $this->assertSame(0, app(ReleaseClearedEarnings::class)());
        $this->assertSame(0, app(SellerBalance::class)($seller->id)['available']->minor);
        $this->assertSame(8_800, app(SellerBalance::class)($seller->id)['clearing']->minor);
    }

    #[Test]
    public function the_sweep_releases_the_money_once_the_period_has_passed_and_is_idempotent(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $entriesBefore = SellerLedgerEntry::query()->withoutGlobalScopes()->count();

        $this->travel(8)->days();

        $first = app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(1, $first['released']);
        $this->assertSame(1, $first['completed']);

        $balance = app(SellerBalance::class)($seller->id);

        $this->assertSame(8_800, $balance['available']->minor);
        $this->assertSame(0, $balance['clearing']->minor);
        $this->assertSame(SellerOrderStatus::Completed, $sellerOrder->refresh()->status);

        // Again, and again. No new rows, no second release, no doubling.
        foreach (range(1, 3) as $_) {
            $repeat = app(CompleteDeliveredSellerOrders::class)();

            $this->assertSame(0, $repeat['released']);
            $this->assertSame(0, $repeat['completed']);
        }

        $this->assertSame($entriesBefore, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(8_800, app(SellerBalance::class)($seller->id)['available']->minor);
    }

    #[Test]
    public function the_amount_released_is_the_snapshot_and_not_a_current_rate(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        $this->assertSame('12.00', $item->commission_rate_snapshot);
        $this->assertSame(8_800, $item->seller_earning_amount_minor);

        // Everything a naive implementation might read again, changed.
        CommissionRule::query()->update(['rate_percent' => '40.00']);
        $offer->forceFill(['price_minor' => 1])->save();

        $this->deliver($this->shipEverything($this->sellerOrderFor($order->id)));
        $this->travel(8)->days();

        app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(
            8_800,
            app(SellerBalance::class)($seller->id)['available']->minor,
            'The seller is paid what was agreed at purchase, months later.',
        );
    }

    #[Test]
    public function a_partial_refund_during_clearing_reduces_what_becomes_available(): void
    {
        ['offer' => $a] = $this->sellableOffer(title: 'Kettle', priceMinor: 10_000, stock: 5);
        ['offer' => $b, 'seller' => $seller] = $this->sellableOffer(
            title: 'Grinder',
            priceMinor: 5_000,
            stock: 5,
            seller: null,
        );

        // One seller, two lines, so a refund of one leaves the other.
        $order = $this->placeOrder([[$a, 1]]);
        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $sellerAccountId = (int) $sellerOrder->seller_account_id;
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();

        $this->assertSame(8_800, app(SellerBalance::class)($sellerAccountId)['clearing']->minor);

        // $2,000 of a $10,000 line comes back while it is clearing.
        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 2_000, 'quantity' => 0]],
            reason: 'A dent the customer accepted a discount for.',
        );

        // 12% of 2,000 is 240, so the seller gives back 1,760.
        $this->assertSame(7_040, app(SellerBalance::class)($sellerAccountId)['clearing']->minor);

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(
            7_040,
            app(SellerBalance::class)($sellerAccountId)['available']->minor,
            'The reversal cleared alongside what it cancels.',
        );

        // History is intact: the original stands, the reversal beside it.
        $entries = SellerLedgerEntry::query()->withoutGlobalScopes()->orderBy('id')->get();

        $original = $entries->firstOrFail();
        $reversal = $entries->skip(1)->firstOrFail();

        $this->assertSame(8_800, $original->amount_minor);
        $this->assertSame(-1_760, $reversal->amount_minor);
        $this->assertSame(LedgerEntryType::RefundReversal, $reversal->type);
        $this->assertSame($original->id, $reversal->reverses_entry_id);
    }

    #[Test]
    public function a_full_refund_during_clearing_leaves_nothing_to_spend(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $sellerAccountId = (int) $sellerOrder->seller_account_id;
        $item = OrderItem::query()->firstOrFail();

        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 10_000, 'quantity' => 1]],
            reason: 'Returned in full after delivery.',
        );

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $balance = app(SellerBalance::class)($sellerAccountId);

        $this->assertSame(0, $balance['available']->minor);
        $this->assertSame(0, $balance['clearing']->minor);

        // The original earning was never a spendable balance, and it was
        // never deleted either.
        $this->assertSame(
            0,
            app(SellerBalance::class)->netMinor($sellerAccountId, LedgerEntryStatus::Available),
        );
        $this->assertSame(2, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_refund_after_the_money_became_available_reduces_the_available_balance(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $sellerAccountId = (int) $sellerOrder->seller_account_id;

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(8_800, app(SellerBalance::class)($sellerAccountId)['available']->minor);

        // §36: the refund lands after the money was released. M7 will pay
        // out against this number, so it has to move.
        $item = OrderItem::query()->firstOrFail();

        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 2_000, 'quantity' => 0]],
            reason: 'Late complaint, resolved with a partial refund.',
        );

        $this->assertSame(
            7_040,
            app(SellerBalance::class)($sellerAccountId)['available']->minor,
            'Available means available now, after everything that has happened.',
        );

        // And the original entry was neither mutated nor removed.
        $original = SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('type', LedgerEntryType::SaleEarning->value)
            ->firstOrFail();

        $this->assertSame(8_800, $original->amount_minor);
        $this->assertSame(LedgerEntryStatus::Available, $original->status);
    }

    #[Test]
    public function each_seller_clears_on_its_own_delivery_date(): void
    {
        ['offer' => $a, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 10_000, stock: 5);
        ['offer' => $b, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 5_000, stock: 5);

        $order = $this->placeOrder([[$a, 1], [$b, 1]]);
        $this->payFor($order);

        $orderA = $this->sellerOrderFor($order->id, $sellerA->id);
        $orderB = $this->sellerOrderFor($order->id, $sellerB->id);

        // A delivers today; B two days later.
        $this->deliver($this->shipEverything($orderA));
        $this->travel(2)->days();
        $this->deliver($this->shipEverything($orderB));

        $clearA = $orderA->refresh()->earnings_clear_at;
        $clearB = $orderB->refresh()->earnings_clear_at;

        $this->assertNotNull($clearA);
        $this->assertNotNull($clearB);
        $this->assertTrue($clearB->greaterThan($clearA), 'Two sellers, two clocks.');

        // Six days on: A has cleared, B has not.
        $this->travel(6)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(8_800, app(SellerBalance::class)($sellerA->id)['available']->minor);
        $this->assertSame(0, app(SellerBalance::class)($sellerB->id)['available']->minor);
        $this->assertSame(4_400, app(SellerBalance::class)($sellerB->id)['clearing']->minor);

        $this->assertSame(SellerOrderStatus::Completed, $orderA->refresh()->status);
        $this->assertSame(SellerOrderStatus::Delivered, $orderB->refresh()->status);
    }

    #[Test]
    public function refunding_one_seller_leaves_the_other_untouched(): void
    {
        ['offer' => $a, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 10_000, stock: 5);
        ['offer' => $b, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 5_000, stock: 5);

        $order = $this->placeOrder([[$a, 1], [$b, 1]]);
        $this->payFor($order);

        $orderA = $this->sellerOrderFor($order->id, $sellerA->id);
        $orderB = $this->sellerOrderFor($order->id, $sellerB->id);

        $this->deliver($this->shipEverything($orderA));
        $this->deliver($this->shipEverything($orderB));

        $itemA = OrderItem::query()->where('seller_order_id', $orderA->id)->firstOrFail();

        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $itemA->id, 'amount_minor' => 10_000, 'quantity' => 1]],
            reason: 'Only the kettle came back.',
        );

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(0, app(SellerBalance::class)($sellerA->id)['available']->minor);
        $this->assertSame(
            4_400,
            app(SellerBalance::class)($sellerB->id)['available']->minor,
            'Cross-seller isolation holds in the ledger as it does everywhere else.',
        );
    }

    #[Test]
    public function nobody_but_the_clock_can_make_money_available(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $sellerAccountId = (int) $sellerOrder->seller_account_id;

        /*
         * §63: a seller must not be able to reach the money sooner by
         * declaring the order finished. Completion is a transition only
         * the sweep makes, and it makes it from a date the platform wrote.
         */
        $this->assertSame(0, app(ReleaseClearedEarnings::class)());
        $this->assertSame(0, app(SellerBalance::class)($sellerAccountId)['available']->minor);

        // Even asking the sweep directly, before the date, does nothing.
        app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(0, app(SellerBalance::class)($sellerAccountId)['available']->minor);
        $this->assertSame(SellerOrderStatus::Delivered, $sellerOrder->refresh()->status);

        // Only time moves it.
        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(8_800, app(SellerBalance::class)($sellerAccountId)['available']->minor);
    }

    #[Test]
    public function the_clearing_date_is_taken_from_delivery_not_from_when_it_was_recorded(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);

        $deliveredAt = Carbon::now();
        $this->deliver($shipment);

        $entry = SellerLedgerEntry::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertNotNull($entry->available_at);
        $this->assertEqualsWithDelta(
            $deliveredAt->copy()->addDays(7)->timestamp,
            $entry->available_at->timestamp,
            5,
        );
    }
}

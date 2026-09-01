<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Cart\Models\Cart;
use App\Modules\Checkout\Actions\StartCheckout;
use App\Modules\Checkout\Enums\CheckoutStatus;
use App\Modules\Checkout\Jobs\ExpireCheckoutAttempts;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Jobs\ExpireReservations;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Actions\CancelUnpaidOrder;
use App\Modules\Orders\Actions\MarkOrderPaid;
use App\Modules\Orders\Jobs\ExpireUnpaidOrders;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\SellerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * §26, end to end, against real order data.
 *
 * Checkout, order pending payment, holds active, the clock runs out,
 * everything unwinds — and then the sweep runs a second time and changes
 * nothing. The second run is the assertion that matters: these are queued
 * jobs, queued jobs are retried, and a release that restored stock twice
 * would invent inventory out of a retry.
 */
final class UnpaidOrderExpiryTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    #[Test]
    public function the_whole_unwind_happens_once_however_many_times_it_runs(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle', stock: 5);
        ['offer' => $b] = $this->sellableOffer('Lamp', stock: 5);

        $order = $this->placeOrder([[$a, 3], [$b, 2]]);

        // Where the marketplace stands with an order awaiting payment.
        $this->assertSame(3, $this->reserved($a->id));
        $this->assertSame(2, $this->reserved($b->id));
        $this->assertSame(5, $this->onHand($a->id), 'A hold never moves physical stock.');
        $this->assertSame(2, SellerOrder::query()->withoutGlobalScopes()->count());

        $order->forceFill(['payment_expires_at' => now()->subMinute()])->save();
        DB::table('checkout_attempts')->update(['expires_at' => now()->subMinute()]);

        // Every sweep, twice, in the order the scheduler would run them.
        for ($run = 1; $run <= 2; $run++) {
            app(ExpireUnpaidOrders::class)->handle(app(CancelUnpaidOrder::class));
            app(ExpireCheckoutAttempts::class)->handle(app(ReleaseReservation::class));
            app(ExpireReservations::class)->handle(app(ReleaseReservation::class));
        }

        $order->refresh();

        $this->assertSame('cancelled', $order->status->value);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(
            ['cancelled', 'cancelled'],
            SellerOrder::query()->withoutGlobalScopes()->pluck('status')
                ->map(static fn ($s): string => is_string($s) ? $s : $s->value)->all(),
        );

        // Stock is back exactly once. on_hand never moved at all.
        $this->assertSame(0, $this->reserved($a->id));
        $this->assertSame(0, $this->reserved($b->id));
        $this->assertSame(5, $this->onHand($a->id));
        $this->assertSame(5, $this->available($a->id));
        $this->assertSame(5, $this->available($b->id));

        $releases = InventoryMovement::query()
            ->whereIn('reason', [
                InventoryMovementReason::ReservationRelease->value,
                InventoryMovementReason::ReservationExpired->value,
            ])
            ->count();

        $this->assertSame(2, $releases, 'Two holds, two releases — not four.');

        // Nothing was sold, so nothing was earned.
        $this->assertSame(0, SellerLedgerEntry::query()->count());
        $this->assertSame(
            0,
            InventoryMovement::query()
                ->where('reason', InventoryMovementReason::SaleCompleted->value)
                ->count(),
        );
    }

    #[Test]
    public function the_released_stock_is_immediately_buyable_by_the_next_customer(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 1);

        $abandoned = $this->placeOrder([$offer]);
        $this->assertSame(0, $this->available($offer->id));

        $abandoned->forceFill(['payment_expires_at' => now()->subMinute()])->save();
        app(ExpireUnpaidOrders::class)->handle(app(CancelUnpaidOrder::class));

        // The whole point of the payment window: an abandoned checkout
        // must not take a seller's last unit off the market for good.
        $this->assertSame(1, $this->available($offer->id));

        $next = $this->placeOrder([$offer]);

        $this->assertSame('pending_payment', $next->status->value);
        $this->assertSame(2, MarketplaceOrder::query()->count());
    }

    #[Test]
    public function an_abandoned_checkout_that_never_reached_an_order_unwinds_too(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 4);

        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 3]);
        $attempt = app(StartCheckout::class)(
            Cart::query()->firstOrFail(),
            'abandoned-key',
            $this->orderAddress(),
        );

        $this->assertSame(3, $this->reserved($offer->id));

        $attempt->forceFill(['expires_at' => now()->subMinute()])->save();

        app(ExpireCheckoutAttempts::class)->handle(app(ReleaseReservation::class));
        app(ExpireCheckoutAttempts::class)->handle(app(ReleaseReservation::class));

        $this->assertSame(CheckoutStatus::Expired, CheckoutAttempt::query()->firstOrFail()->status);
        $this->assertSame(0, $this->reserved($offer->id));
        $this->assertSame(4, $this->available($offer->id));
        $this->assertSame(0, MarketplaceOrder::query()->count());
    }

    #[Test]
    public function a_paid_order_survives_every_sweep(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 6);
        $order = $this->placeOrder([[$offer, 2]]);

        app(MarkOrderPaid::class)($order);

        // Backdated deliberately: the sweeps must key off state, not
        // only off the clock.
        DB::table('marketplace_orders')->update(['payment_expires_at' => now()->subHour()]);
        DB::table('checkout_attempts')->update(['expires_at' => now()->subHour()]);

        app(ExpireUnpaidOrders::class)->handle(app(CancelUnpaidOrder::class));
        app(ExpireCheckoutAttempts::class)->handle(app(ReleaseReservation::class));
        app(ExpireReservations::class)->handle(app(ReleaseReservation::class));

        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertSame(4, $this->onHand($offer->id), 'The sale stands.');
        $this->assertSame(0, $this->reserved($offer->id));
    }

    #[Test]
    public function an_order_inside_its_window_is_untouched_by_a_sweep(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $order = $this->placeOrder([[$offer, 2]]);

        app(ExpireUnpaidOrders::class)->handle(app(CancelUnpaidOrder::class));
        app(ExpireCheckoutAttempts::class)->handle(app(ReleaseReservation::class));
        app(ExpireReservations::class)->handle(app(ReleaseReservation::class));

        $this->assertSame('pending_payment', $order->refresh()->status->value);
        $this->assertSame(2, $this->reserved($offer->id));
    }

    #[Test]
    public function the_reservation_sweep_alone_does_not_leave_an_order_stranded(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $order = $this->placeOrder([[$offer, 2]]);

        DB::table('inventory_reservations')->update(['expires_at' => now()->subMinute()]);
        $order->forceFill(['payment_expires_at' => now()->subMinute()])->save();

        // The inventory sweep wins the race and releases the hold first.
        app(ExpireReservations::class)->handle(app(ReleaseReservation::class));
        $this->assertSame(0, $this->reserved($offer->id));

        // The order sweep must still close the order behind it, rather
        // than leaving a row nobody can fulfil claiming stock it no
        // longer holds.
        app(ExpireUnpaidOrders::class)->handle(app(CancelUnpaidOrder::class));

        $this->assertSame('cancelled', $order->refresh()->status->value);
        $this->assertSame(5, $this->available($offer->id));
    }

    private function reserved(int $offerId): int
    {
        return (int) DB::table('inventory_balances')->where('offer_id', $offerId)->value('reserved');
    }

    private function onHand(int $offerId): int
    {
        return (int) DB::table('inventory_balances')->where('offer_id', $offerId)->value('on_hand');
    }

    private function available(int $offerId): int
    {
        return (int) DB::table('inventory_balances')->where('offer_id', $offerId)->value('available');
    }
}

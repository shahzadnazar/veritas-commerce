<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Actions\CancelUnpaidOrder;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Support\OrderPayability;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PlatformRevenueEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * Two things get called "cancelled", and they are not the same thing.
 *
 * A **payment attempt** failing or being cancelled at the provider is one
 * try at moving money that did not work — usually a customer reaching for
 * a different card. The order survives it, with its stock still held.
 *
 * An **order** being cancelled, or its checkout expiring, is the business
 * decision: it stops being payable, the hold goes back, and no seller
 * earning or platform commission is realised.
 *
 * M6's fulfilment work depends on the line between them staying where M5
 * put it, so it is pinned here rather than left as a comment.
 */
final class OrderCancellationSemanticsTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    #[Test]
    public function a_provider_cancellation_ends_the_attempt_and_not_the_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        ['reference' => $reference] = $this->prepare($order);

        $this->provider()->settle($reference, PaymentAttemptStatus::Cancelled);
        $this->deliverEvent('payment_intent.canceled', $reference);

        $this->assertSame(PaymentAttemptStatus::Cancelled, PaymentAttempt::query()->firstOrFail()->status);

        // The order and every seller order are untouched.
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertNull($order->cancelled_at);
        $this->assertSame(
            [SellerOrderStatus::PendingPayment],
            SellerOrder::query()->withoutGlobalScopes()->get()->pluck('status')->all(),
        );

        // The stock is still theirs to buy.
        $this->assertSame(
            [ReservationStatus::Held],
            InventoryReservation::query()->get()->pluck('status')->unique()->values()->all(),
        );
        $this->assertTrue(OrderPayability::isPayable($order->refresh()));

        // And it really is payable: another card goes through.
        ['reference' => $second] = $this->prepare($order->refresh());
        $this->provider()->settle($second, PaymentAttemptStatus::Succeeded);
        $this->deliverEvent('payment_intent.succeeded', $second);

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
    }

    #[Test]
    public function cancelling_the_order_releases_the_hold_and_realises_no_money(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->prepare($order);

        $this->assertTrue(app(CancelUnpaidOrder::class)($order->refresh(), 'Customer changed their mind.'));

        $order->refresh();

        $this->assertSame(MarketplaceOrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertFalse(OrderPayability::isPayable($order));
        $this->assertSame(OrderPayability::NOT_PAYABLE, OrderPayability::reasonNotPayable($order));

        $this->assertSame(
            [SellerOrderStatus::Cancelled],
            SellerOrder::query()->withoutGlobalScopes()->get()->pluck('status')->all(),
        );

        // The hold goes back, and no money was realised on the way out.
        $this->assertSame(
            [ReservationStatus::Released],
            InventoryReservation::query()->get()->pluck('status')->unique()->values()->all(),
        );
        $this->assertSame(
            10,
            (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('available'),
        );
        $this->assertSame(0, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, PlatformRevenueEntry::query()->count());
    }

    #[Test]
    public function cancelling_twice_cancels_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->assertTrue(app(CancelUnpaidOrder::class)($order->refresh(), 'expired'));
        $this->assertFalse(app(CancelUnpaidOrder::class)($order->refresh(), 'expired'));
        $this->assertFalse(app(CancelUnpaidOrder::class)($order->refresh(), 'expired'));

        // One cancellation, one history row per scope, one release.
        $this->assertSame(
            1,
            OrderStatusHistory::query()
                ->where('marketplace_order_id', $order->id)
                ->where('to_status', MarketplaceOrderStatus::Cancelled->value)
                ->count(),
        );
        $this->assertSame(
            1,
            InventoryReservation::query()->where('status', ReservationStatus::Released->value)->count(),
        );
    }

    #[Test]
    public function an_expired_window_refuses_payment_without_pretending_it_was_cancelled(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $order->forceFill(['payment_expires_at' => now()->subMinute()])->save();

        // Still pending_payment as a row — the sweep has not run — but no
        // longer payable, and told apart from a cancellation.
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(OrderPayability::EXPIRED, OrderPayability::reasonNotPayable($order));
    }

    #[Test]
    public function a_paid_order_is_not_payable_again(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $this->assertSame(OrderPayability::ALREADY_PAID, OrderPayability::reasonNotPayable($order->refresh()));

        // And the sweep cannot undo a paid order.
        $this->assertFalse(app(CancelUnpaidOrder::class)($order->refresh(), 'expired'));
        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
    }
}

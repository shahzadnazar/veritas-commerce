<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Actions\CancelUnpaidOrder;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Actions\FinalizeRefund;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ProviderEventStatus;
use App\Modules\Payments\Exceptions\PaymentRefused;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PlatformRevenueEntry;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use App\Modules\Payments\Models\Refund;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;
use Throwable;

/**
 * §76 — the six ways two things happening at once could move money twice.
 *
 * Truncation rather than RefreshDatabase, for the reason the checkout and
 * inventory concurrency suites give: work wrapped in a transaction that is
 * never committed is invisible to a second connection, and two sessions
 * that cannot see each other's rows prove nothing about a race.
 *
 * These tests commit, and where a second worker matters they act through a
 * separate connection so the guard being exercised is the database's — a
 * unique index, a conditional UPDATE, a row lock — and not an application
 * check that would lose the race in production.
 */
final class PaymentConcurrencyTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    #[Test]
    public function two_deliveries_of_one_success_cannot_finalize_it_twice(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_500, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        // Two distinct events about the same payment, both genuine — a
        // provider retry that arrived after the original was already
        // committed, which is the ordinary case rather than the exotic one.
        $this->deliverEvent('payment_intent.succeeded', $reference, eventId: 'evt_first');
        $this->deliverEvent('payment_intent.succeeded', $reference, eventId: 'evt_second');

        $this->assertSame(2, ProviderWebhookEvent::query()->count(), 'Both events are recorded.');

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(1, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, PlatformRevenueEntry::query()->count());

        // And the stock moved once: the commit claims held rows, so the
        // second pass finds nothing left to claim.
        $this->assertSame(
            [ReservationStatus::Consumed],
            InventoryReservation::query()->get()->pluck('status')->unique()->values()->all(),
        );
        $this->assertSame(
            1,
            InventoryMovement::query()->where('on_hand_change', '<', 0)->count(),
            'One sale, one movement off the shelf.',
        );
        $this->assertSame(
            8,
            (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('on_hand'),
        );
    }

    #[Test]
    public function a_second_worker_claiming_the_same_event_touches_no_financial_row(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        $this->assertSame(ProviderEventStatus::Processed, $event->refresh()->status);

        $ledgerBefore = SellerLedgerEntry::query()->withoutGlobalScopes()->count();
        $attemptsBefore = (int) $event->attempts;

        /*
         * The other worker, on its own connection and seeing the winner's
         * committed row. Its conditional UPDATE matches nothing, because
         * the status is no longer one this job will claim — the WHERE is
         * the lock, and it holds across connections as an application-level
         * "have I seen this?" never would.
         */
        $second = DB::connection('concurrent');

        try {
            $claimed = $second->table('provider_webhook_events')
                ->where('id', $event->id)
                ->whereIn('status', [ProviderEventStatus::Received->value, ProviderEventStatus::Failed->value])
                ->update(['attempts' => $second->raw('attempts + 1')]);

            $this->assertSame(0, $claimed, 'A processed event is not claimable.');
        } finally {
            $this->cleanUp($second);
        }

        // And running the whole job again is equally inert.
        $this->reprocess($event);

        $this->assertSame($attemptsBefore, (int) $event->refresh()->attempts);
        $this->assertSame($ledgerBefore, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    #[Test]
    public function expiry_winning_the_race_leaves_a_late_payment_as_an_exception(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);

        // The sweep gets there first and puts the stock back on the shelf.
        app(CancelUnpaidOrder::class)($order->refresh(), 'expired');

        $this->assertSame(MarketplaceOrderStatus::Cancelled, $order->refresh()->status);

        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        /*
         * §23. Money arriving for a cancelled order must NOT quietly
         * revive it: the stock went back and may already be someone
         * else's. It becomes an operational exception — and, in the real
         * world, a refund.
         */
        $this->assertSame(MarketplaceOrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(ProviderEventStatus::Failed, $event->refresh()->status);
        $this->assertStringContainsString('order_not_open', (string) $event->error);

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            [ReservationStatus::Released],
            InventoryReservation::query()->get()->pluck('status')->unique()->values()->all(),
        );
    }

    #[Test]
    public function payment_winning_the_race_survives_the_expiry_sweep_behind_it(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);

        // The sweep arrives a moment late, with the window long past.
        $order->forceFill(['payment_expires_at' => now()->subHour()])->save();

        try {
            app(CancelUnpaidOrder::class)($order->refresh(), 'expired');
        } catch (Throwable) {
            // Refusing outright is equally correct; what matters is below.
        }

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(
            [ReservationStatus::Consumed],
            InventoryReservation::query()->get()->pluck('status')->unique()->values()->all(),
        );
        $this->assertSame(1, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function two_refunds_racing_for_the_same_balance_cannot_exceed_it(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 6_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();
        $lines = [['order_item_id' => $item->id, 'amount_minor' => 6_000, 'quantity' => 1]];

        $accepted = 0;

        // Two operators, each refunding the whole thing. The second reads
        // the first's claim under the payment's lock, so it loses.
        foreach (['first', 'second'] as $key) {
            try {
                app(RequestRefund::class)($order, $lines, "Refund attempt {$key}.", null, $key);
                $accepted++;
            } catch (PaymentRefused $refused) {
                $this->assertSame('exceeds_item_refundable', $refused->reason);
            }
        }

        $this->assertSame(1, $accepted, 'One payment, one full refund.');
        $this->assertSame(6_000, (int) Payment::query()->firstOrFail()->refunded_amount_minor);
        $this->assertSame(6_000, (int) Refund::query()->sum('amount_minor'));
    }

    #[Test]
    public function a_refund_event_delivered_repeatedly_reverses_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        $refund = app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 5_000, 'quantity' => 1]],
            reason: 'Returned, and the provider is enthusiastic about telling us.',
        );

        $reference = (string) $refund->provider_refund_reference;

        // Four more deliveries, one of them from another connection's
        // point of view — every one finds the refund already terminal.
        foreach (range(1, 4) as $_) {
            app(FinalizeRefund::class)($reference);
        }

        $second = DB::connection('concurrent');

        try {
            $this->assertSame(
                1,
                (int) $second->table('seller_ledger_entries')->where('type', 'refund_reversal')->count(),
            );
            $this->assertSame(
                1,
                (int) $second->table('platform_revenue_entries')->where('type', 'commission_reversal')->count(),
            );
            $this->assertSame(
                1,
                (int) $second->table('payment_transactions')->where('type', 'refund')->count(),
            );
        } finally {
            $this->cleanUp($second);
        }

        $this->assertSame(5_000, (int) Payment::query()->firstOrFail()->refunded_amount_minor);
    }

    #[Test]
    public function a_retry_after_a_decline_pays_the_same_order_and_never_a_second_one(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 3]]);

        ['reference' => $declined] = $this->prepare($order);
        $this->provider()->settle($declined, PaymentAttemptStatus::Failed);
        $this->deliverEvent('payment_intent.payment_failed', $declined);

        // The customer reaches for another card. Same order, same hold.
        ['reference' => $retried] = $this->prepare($order->refresh());

        $this->assertNotSame($declined, $retried, 'A fresh attempt, not a reused decline.');

        $this->provider()->settle($retried, PaymentAttemptStatus::Succeeded);
        $this->deliverEvent('payment_intent.succeeded', $retried);

        $this->assertSame(1, MarketplaceOrder::query()->count());
        $this->assertSame(1, SellerOrder::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, PaymentAttempt::query()->count(), 'Two tries, both kept.');
        $this->assertSame(1, Payment::query()->count(), 'One capture.');

        // The hold was taken once at checkout and committed once here.
        $this->assertSame(1, InventoryReservation::query()->count());
        $this->assertSame(
            [ReservationStatus::Consumed],
            InventoryReservation::query()->get()->pluck('status')->unique()->values()->all(),
        );
        // Ten on hand, three sold: the decline never touched the stock.
        $this->assertSame(
            7,
            (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('on_hand'),
        );
    }

    private function cleanUp(mixed $second): void
    {
        try {
            if ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
        } catch (Throwable) {
            // Already resolved.
        }

        $second->disconnect();
    }
}

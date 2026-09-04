<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ProviderEventStatus;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Jobs\ProcessProviderEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PlatformRevenueEntry;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use App\Modules\Payments\Models\Refund;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * The parts of the lifecycle the happy path never reaches.
 *
 * Cancellation before capture, a refund decided by a webhook rather than
 * the request that asked for it, the arithmetic when a total does not
 * divide cleanly, and what the queue does when processing fails. Each one
 * is a place where an implementation that only ever ran the intended
 * sequence would leave money in a state nobody can reconcile.
 */
final class PaymentLifecycleTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    #[Test]
    public function a_cancellation_before_capture_records_no_money_and_returns_the_stock(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        ['reference' => $reference] = $this->prepare($order);

        $this->provider()->settle($reference, PaymentAttemptStatus::Cancelled);
        $this->deliverEvent('payment_intent.canceled', $reference);

        $attempt = PaymentAttempt::query()->firstOrFail();

        $this->assertSame(PaymentAttemptStatus::Cancelled, $attempt->status);
        $this->assertNotNull($attempt->cancelled_at);

        // §42: nothing financial happened, because nothing financial did.
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PaymentTransaction::query()->count());
        $this->assertSame(0, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, PlatformRevenueEntry::query()->count());

        /*
         * The hold is NOT released here — §20's rule holds for a cancelled
         * attempt exactly as for a declined one. The customer can still
         * pay this order with another method until the expiry sweep says
         * otherwise, and destroying their order at the provider's word
         * would lose a sale the customer had not abandoned.
         */
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(
            [ReservationStatus::Held],
            InventoryReservation::query()->get()->pluck('status')->unique()->values()->all(),
        );
    }

    #[Test]
    public function a_cancellation_event_delivered_twice_changes_nothing_the_second_time(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Cancelled);

        $first = $this->deliverEvent('payment_intent.canceled', $reference);
        $cancelledAt = PaymentAttempt::query()->firstOrFail()->cancelled_at;

        $this->deliverEvent('payment_intent.canceled', $reference, eventId: 'evt_cancel_again');
        $this->reprocess($first);

        $attempt = PaymentAttempt::query()->firstOrFail();

        $this->assertSame(PaymentAttemptStatus::Cancelled, $attempt->status);
        $this->assertEquals($cancelledAt, $attempt->cancelled_at, 'A terminal attempt does not move again.');
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    #[Test]
    public function a_refund_decided_by_a_provider_event_reverses_exactly_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        // The provider takes its time, as a bank refund does.
        $this->provider()->refundsResolveAs(RefundStatus::Processing);

        $item = OrderItem::query()->firstOrFail();

        $refund = app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 5_000, 'quantity' => 1]],
            reason: 'Returned; the provider will confirm later.',
        );

        // §43: the HTTP request that asked for the refund is not the final
        // authority. Nothing is reversed on the strength of asking.
        $this->assertSame(RefundStatus::Processing, $refund->refresh()->status);
        $this->assertSame(1, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, (int) Payment::query()->firstOrFail()->refunded_amount_minor);

        // The provider settles, and tells us through a signed event.
        $this->provider()->settleRefund((string) $refund->provider_refund_reference, RefundStatus::Succeeded);

        $reference = (string) $refund->provider_refund_reference;
        $event = $this->deliverRefundEvent('refund.updated', $reference);
        $this->deliverRefundEvent('refund.updated', $reference, eventId: 'evt_refund_again');
        $this->reprocess($event);

        $this->assertSame(RefundStatus::Succeeded, $refund->refresh()->status);
        $this->assertSame(2, SellerLedgerEntry::query()->withoutGlobalScopes()->count(), 'Sale, then one reversal.');
        $this->assertSame(1, PlatformRevenueEntry::query()->where('type', PlatformRevenueEntry::TYPE_REVERSAL)->count());
        $this->assertSame(1, PaymentTransaction::query()->where('type', 'refund')->count());
        $this->assertSame(5_000, (int) Payment::query()->firstOrFail()->refunded_amount_minor);
    }

    #[Test]
    public function commission_rounding_is_deterministic_and_the_seller_takes_the_remainder(): void
    {
        // 12% of 3,333 is 399.96 — the case where a naive float leaves a
        // cent nobody owns.
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 3_333, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        $this->assertSame(3_333, $item->line_total_minor);
        $this->assertSame(400, $item->commission_amount_minor, 'Rounded half up, once, at purchase.');
        $this->assertSame(2_933, $item->seller_earning_amount_minor, 'The seller takes the remainder.');
        $this->assertSame(
            $item->line_total_minor,
            $item->commission_amount_minor + $item->seller_earning_amount_minor,
            'Not one minor unit is created or lost.',
        );

        $this->assertSame(400, (int) PlatformRevenueEntry::query()->sum('amount_minor'));
        $this->assertSame(2_933, (int) SellerLedgerEntry::query()->withoutGlobalScopes()->sum('amount_minor'));
    }

    #[Test]
    public function a_multi_seller_payment_reconciles_to_the_customers_total(): void
    {
        ['offer' => $a] = $this->sellableOffer(title: 'Kettle', priceMinor: 3_333, stock: 10);
        ['offer' => $b] = $this->sellableOffer(title: 'Grinder', priceMinor: 4_999, stock: 10);
        ['offer' => $c] = $this->sellableOffer(title: 'Scale', priceMinor: 1_111, stock: 10);

        $order = $this->placeOrder([[$a, 2], [$b, 1], [$c, 3]]);

        $this->payFor($order);

        $commission = (int) PlatformRevenueEntry::query()->sum('amount_minor');
        $earnings = (int) SellerLedgerEntry::query()->withoutGlobalScopes()->sum('amount_minor');

        // The whole identity, across three sellers and six units.
        $this->assertSame($order->grand_total_minor, $commission + $earnings);
        $this->assertSame($order->grand_total_minor, (int) Payment::query()->firstOrFail()->amount_minor);

        // And per seller order, so a reconciliation that netted out across
        // sellers could not hide a discrepancy inside one of them.
        foreach (SellerOrder::query()->withoutGlobalScopes()->get() as $sellerOrder) {
            $this->assertSame(
                $sellerOrder->order_total_minor,
                $sellerOrder->commission_total_minor + $sellerOrder->seller_earning_total_minor,
            );
        }

        $this->assertSame(3, SellerOrder::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function shipping_and_tax_are_zero_in_this_phase_and_the_refund_does_not_invent_them(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        // §74: M4's policy, stated rather than assumed. When the business
        // defines shipping and tax, the refund allocator will need a rule
        // for reversing them; until then it must not guess one.
        $this->assertSame(0, $order->shipping_total_minor);
        $this->assertSame(0, $order->tax_total_minor);
        $this->assertSame($order->items_total_minor, $order->grand_total_minor);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => $item->line_total_minor, 'quantity' => 1]],
            reason: 'Returned in full.',
        );

        // The refund is the line total, with nothing added for postage the
        // platform never charged.
        $this->assertSame($order->grand_total_minor, (int) Refund::query()->firstOrFail()->amount_minor);
        $this->assertSame(0, (int) SellerLedgerEntry::query()->withoutGlobalScopes()->sum('amount_minor'));
        $this->assertSame(0, (int) PlatformRevenueEntry::query()->sum('amount_minor'));
    }

    #[Test]
    public function an_event_whose_processing_fails_stays_visible_and_retryable(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);

        // The provider says an amount the order does not agree with, which
        // is a disagreement about money and not a transient fault.
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);
        $this->provider()->tamperAmount($reference, 1);

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        // §63: the event is kept, marked failed, and readable by an
        // operator. Nothing partial was written.
        $this->assertSame(ProviderEventStatus::Failed, $event->refresh()->status);
        $this->assertNotNull($event->failed_at);
        $this->assertStringContainsString('amount_mismatch', (string) $event->error);

        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, SellerLedgerEntry::query()->withoutGlobalScopes()->count());

        // Retryable: the provider corrects itself and the same stored
        // event goes through, because a failed row is claimable again.
        $this->provider()->tamperAmount($reference, 4_000);
        $this->reprocess($event);

        $this->assertSame(ProviderEventStatus::Processed, $event->refresh()->status);
        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(1, Payment::query()->count());
    }

    #[Test]
    public function provider_events_are_processed_on_the_payments_queue_and_nothing_else(): void
    {
        Queue::fake();

        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $provider = $this->provider();
        $signed = $provider->signedEvent('payment_intent.succeeded', $provider->paymentObject($reference));

        $this->call(
            'POST',
            '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
            content: $signed['payload'],
        )->assertOk();

        /*
         * §62: verified and persisted before the job exists, so a 200 is
         * never an acknowledgement of something the platform has not
         * looked at. §65: its own queue, so a backlog of emails or images
         * cannot delay a payment finalizing.
         */
        $this->assertSame(1, ProviderWebhookEvent::query()->count());

        Queue::assertPushedOn(
            Queues::PAYMENTS,
            ProcessProviderEvent::class,
        );
    }

    #[Test]
    public function the_payments_queue_is_configured_ahead_of_every_other_lane(): void
    {
        // §65 as configuration rather than intention: its own supervisor,
        // more retries than anything else, and first in the drain order.
        $this->assertSame(Queues::PAYMENTS, Queues::all()[0]);

        $supervisors = config('horizon.defaults');

        $this->assertIsArray($supervisors);
        $this->assertArrayHasKey('payments', $supervisors);
        $this->assertSame([Queues::PAYMENTS], $supervisors['payments']['queue']);
        $this->assertSame(8, $supervisors['payments']['tries']);

        // Not sharing a pool with anything: a queue of emails must never
        // be able to occupy the processes a payment needs.
        foreach ($supervisors as $name => $supervisor) {
            if ($name === 'payments') {
                continue;
            }

            $this->assertNotContains(Queues::PAYMENTS, $supervisor['queue'], "`{$name}` must not drain payments.");
        }
    }

    #[Test]
    public function the_customer_sees_every_state_the_order_actually_reaches(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $page = fn (): array => (array) $this->asUser($user)
            ->getJson("/checkout/{$order->reference}/payment/status")
            ->json('payment');

        $this->assertSame('awaiting_payment', $page()['state']);

        ['reference' => $reference] = $this->prepare($order);

        $this->provider()->settle($reference, PaymentAttemptStatus::Processing);
        $this->deliverEvent('payment_intent.processing', $reference);

        $processing = $page();

        $this->assertSame('processing', $processing['state']);
        $this->assertFalse($processing['isPaid'], 'Processing is not paid, and never says so.');
        $this->assertFalse($processing['canPay']);

        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);
        $this->deliverEvent('payment_intent.succeeded', $reference, eventId: 'evt_success');

        $paid = $page();

        $this->assertSame('paid', $paid['state']);
        $this->assertTrue($paid['isPaid']);
        $this->assertSame('Payment received. Your order is confirmed.', $paid['headline']);
    }

    #[Test]
    public function the_admin_screen_shows_the_refunded_and_partially_refunded_states(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 8_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();
        $admin = $this->makeAdmin(AdminRole::FinanceAdmin);

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 3_000, 'quantity' => 0]],
            reason: 'Partial refund for a damaged corner.',
        );

        $this->asAdmin($admin)->get("/admin/payments/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('payment.status', 'partially_refunded')
                ->where('payment.refundedMinor', 3_000)
                ->where('payment.refundableMinor', 5_000)
                ->has('refunds', 1));

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 5_000, 'quantity' => 1]],
            reason: 'The customer returned it after all.',
        );

        $this->asAdmin($admin)->get("/admin/payments/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('payment.status', 'refunded')
                ->where('payment.refundableMinor', 0)
                ->has('refunds', 2));

        // Two refunds, both kept: the history is the record.
        $this->assertSame(2, Refund::query()->count());
        $this->assertSame(8_000, (int) Refund::query()->sum('amount_minor'));
    }

    #[Test]
    public function the_payment_page_shows_a_failure_without_showing_the_provider(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        ['reference' => $reference] = $this->prepare($order);

        $this->provider()->settle($reference, PaymentAttemptStatus::Failed);
        $this->deliverEvent('payment_intent.payment_failed', $reference);

        $response = $this->asUser($user)->get("/checkout/{$order->reference}/payment")->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Checkout/PaymentPending')
            ->where('payment.state', 'failed')
            ->where('payment.canRetry', true)
            ->where('payment.canPay', true));

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString($reference, $body);
        $this->assertStringNotContainsString('fake_pi_', $body);
    }

    #[Test]
    public function one_order_is_paid_once_however_many_attempts_it_took(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        // Three declines and a success — a bad evening with a card.
        foreach (range(1, 3) as $round) {
            ['reference' => $failed] = $this->prepare($order->refresh());
            $this->provider()->settle($failed, PaymentAttemptStatus::Failed);
            $this->deliverEvent('payment_intent.payment_failed', $failed, eventId: "evt_fail_{$round}");
        }

        ['reference' => $good] = $this->prepare($order->refresh());
        $this->provider()->settle($good, PaymentAttemptStatus::Succeeded);
        $this->deliverEvent('payment_intent.succeeded', $good);

        $this->assertSame(4, PaymentAttempt::query()->count(), 'Every try is a row.');
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, MarketplaceOrder::query()->count());
        $this->assertSame(1, InventoryReservation::query()->count());
        $this->assertSame(1, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
    }
}

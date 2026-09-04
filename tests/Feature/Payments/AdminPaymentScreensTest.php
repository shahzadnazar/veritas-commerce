<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Data\ProviderFailure;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * The admin payment screens, and who gets to see what on them.
 *
 * Three permissions, three audiences. `payments.view` answers "did this
 * order pay". `orders.refund` is the only way money goes back out.
 * `payments.events.view` opens the provider's own event trail, which is
 * an incident tool rather than a support one.
 */
final class AdminPaymentScreensTest extends TestCase
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
    public function the_list_shows_captured_and_refunded_side_by_side(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 1_000, 'quantity' => 0]],
            reason: 'Goodwill for a late delivery.',
        );

        $this->asAdmin($this->makeAdmin(AdminRole::FinanceAdmin))
            ->get('/admin/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payments/Index')
                ->where('payments.data.0.orderReference', $order->reference)
                ->where('payments.data.0.amountMinor', 4_000)
                ->where('payments.data.0.refundedMinor', 1_000)
                ->where('payments.data.0.netMinor', 3_000)
                ->where('payments.data.0.status', 'partially_refunded'));
    }

    #[Test]
    public function the_detail_lists_every_attempt_including_the_failed_ones(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $first] = $this->prepare($order);
        $this->provider()->settle(
            $first,
            PaymentAttemptStatus::Failed,
            failure: new ProviderFailure('card_declined', 'generic_decline', 'Your card was declined.'),
        );
        $this->deliverEvent('payment_intent.payment_failed', $first);

        $this->payFor($order);

        $this->asAdmin($this->makeAdmin(AdminRole::FinanceAdmin))
            ->get("/admin/payments/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payments/Show')
                ->has('attempts', 2)
                ->where('attempts.0.status', 'failed')
                // The provider's own code, for the operator only. §53
                // keeps it out of everything the customer ever sees.
                ->where('attempts.0.failureCode', 'card_declined')
                ->where('attempts.1.status', 'succeeded')
                ->where('payment.amountMinor', 4_000)
                ->where('can.refund', true));
    }

    #[Test]
    public function support_can_read_a_payment_but_not_the_provider_events_or_the_refund_control(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $this->asAdmin($this->makeAdmin(AdminRole::Support))
            ->get("/admin/payments/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.refund', false)
                ->where('can.viewEvents', false)
                // Not merely hidden in the UI: the payload is not built.
                ->where('providerEvents', [])
                ->where('refundableItems', []));
    }

    #[Test]
    public function the_provider_event_trail_opens_for_the_roles_that_reconcile_money(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $response = $this->asAdmin($this->makeAdmin(AdminRole::FinanceAdmin))
            ->get("/admin/payments/{$order->reference}")
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('can.viewEvents', true)
            ->has('providerEvents.0', fn ($event) => $event
                ->where('type', 'payment_intent.succeeded')
                ->where('status', 'processed')
                ->etc()));

        // Metadata, not bodies. The stored payload is the one place a
        // provider's description of a payment method sits.
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('payload', $body);
        $this->assertStringNotContainsString('client_secret', $body);
    }

    #[Test]
    public function an_admin_without_payments_view_cannot_open_the_screens_at_all(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $analyst = $this->makeAdmin(AdminRole::Analyst);

        $this->asAdmin($analyst)->get('/admin/payments')->assertForbidden();
        $this->asAdmin($analyst)->get("/admin/payments/{$order->reference}")->assertForbidden();
    }

    #[Test]
    public function the_screens_are_closed_to_everyone_who_is_not_signed_in_as_an_admin(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $this->get('/admin/payments')->assertRedirect('/admin/login');
        $this->get("/admin/payments/{$order->reference}")->assertRedirect('/admin/login');

        // A customer session is not an admin session, whatever the URL.
        $this->asUser(User::factory()->create())
            ->get('/admin/payments')
            ->assertRedirect('/admin/login');
    }

    #[Test]
    public function a_refund_is_audited_with_its_reason(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();
        $admin = $this->makeAdmin(AdminRole::FinanceAdmin);

        $this->asAdmin($admin)->post("/admin/payments/{$order->reference}/refunds", [
            'reason' => 'Customer returned the item unopened.',
            'lines' => [['order_item_id' => $item->id, 'amount_minor' => 4_000, 'quantity' => 1]],
        ])->assertRedirect();

        $log = AuditLog::query()->where('action', 'payment.refunded')->firstOrFail();

        $this->assertSame('admin', $log->actor_type);
        $this->assertSame((int) $admin->id, $log->actor_id);
        $this->assertSame('Customer returned the item unopened.', $log->reason);
        $this->assertSame(4_000, $log->changes['amount_minor'] ?? null);
        $this->assertSame($order->reference, $log->changes['order_reference'] ?? null);
    }

    #[Test]
    public function a_refund_does_not_put_the_stock_back_on_the_shelf(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 2]]);
        $this->payFor($order);

        $afterSale = InventoryBalance::query()->where('offer_id', $offer->id)->firstOrFail();
        $onHand = $afterSale->on_hand;

        $item = OrderItem::query()->firstOrFail();

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => $item->line_total_minor, 'quantity' => 2]],
            reason: 'Customer changed their mind.',
        );

        /*
         * Money coming back is not goods coming back. The item may be in
         * a courier's van, on a customer's shelf, or destroyed; restocking
         * on a refund would offer stock the seller does not have, and the
         * next buyer would find that out. Returns are M6's, and they start
         * from a physical event, not a financial one.
         */
        $this->assertSame(
            $onHand,
            InventoryBalance::query()->where('offer_id', $offer->id)->firstOrFail()->on_hand,
        );
    }
}

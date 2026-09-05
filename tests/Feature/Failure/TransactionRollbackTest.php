<?php

declare(strict_types=1);

namespace Tests\Feature\Failure;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Models\PayoutRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\Feature\Payouts\BuildsSellerFinance;
use Tests\Support\Failure\FailsAtQuery;
use Tests\TestCase;
use Throwable;

/**
 * A transaction that fails halfway must leave nothing behind.
 *
 * The interesting failures are not the ones at the start. An action that
 * throws before it writes anything is trivially safe; the dangerous
 * moment is after two or three domain objects exist and before the last
 * one does, because that is where a half-finished payment — an order
 * marked paid with no ledger entry, a settlement with no debit — would
 * survive if the boundary were wrong.
 *
 * The failure is injected by replacing one of the action's own
 * collaborators with something that raises. That is the only honest way:
 * a production switch that can make a payment fail halfway is a worse
 * defect than the one being looked for, so there isn't one.
 */
final class TransactionRollbackTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsSellerFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    /**
     * Deliver a signed success event and report what the endpoint said.
     *
     * The status code is the assertion that matters here: the drills run
     * through the real HTTP entry point, where an exception becomes a 500
     * rather than something a `try` can catch — and a 500 is precisely
     * the signal that tells the provider to redeliver.
     */
    private function deliverStatus(string $reference): int
    {
        $provider = $this->provider();
        $signed = $provider->signedEvent('payment_intent.succeeded', $provider->paymentObject($reference));

        return $this->call(
            'POST',
            '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
            content: $signed['payload'],
        )->getStatusCode();
    }

    /**
     * Payment finalisation fails after the order has been marked paid.
     *
     * `RecordFinancialObligations` runs last, after the attempt has
     * transitioned, the payment row exists, the order and its seller
     * orders are paid and the inventory holds have been committed. If the
     * boundary is wrong, this is what a customer's order looks like
     * afterwards: paid, stock gone, and not a cent owed to the seller.
     */
    #[Test]
    public function a_payment_that_fails_after_the_order_is_marked_paid_leaves_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $stockBefore = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        FailsAtQuery::containing('insert into "platform_revenue_entries"');

        $this->assertSame(
            500,
            $this->deliverStatus($reference),
            'The endpoint acknowledged a delivery whose transaction had rolled back.',
        );

        $order->refresh();

        $this->assertSame('pending_payment', $order->status->value, 'The order stayed paid after a rolled-back payment.');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        $this->assertDatabaseCount('platform_revenue_entries', 0);
        $this->assertDatabaseMissing('payment_attempts', ['status' => PaymentAttemptStatus::Succeeded->value]);

        $stockAfter = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        $this->assertEquals($stockBefore?->on_hand, $stockAfter?->on_hand, 'Stock was committed by a rolled-back payment.');
        $this->assertEquals($stockBefore?->reserved, $stockAfter?->reserved, 'A hold was released by a rolled-back payment.');
    }

    /**
     * And the evidence of the failure survives, so it can be retried.
     *
     * The provider event is the durable record. A rollback that also
     * discarded it would leave nothing to retry from.
     */
    #[Test]
    public function the_provider_event_survives_a_rolled_back_finalisation(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        FailsAtQuery::containing('insert into "platform_revenue_entries"');

        $this->deliverStatus($reference);

        $this->assertDatabaseHas('provider_webhook_events', ['status' => 'failed']);
        $this->assertSame(1, DB::table('provider_webhook_events')->value('attempts'));
    }

    /**
     * The same payment then finalises cleanly once the fault is removed.
     *
     * This is what makes the rollback a pause rather than a loss.
     */
    #[Test]
    public function the_rolled_back_payment_finalises_on_the_next_attempt(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        FailsAtQuery::containing('insert into "platform_revenue_entries"');

        $event = null;

        $this->deliverStatus($reference);

        /** @var ProviderWebhookEvent $event */
        $event = ProviderWebhookEvent::query()->firstOrFail();

        // The fault fires once, so this attempt is the clean one — which
        // is what a queue retry after a transient fault looks like.
        $this->reprocess($event);

        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('seller_ledger_entries')->where('type', 'sale_earning')->count());
    }

    /**
     * A settlement that fails before its debit leaves the payout approved.
     *
     * The dangerous half-state here is a payout that reads `paid` with no
     * ledger debit behind it: the seller's balance would still show the
     * money, and a second settlement would send it twice.
     */
    #[Test]
    public function a_payout_settlement_that_fails_leaves_no_partial_state(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->approve($this->requestPayout($seller, 10_000));
        $before = $this->positionOf($seller);

        FailsAtQuery::containing('insert into "seller_ledger_entries"');

        $raised = false;

        try {
            app(RecordPayoutSettlement::class)($request, $this->financeActor(), 'wire', 'FT-ROLLBACK-1');
        } catch (Throwable) {
            $raised = true;
        }

        $this->assertTrue($raised, 'The settlement swallowed a failure from inside its own transaction.');

        /** @var PayoutRequest $fresh */
        $fresh = PayoutRequest::query()->findOrFail($request->id);

        $this->assertSame(PayoutStatus::Approved, $fresh->status);
        $this->assertNull($fresh->paid_at);
        $this->assertSame(0, DB::table('seller_ledger_entries')->where('type', 'payout')->count());
        $this->assertSame(
            0,
            DB::table('payout_allocations')->where('status', 'settled')->count(),
            'Allocations were settled by a rolled-back payout.',
        );

        $after = $this->positionOf($seller);

        $this->assertSame($before->availableMinor, $after->availableMinor);
        $this->assertSame($before->reservedMinor, $after->reservedMinor);
    }

    /**
     * A shipment that fails after its header leaves no orphan shipment.
     *
     * The header is written before the lines, so this is the case where a
     * shipment could exist with nothing in it — and a seller order would
     * then read as partially shipped on the strength of an empty parcel.
     */
    #[Test]
    public function a_shipment_that_fails_after_its_header_leaves_no_orphan(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 2]]);
        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->confirm($sellerOrder);

        $lines = [];

        foreach ($this->itemsOf($sellerOrder) as $item) {
            $lines[] = ['order_item_id' => (int) $item->id, 'quantity' => $item->quantity];
        }

        $statusBefore = $sellerOrder->refresh()->status->value;

        FailsAtQuery::containing('insert into "shipment_items"');

        $raised = false;

        try {
            $this->shipmentFor($sellerOrder, $lines);
        } catch (Throwable) {
            $raised = true;
        }

        $this->assertTrue($raised);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('shipment_items', 0);
        $this->assertSame($statusBefore, $sellerOrder->refresh()->status->value);
    }

    /**
     * The order is not marked paid by a transaction that then fails.
     *
     * Same boundary from the other side: the failure is injected into the
     * step that runs immediately after `MarkOrderPaid`, so the order is
     * paid in memory when the rollback happens.
     */
    #[Test]
    public function marking_an_order_paid_does_not_survive_a_later_failure(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        FailsAtQuery::containing('insert into "platform_revenue_entries"');

        $this->deliverStatus($reference);

        $this->assertDatabaseMissing('marketplace_orders', ['status' => 'paid']);
        $this->assertDatabaseMissing('seller_orders', ['status' => 'paid']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Actions\FinalizeRefund;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PaymentTransactionType;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\PaymentRefused;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PlatformRevenueEntry;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Models\RefundAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * Refunds, and the arithmetic that has to survive them.
 *
 * A refund is the one operation that takes money back out of the
 * platform's account, and the marketplace shape makes it harder than it
 * looks: one customer payment is several sellers' money, so returning it
 * means knowing whose share is being reversed and by exactly how much.
 * These tests hold that line — the reversal comes from the order item's
 * own snapshot, never from a commission rule that may have moved since.
 */
final class RefundTest extends TestCase
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
    public function a_full_refund_reverses_exactly_what_the_sale_recorded(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000, stock: 5);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        $refund = app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => $item->line_total_minor, 'quantity' => 2]],
            reason: 'Customer returned the item unopened.',
        );

        $this->assertSame(RefundStatus::Succeeded, $refund->refresh()->status);
        $this->assertSame(10_000, $refund->amount_minor);

        $allocation = RefundAllocation::query()->firstOrFail();

        // To the minor unit, from the snapshot — not recomputed.
        $this->assertSame($item->commission_amount_minor, $allocation->commission_reversed_minor);
        $this->assertSame($item->seller_earning_amount_minor, $allocation->earning_reversed_minor);
        $this->assertSame(
            $allocation->amount_minor,
            $allocation->commission_reversed_minor + $allocation->earning_reversed_minor,
            'The split is exact, which the database CHECK also enforces.',
        );
    }

    #[Test]
    public function the_original_ledger_entry_is_untouched_and_a_reversal_is_appended(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sale = SellerLedgerEntry::query()->withoutGlobalScopes()->firstOrFail();
        $saleAmount = $sale->amount_minor;

        $item = OrderItem::query()->firstOrFail();

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 4_000, 'quantity' => 1]],
            reason: 'Item arrived damaged in transit.',
        );

        $entries = SellerLedgerEntry::query()->withoutGlobalScopes()->orderBy('id')->get();

        $this->assertCount(2, $entries, 'A reversal is appended; nothing is edited.');
        $this->assertSame($saleAmount, $entries[0]?->amount_minor, 'The sale entry is exactly as it was.');

        $reversal = $entries[1];

        $this->assertNotNull($reversal);
        $this->assertSame(LedgerEntryType::RefundReversal, $reversal->type);
        $this->assertSame(-$item->seller_earning_amount_minor, $reversal->amount_minor);
        $this->assertSame($sale->id, $reversal->reverses_entry_id);
        $this->assertSame(0, $reversal->balance_after_minor, 'The running balance nets to nothing.');

        // §33: a reversal is money being taken back, not money to withdraw.
        $this->assertSame(LedgerEntryStatus::Pending, $reversal->status);
        $this->assertNull($reversal->available_at);
    }

    #[Test]
    public function the_commission_reversal_uses_the_rate_that_was_charged(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();
        $this->assertSame('12.00', $item->commission_rate_snapshot);
        $this->assertSame(1_200, $item->commission_amount_minor);

        // The platform moves its rate after the sale. The refund must not
        // notice: a purchase taken at 12% reverses twelve percent.
        CommissionRule::query()->update(['rate_percent' => '30.00']);

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 10_000, 'quantity' => 1]],
            reason: 'Order cancelled by the seller before dispatch.',
        );

        $reversal = PlatformRevenueEntry::query()
            ->where('type', PlatformRevenueEntry::TYPE_REVERSAL)
            ->firstOrFail();

        $this->assertSame(-1_200, $reversal->amount_minor);
        $this->assertSame('12.00', $reversal->rate_percent_snapshot);
        $this->assertSame(
            0,
            (int) PlatformRevenueEntry::query()->sum('amount_minor'),
            'Commission taken and commission returned net to nothing.',
        );
    }

    #[Test]
    public function a_partial_refund_splits_proportionally_and_leaves_the_rest_refundable(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000, stock: 5);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 5_000, 'quantity' => 1]],
            reason: 'One of the two arrived faulty.',
        );

        $allocation = RefundAllocation::query()->firstOrFail();

        // Half the line, so half the commission — the platform's share
        // rounded, the seller taking the remainder, which is the same rule
        // the original split used.
        $this->assertSame(600, $allocation->commission_reversed_minor);
        $this->assertSame(4_400, $allocation->earning_reversed_minor);
        $this->assertSame(5_000, $allocation->amount_minor);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(5_000, $payment->refunded_amount_minor);

        // The other half is still refundable, and is accepted.
        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 5_000, 'quantity' => 1]],
            reason: 'The replacement was faulty too.',
        );

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertSame(10_000, $payment->refunded_amount_minor);
    }

    #[Test]
    public function a_refund_cannot_exceed_what_was_captured(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 4_000, 'quantity' => 1]],
            reason: 'Returned in full by the customer.',
        );

        // §37: the second request sees the first one's claim, so two
        // admins cannot between them return more than arrived.
        $this->expectException(PaymentRefused::class);

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 1, 'quantity' => 0]],
            reason: 'A second attempt at the same money.',
        );
    }

    #[Test]
    public function a_refund_needs_a_reason_and_at_least_one_line(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        try {
            app(RequestRefund::class)(
                order: $order,
                lines: [['order_item_id' => $item->id, 'amount_minor' => 100]],
                reason: '   ',
            );

            $this->fail('A refund without a reason is an unexplained withdrawal.');
        } catch (PaymentRefused $refused) {
            $this->assertSame('reason_required', $refused->reason);
        }

        try {
            app(RequestRefund::class)($order, [], 'A perfectly good reason.');

            $this->fail('A refund must say which items it returns.');
        } catch (PaymentRefused $refused) {
            $this->assertSame('no_allocations', $refused->reason);
        }

        $this->assertSame(0, Refund::query()->count());
    }

    #[Test]
    public function an_item_from_another_order_cannot_be_refunded_against_this_payment(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 20);

        $mine = $this->placeOrder([[$offer, 1]]);
        $this->payFor($mine);

        $theirs = $this->placeOrder([[$offer, 1]]);
        $this->payFor($theirs);

        $theirItem = OrderItem::query()
            ->whereIn('seller_order_id', SellerOrder::query()
                ->withoutGlobalScopes()
                ->where('marketplace_order_id', $theirs->id)
                ->select('id'))
            ->firstOrFail();

        try {
            app(RequestRefund::class)(
                order: $mine,
                lines: [['order_item_id' => $theirItem->id, 'amount_minor' => 4_000]],
                reason: 'Refunding somebody else’s line.',
            );

            $this->fail('An item outside the order is not refundable against it.');
        } catch (PaymentRefused $refused) {
            $this->assertSame('item_not_in_order', $refused->reason);
        }
    }

    #[Test]
    public function one_idempotency_key_is_one_refund(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();
        $lines = [['order_item_id' => $item->id, 'amount_minor' => 2_000, 'quantity' => 0]];

        $first = app(RequestRefund::class)($order, $lines, 'Partial goodwill refund.', null, 'admin-double-click');
        $second = app(RequestRefund::class)($order, $lines, 'Partial goodwill refund.', null, 'admin-double-click');

        $this->assertSame($first->id, $second->id, 'A double-clicked button is one refund.');
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(2_000, (int) Payment::query()->firstOrFail()->refunded_amount_minor);
    }

    #[Test]
    public function a_provider_that_refuses_the_refund_reverses_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        // §44: reversing a seller's earning before the money leaves would
        // leave them short of funds that never went anywhere.
        $this->provider()->refundsResolveAs(RefundStatus::Failed);

        $refund = app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 4_000, 'quantity' => 1]],
            reason: 'Attempted refund the provider will refuse.',
        );

        $this->assertSame(RefundStatus::Failed, $refund->refresh()->status);

        $this->assertSame(
            1,
            SellerLedgerEntry::query()->withoutGlobalScopes()->count(),
            'The sale entry, and nothing beside it.',
        );

        $this->assertSame(0, PaymentTransaction::query()->where('type', PaymentTransactionType::Refund)->count());
        $this->assertSame(0, (int) Payment::query()->firstOrFail()->refunded_amount_minor);
        $this->assertSame(PaymentStatus::Captured, Payment::query()->firstOrFail()->status);
    }

    #[Test]
    public function a_replayed_refund_event_posts_one_reversal(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        $refund = app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $item->id, 'amount_minor' => 4_000, 'quantity' => 1]],
            reason: 'Refunded, then the provider told us four times.',
        );

        $reference = (string) $refund->provider_refund_reference;

        // Exactly what a provider retry looks like.
        app(FinalizeRefund::class)($reference);
        app(FinalizeRefund::class)($reference);
        app(FinalizeRefund::class)($reference);

        $this->assertSame(2, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            1,
            PlatformRevenueEntry::query()->where('type', PlatformRevenueEntry::TYPE_REVERSAL)->count(),
        );
        $this->assertSame(
            1,
            PaymentTransaction::query()->where('type', PaymentTransactionType::Refund)->count(),
        );
        $this->assertSame(4_000, (int) Payment::query()->firstOrFail()->refunded_amount_minor);
    }

    #[Test]
    public function each_seller_only_has_their_own_share_reversed(): void
    {
        ['offer' => $offerA, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 10_000, stock: 5);
        ['offer' => $offerB, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 6_000, stock: 5);

        $order = $this->placeOrder([[$offerA, 1], [$offerB, 1]]);
        $this->payFor($order);

        $itemA = OrderItem::query()->where('product_title', 'Kettle')->firstOrFail();

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => $itemA->id, 'amount_minor' => 10_000, 'quantity' => 1]],
            reason: 'Only the kettle came back.',
        );

        $reversals = SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('type', LedgerEntryType::RefundReversal->value)
            ->get();

        $this->assertCount(1, $reversals, 'One seller sold the returned item; only they are debited.');
        $this->assertSame($sellerA->id, $reversals[0]?->seller_account_id);

        $sellerBBalance = (int) SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('seller_account_id', $sellerB->id)
            ->sum('amount_minor');

        $this->assertSame(5_280, $sellerBBalance, 'The other seller keeps every cent of their sale.');
    }

    #[Test]
    public function the_admin_refund_route_requires_the_permission_and_a_reason(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();
        $body = [
            'reason' => 'Customer returned the item.',
            'lines' => [['order_item_id' => $item->id, 'amount_minor' => 4_000, 'quantity' => 1]],
        ];

        // Support can look a payment up and cannot give money back.
        $this->asAdmin($this->makeAdmin(AdminRole::Support))
            ->post("/admin/payments/{$order->reference}/refunds", $body)
            ->assertForbidden();

        $this->assertSame(0, Refund::query()->count());

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        // The reason is required by the server, not only by the dialog.
        $this->asAdmin($finance)
            ->post("/admin/payments/{$order->reference}/refunds", ['lines' => $body['lines']])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, Refund::query()->count());

        $this->asAdmin($finance)
            ->post("/admin/payments/{$order->reference}/refunds", $body)
            ->assertRedirect();

        $refund = Refund::query()->firstOrFail();

        $this->assertSame(4_000, $refund->amount_minor);
        $this->assertSame('Customer returned the item.', $refund->reason);
        $this->assertSame((int) $finance->id, $refund->requested_by_admin_id);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Actions\CompleteDeliveredSellerOrders;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payouts\Actions\ApprovePayout;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Queries\ReconcileSellerFinance;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * §42, §46 and §47 — a refund after the money has already gone.
 *
 * M7 is the first milestone where this can happen, and it is the one place
 * a marketplace is most tempted to cheat: the money left, the customer
 * wants it back, and the tidy-looking answer is to edit the payout or
 * delete the earning. Neither is done here. The payout stands, the earning
 * stands, a reversal is appended, and the seller's position goes below
 * zero — which is the truth.
 *
 * Everything below runs through the real chain: a customer pays, the
 * seller delivers, the clearing sweep releases the money, a payout is
 * requested, approved and settled, and then M5's refund path runs. No
 * ledger row is written by hand.
 */
final class PostPayoutRefundTest extends TestCase
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

    private function financeAdmin(): PayoutActor
    {
        return PayoutActor::admin(null, 'Finance');
    }

    /**
     * Pay, deliver, clear — the whole way to withdrawable money.
     *
     * @return array{seller: SellerAccount, order: MarketplaceOrder, item: OrderItem, earningMinor: int}
     */
    private function earnedAndCleared(int $priceMinor = 10_000): array
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: $priceMinor, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $earning = SellerLedgerEntry::query()->withoutGlobalScopes()
            ->where('seller_account_id', $seller->id)
            ->where('type', LedgerEntryType::SaleEarning->value)
            ->firstOrFail();

        $this->assertSame(LedgerEntryStatus::Available, $earning->status);

        $this->destination($seller);

        return [
            'seller' => $seller,
            'order' => $order->refresh(),
            'item' => OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail(),
            'earningMinor' => $earning->amount_minor,
        ];
    }

    #[Test]
    public function a_refund_after_a_settled_payout_leaves_both_records_and_a_negative_balance(): void
    {
        ['seller' => $seller, 'order' => $order, 'item' => $item, 'earningMinor' => $earned] =
            $this->earnedAndCleared();

        // 12% commission on $100 leaves the seller $88.
        $this->assertSame(8_800, $earned);

        $payout = $this->requestPayout($seller, $earned);
        app(ApprovePayout::class)($payout, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($payout, $this->financeAdmin(), 'wire', 'FT-100');

        $this->assertSame(0, $this->positionOf($seller)->netBalanceMinor());

        // The real refund path, M5's, on a fully delivered and paid order.
        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 10_000, 'quantity' => 1]],
            reason: 'Returned after delivery.',
        );

        $position = $this->positionOf($seller);

        // §42: the seller now owes the platform what it paid them.
        $this->assertSame(-8_800, $position->netBalanceMinor());
        $this->assertTrue($position->isNegative());
        $this->assertSame(8_800, $position->paidOutMinor);

        // Nothing was edited or deleted. Three rows: the earning, the
        // payout debit, and the reversal.
        $rows = SellerLedgerEntry::query()->withoutGlobalScopes()
            ->where('seller_account_id', $seller->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $rows);
        $this->assertSame(
            [
                [LedgerEntryType::SaleEarning->value, 8_800],
                [LedgerEntryType::Payout->value, -8_800],
                [LedgerEntryType::RefundReversal->value, -8_800],
            ],
            $rows->map(static fn (SellerLedgerEntry $row): array => [$row->type->value, $row->amount_minor])->all(),
        );

        // The payout is untouched and still says what it said.
        $settled = $payout->refresh();
        $this->assertSame(PayoutStatus::Paid, $settled->status);
        $this->assertSame(8_800, $settled->amount_minor);
        $this->assertSame('FT-100', $settled->settlement_ref);
    }

    #[Test]
    public function the_reversal_finds_its_earning_even_though_it_was_paid_out(): void
    {
        ['seller' => $seller, 'order' => $order, 'item' => $item, 'earningMinor' => $earned] =
            $this->earnedAndCleared();

        $payout = $this->requestPayout($seller, $earned);
        app(ApprovePayout::class)($payout, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($payout, $this->financeAdmin(), 'wire', 'FT-101');

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 10_000, 'quantity' => 1]],
            reason: 'Returned after payout.',
        );

        // §46: the reversal points back at the original earning rather
        // than failing because that earning is no longer spendable.
        $earning = SellerLedgerEntry::query()->withoutGlobalScopes()
            ->where('type', LedgerEntryType::SaleEarning->value)->firstOrFail();

        $reversal = SellerLedgerEntry::query()->withoutGlobalScopes()
            ->where('type', LedgerEntryType::RefundReversal->value)->firstOrFail();

        $this->assertSame((int) $earning->id, (int) $reversal->reverses_entry_id);
        $this->assertSame(LedgerEntryStatus::Available, $reversal->status);
    }

    #[Test]
    public function a_negative_seller_cannot_withdraw_and_a_later_sale_puts_them_right(): void
    {
        ['seller' => $seller, 'order' => $order, 'item' => $item, 'earningMinor' => $earned] =
            $this->earnedAndCleared();

        $payout = $this->requestPayout($seller, $earned);
        app(ApprovePayout::class)($payout, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($payout, $this->financeAdmin(), 'wire', 'FT-102');

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 10_000, 'quantity' => 1]],
            reason: 'Returned.',
        );

        // §67: no payout while the net position is not positive.
        try {
            $this->requestPayout($seller, 1_000);
            $this->fail('A store carrying a negative balance must not withdraw.');
        } catch (PayoutNotPermitted $refused) {
            $this->assertSame('negative_balance', $refused->reason);
        }

        // §66: a later sale offsets it, once it has cleared on its own.
        ['offer' => $second] = $this->sellableOffer(
            title: 'A second kettle',
            priceMinor: 30_000,
            stock: 5,
            seller: $seller,
        );

        $secondOrder = $this->placeOrder([[$second, 1]]);
        $this->payFor($secondOrder);

        $secondSellerOrder = $this->sellerOrderFor($secondOrder->id, (int) $seller->id);
        $this->deliver($this->shipEverything($secondSellerOrder));

        // Delivered but still clearing: the debt is offset in the net
        // position, and nothing is withdrawable yet.
        $clearing = $this->positionOf($seller);
        $this->assertSame(17_600, $clearing->netBalanceMinor());
        $this->assertSame(-8_800, $clearing->withdrawableMinor());

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $after = $this->positionOf($seller);
        $this->assertSame(17_600, $after->availableMinor);
        $this->assertSame(17_600, $after->withdrawableMinor());
        $this->assertFalse($after->isNegative());

        // And a payout is possible again.
        $this->assertSame(17_600, $this->requestPayout($seller, 17_600)->amount_minor);
    }

    #[Test]
    public function a_refund_against_one_seller_leaves_the_other_untouched(): void
    {
        // §47 — one marketplace order, two sellers, one of them paid out.
        ['offer' => $offerA, 'seller' => $sellerA] = $this->sellableOffer(
            title: 'Kettle from A', priceMinor: 10_000, stock: 5,
        );
        ['offer' => $offerB, 'seller' => $sellerB] = $this->sellableOffer(
            title: 'Toaster from B', priceMinor: 20_000, stock: 5,
        );

        $order = $this->placeOrder([[$offerA, 1], [$offerB, 1]]);
        $this->payFor($order);

        foreach ([$sellerA, $sellerB] as $seller) {
            $sellerOrder = $this->sellerOrderFor($order->id, (int) $seller->id);
            $this->deliver($this->shipEverything($sellerOrder));
        }

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->destination($sellerA);
        $this->destination($sellerB);

        $payout = $this->requestPayout($sellerA, 8_800);
        app(ApprovePayout::class)($payout, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($payout, $this->financeAdmin(), 'wire', 'FT-103');

        $bBefore = $this->positionOf($sellerB);

        $itemA = OrderItem::query()
            ->where('seller_order_id', $this->sellerOrderFor($order->id, (int) $sellerA->id)->id)
            ->firstOrFail();

        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $itemA->id, 'amount_minor' => 10_000, 'quantity' => 1]],
            reason: 'A only.',
        );

        $aAfter = $this->positionOf($sellerA);
        $bAfter = $this->positionOf($sellerB);

        $this->assertSame(-8_800, $aAfter->netBalanceMinor(), 'A carries the refund.');
        $this->assertSame(
            $bBefore->netBalanceMinor(),
            $bAfter->netBalanceMinor(),
            'B is untouched: 17,600 still theirs.',
        );
        $this->assertSame(17_600, $bAfter->withdrawableMinor());
        $this->assertSame(0, $bAfter->paidOutMinor);
        $this->assertSame(
            0,
            PayoutRequest::query()->withoutGlobalScopes()
                ->where('seller_account_id', $sellerB->id)->count(),
            'B has no payout history at all.',
        );
    }

    #[Test]
    public function the_whole_sequence_still_reconciles(): void
    {
        ['seller' => $seller, 'order' => $order, 'item' => $item, 'earningMinor' => $earned] =
            $this->earnedAndCleared();

        $payout = $this->requestPayout($seller, $earned);
        app(ApprovePayout::class)($payout, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($payout, $this->financeAdmin(), 'wire', 'FT-104');

        app(RequestRefund::class)(
            order: $order,
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 10_000, 'quantity' => 1]],
            reason: 'Returned.',
        );

        // §41 and §75: everything still adds up after the hardest case.
        $this->assertSame([], app(ReconcileSellerFinance::class)());
    }
}

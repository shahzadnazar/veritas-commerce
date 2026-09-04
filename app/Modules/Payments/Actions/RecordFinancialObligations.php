<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Ledger\Actions\PostLedgerEntry;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Models\PlatformRevenueEntry;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who is owed what, recorded from the order's own snapshots.
 *
 * Two rules, and both are the whole point.
 *
 * The figures are COPIED, never computed. §31 and §32: an order taken at
 * 12% commission that is paid for after the platform moved to 15% records
 * twelve percent, because that is what the seller agreed to and what the
 * customer was charged. Every number here comes from
 * `order_items.commission_amount_minor` and
 * `order_items.seller_earning_amount_minor`, written at placement and
 * guarded by an immutability rule on the model. No commission rule is
 * consulted at payment time — there is no code path from here to one.
 *
 * And the seller's money is NOT AVAILABLE. §30 is unambiguous: `available_at`
 * stays null, the entry status stays Pending, and it becomes withdrawable
 * only after delivery plus the clearing period, both of which belong to
 * later milestones. Setting `available_at = now()` here would let a seller
 * withdraw against an order that has not shipped — and the platform would
 * be funding the float on every refund.
 *
 * Posting is exactly-once by unique index on `source_key`, not by a check
 * that races: a retried job finds the row already there.
 */
final class RecordFinancialObligations
{
    public function __construct(private readonly PostLedgerEntry $postEntry) {}

    /**
     * @return array{seller_entries: int, revenue_entries: int}
     */
    public function __invoke(MarketplaceOrder $order): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, SellerOrder> $sellerOrders */
        $sellerOrders = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->orderBy('position')
            ->get();

        $items = OrderItem::query()
            ->whereIn('seller_order_id', $sellerOrders->modelKeys())
            ->orderBy('id')
            ->get()
            ->groupBy('seller_order_id');

        // Loaded once rather than per item: the ledger action needs the
        // account to resolve its own clearing policy.
        $sellers = SellerAccount::query()
            ->whereIn('id', $sellerOrders->pluck('seller_account_id')->unique())
            ->get()
            ->keyBy('id');

        $sellerEntries = 0;
        $revenueEntries = 0;

        foreach ($sellerOrders as $sellerOrder) {
            /** @var Collection<int, OrderItem> $lines */
            $lines = $items->get($sellerOrder->id) ?? collect();

            $seller = $sellers->get($sellerOrder->seller_account_id);

            if ($seller === null) {
                continue;
            }

            foreach ($lines as $item) {
                $sellerEntries += $this->postSellerEarning($order, $sellerOrder, $item, $seller);
                $revenueEntries += $this->postCommission($order, $sellerOrder, $item);
            }
        }

        return ['seller_entries' => $sellerEntries, 'revenue_entries' => $revenueEntries];
    }

    /**
     * The seller's side: pending, and not available.
     *
     * The running balance column the ledger keeps is deliberately computed
     * from the seller's own prior entries under a lock, so two orders
     * finalizing at once cannot both read the same "balance before".
     */
    private function postSellerEarning(
        MarketplaceOrder $order,
        SellerOrder $sellerOrder,
        OrderItem $item,
        SellerAccount $seller,
    ): int {
        $key = "sale:{$item->id}";

        $alreadyPosted = SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('source_key', $key)
            ->exists();

        /*
         * Through the ledger's own action, which is the only thing allowed
         * to write a ledger row — it holds the running-balance lock and the
         * clearing policy, and a second implementation of either here would
         * be a second answer to "what is this seller owed".
         */
        ($this->postEntry)(
            seller: $seller,
            type: LedgerEntryType::SaleEarning,
            // The item's snapshot. Not a recomputation from any rule.
            amountMinor: $item->seller_earning_amount_minor,
            // Pending, so no clearing clock starts: the goods have not
            // shipped and this money is not withdrawable (§30).
            status: LedgerEntryStatus::Pending,
            sellerOrderId: $sellerOrder->id,
            orderItemId: $item->id,
            note: "Sale on {$order->reference}",
            currency: $item->currency,
            sourceKey: $key,
        );

        return $alreadyPosted ? 0 : 1;
    }

    /** The platform's side, from the same snapshot. */
    private function postCommission(MarketplaceOrder $order, SellerOrder $sellerOrder, OrderItem $item): int
    {
        $key = "commission:{$item->id}";

        if (PlatformRevenueEntry::query()->where('source_key', $key)->exists()) {
            return 0;
        }

        return PlatformRevenueEntry::query()->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'marketplace_order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'order_item_id' => $item->id,
            'seller_account_id' => $sellerOrder->seller_account_id,
            'type' => PlatformRevenueEntry::TYPE_COMMISSION,
            'currency' => $item->currency,
            'amount_minor' => $item->commission_amount_minor,
            // Recorded so a report can show the rate that applied without
            // joining back to a rule that has since changed.
            'rate_percent_snapshot' => $item->commission_rate_snapshot,
            'source_key' => $key,
            'created_at' => now(),
        ]);
    }

    /**
     * What has been posted for an order, for reconciliation.
     *
     * @return array{seller_minor: int, commission_minor: int}
     */
    public static function postedTotals(MarketplaceOrder $order): array
    {
        $sellerOrderIds = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->pluck('id');

        return [
            'seller_minor' => (int) DB::table('seller_ledger_entries')
                ->whereIn('seller_order_id', $sellerOrderIds)
                ->sum('amount_minor'),
            'commission_minor' => (int) DB::table('platform_revenue_entries')
                ->where('marketplace_order_id', $order->id)
                ->sum('amount_minor'),
        ];
    }
}

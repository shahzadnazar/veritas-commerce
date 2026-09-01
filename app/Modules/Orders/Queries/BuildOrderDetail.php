<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * One placed order, read entirely from its own snapshots.
 *
 * §27 is the rule this class exists to keep: an order describes itself.
 * Not one value here is read through a relationship to a live offer, a
 * current product title or today's store name. A seller who renames their
 * shop, retitles a listing or raises a price must not be able to change
 * what a customer's receipt says, and the only way to be sure of that is
 * for the read path to have no route to those tables at all.
 *
 * Three queries for an order of any size: the parent, its seller orders,
 * and every item across them in one go. No N+1 over sellers or lines, and
 * no eager-loading of the catalogue graph to avoid one.
 */
final class BuildOrderDetail
{
    /**
     * @param  bool  $withFinance  whether the reader may see commission and earnings
     * @return array<string, mixed>
     */
    public function __invoke(MarketplaceOrder $order, bool $withFinance = false): array
    {
        /** @var Collection<int, SellerOrder> $sellerOrders */
        $sellerOrders = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->orderBy('position')
            ->get();

        $itemsByOrder = OrderItem::query()
            ->whereIn('seller_order_id', $sellerOrders->modelKeys())
            ->orderBy('id')
            ->get()
            ->groupBy('seller_order_id');

        return [
            ...$this->parent($order),
            'sellerOrders' => $sellerOrders->map(
                fn (SellerOrder $sellerOrder): array => $this->sellerOrder(
                    $sellerOrder,
                    $itemsByOrder->get($sellerOrder->id)?->all() ?? [],
                    $withFinance,
                ),
            )->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function parent(MarketplaceOrder $order): array
    {
        return [
            'reference' => $order->reference,
            'status' => $order->status->value,
            'currency' => $order->currency,
            'placedAt' => $order->placed_at?->toIso8601String(),
            'cancelledAt' => $order->cancelled_at?->toIso8601String(),
            'paymentExpiresAt' => $order->payment_expires_at?->toIso8601String(),
            'email' => $order->email,
            'itemsTotal' => $this->money($order->items_total_minor, $order->currency),
            'shippingTotal' => $this->money($order->shipping_total_minor, $order->currency),
            'taxTotal' => $this->money($order->tax_total_minor, $order->currency),
            'discountTotal' => $this->money($order->discount_total_minor, $order->currency),
            'grandTotal' => $this->money($order->grand_total_minor, $order->currency),
            // The address the order was placed with, not the one in the
            // customer's address book today.
            'shippingAddress' => [
                'name' => $order->ship_name,
                'line1' => $order->ship_line1,
                'line2' => $order->ship_line2,
                'city' => $order->ship_city,
                'state' => $order->ship_state,
                'postcode' => $order->ship_postcode,
                'country' => $order->ship_country,
                'phone' => $order->ship_phone,
            ],
        ];
    }

    /**
     * @param  array<int, OrderItem>  $items
     * @return array<string, mixed>
     */
    public function sellerOrder(SellerOrder $sellerOrder, array $items, bool $withFinance): array
    {
        $currency = $sellerOrder->currency;

        $row = [
            'reference' => $sellerOrder->reference,
            'position' => $sellerOrder->position,
            'status' => $sellerOrder->status->value,
            'currency' => $currency,
            // The store's name as it was, carried on the items rather than
            // read from the store today.
            'storeName' => $items[0]->store_name_snapshot ?? null,
            'itemsTotal' => $this->money($sellerOrder->items_total_minor, $currency),
            'shippingTotal' => $this->money($sellerOrder->shipping_total_minor, $currency),
            'orderTotal' => $this->money($sellerOrder->order_total_minor, $currency),
            'itemCount' => count($items),
            'quantity' => array_sum(array_map(static fn (OrderItem $i): int => $i->quantity, $items)),
            'items' => array_map(
                fn (OrderItem $item): array => $this->item($item, $withFinance),
                array_values($items),
            ),
        ];

        if ($withFinance) {
            $row['commissionTotal'] = $this->money($sellerOrder->commission_total_minor, $currency);
            $row['sellerEarningTotal'] = $this->money($sellerOrder->seller_earning_total_minor, $currency);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function item(OrderItem $item, bool $withFinance): array
    {
        $currency = $item->currency;

        $row = [
            'publicId' => $item->public_id,
            'productTitle' => $item->product_title,
            'brand' => $item->brand_name_snapshot,
            'storeName' => $item->store_name_snapshot,
            'productSlug' => $item->product_slug_snapshot,
            'variantName' => $item->variant_name,
            'sellerSku' => $item->seller_sku,
            'quantity' => $item->quantity,
            'unitPrice' => $this->money($item->unit_price_snapshot_minor, $currency),
            'lineTotal' => $this->money($item->line_total_minor, $currency),
        ];

        if ($withFinance) {
            $row['commissionRate'] = $item->commission_rate_snapshot;
            $row['commission'] = $this->money($item->commission_amount_minor, $currency);
            $row['sellerEarning'] = $this->money($item->seller_earning_amount_minor, $currency);
        }

        return $row;
    }

    /**
     * Money, twice: formatted for a person and in minor units for a test.
     *
     * A test that asserted on "$12.00" would break the day the format
     * changes and pass the day the arithmetic does.
     *
     * @return array{formatted: string, minor: int}
     */
    private function money(int $minor, string $currency): array
    {
        return ['formatted' => Money::of($minor, $currency)->format(), 'minor' => $minor];
    }
}

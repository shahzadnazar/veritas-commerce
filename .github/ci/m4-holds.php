<?php

declare(strict_types=1);

/*
 * What the marketplace's stock looks like with an order awaiting payment.
 *
 * Scoped to the offer the order actually contains rather than to whichever
 * balance row happens to be newest — the demo catalogue seeds balances too,
 * and a smoke that read the wrong row would pass while the real one was
 * wrong.
 */

use App\Modules\Orders\Models\MarketplaceOrder;
use Illuminate\Support\Facades\DB;

$order = MarketplaceOrder::query()->latest('id')->firstOrFail();

$offerId = (int) DB::table('order_items')
    ->whereIn(
        'seller_order_id',
        DB::table('seller_orders')->where('marketplace_order_id', $order->id)->select('id'),
    )
    ->value('offer_id');

$balance = DB::table('inventory_balances')->where('offer_id', $offerId)->first();

printf(
    "holds on_hand=%d reserved=%d available=%d status=%s\n",
    $balance->on_hand ?? -1,
    $balance->reserved ?? -1,
    $balance->available ?? -1,
    $order->status->value,
);

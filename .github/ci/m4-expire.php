<?php

declare(strict_types=1);

/*
 * The unpaid-order sweep, against real order data in the built image.
 *
 * Runs every sweep twice on purpose. These are queued jobs, queued jobs
 * are retried, and a release that restored stock twice would invent
 * inventory out of a retry — so "once" is the whole assertion.
 */

use App\Modules\Checkout\Jobs\ExpireCheckoutAttempts;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Jobs\ExpireReservations;
use App\Modules\Orders\Actions\CancelUnpaidOrder;
use App\Modules\Orders\Jobs\ExpireUnpaidOrders;
use App\Modules\Orders\Models\MarketplaceOrder;
use Illuminate\Support\Facades\DB;

$order = MarketplaceOrder::query()->latest('id')->firstOrFail();
$offerId = (int) DB::table('order_items')
    ->whereIn('seller_order_id', DB::table('seller_orders')->where('marketplace_order_id', $order->id)->select('id'))
    ->value('offer_id');

DB::table('marketplace_orders')->where('id', $order->id)->update(['payment_expires_at' => now()->subMinute()]);
DB::table('checkout_attempts')->update(['expires_at' => now()->subMinute()]);
DB::table('inventory_reservations')->update(['expires_at' => now()->subMinute()]);

for ($run = 1; $run <= 2; $run++) {
    app(ExpireUnpaidOrders::class)->handle(app(CancelUnpaidOrder::class));
    app(ExpireCheckoutAttempts::class)->handle(app(ReleaseReservation::class));
    app(ExpireReservations::class)->handle(app(ReleaseReservation::class));
}

$balance = DB::table('inventory_balances')->where('offer_id', $offerId)->first();

echo 'order='.DB::table('marketplace_orders')->where('id', $order->id)->value('status'), PHP_EOL;
echo 'seller='.DB::table('seller_orders')->where('marketplace_order_id', $order->id)->value('status'), PHP_EOL;
echo 'on_hand='.($balance->on_hand ?? -1), PHP_EOL;
echo 'reserved='.($balance->reserved ?? -1), PHP_EOL;
echo 'available='.($balance->available ?? -1), PHP_EOL;
echo 'releases='.DB::table('inventory_movements')
    ->whereIn('reason', ['reservation_release', 'reservation_expired'])->count(), PHP_EOL;
echo 'sales='.DB::table('inventory_movements')->where('reason', 'sale_completed')->count(), PHP_EOL;
echo 'ledger='.DB::table('seller_ledger_entries')->count(), PHP_EOL;

<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\SellerOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An order that was never paid for gives its stock back.
 *
 * §29. Reserving on order creation is what stops two customers buying the
 * same last unit; never releasing it is what would make a marketplace's
 * inventory slowly disappear into abandoned checkouts. The payment window
 * is the promise, and this is what keeps it.
 *
 * IDEMPOTENT throughout, because a sweep is queued and jobs are retried:
 * the order is locked and re-read, an order that is no longer pending
 * payment is left alone, and the release itself only claims holds still
 * `held`. Two workers racing the same order produce one cancellation.
 *
 * The whole parent goes together. A marketplace order half cancelled is
 * not a state a customer, a seller or a payment provider can make sense
 * of, and the payment is taken once for the whole thing.
 */
final class CancelUnpaidOrder
{
    public function __construct(private readonly ReleaseReservation $release) {}

    /**
     * @param  string  $reason  recorded on the history rows
     * @return bool whether this call was the one that cancelled it
     */
    public function __invoke(MarketplaceOrder $order, string $reason = 'Payment window closed.'): bool
    {
        return DB::transaction(function () use ($order, $reason): bool {
            /** @var MarketplaceOrder|null $locked */
            $locked = MarketplaceOrder::query()
                ->whereKey($order->getKey())
                ->where('status', MarketplaceOrderStatus::PendingPayment->value)
                ->lockForUpdate()
                ->first();

            // Paid, or already cancelled by a competing sweep.
            if ($locked === null) {
                return false;
            }

            /*
             * Released before the status moves, inside the same
             * transaction. If the release fails the cancellation rolls
             * back with it, so an order can never be marked cancelled
             * while still holding a seller's units.
             */
            if ($locked->reservation_reference !== null) {
                ($this->release)($locked->reservation_reference);
            }

            /** @var Collection<int, SellerOrder> $sellerOrders */
            $sellerOrders = SellerOrder::query()
                ->where('marketplace_order_id', $locked->id)
                ->where('status', SellerOrderStatus::PendingPayment->value)
                ->lockForUpdate()
                ->get();

            foreach ($sellerOrders as $sellerOrder) {
                $sellerOrder->forceFill([
                    'status' => SellerOrderStatus::Cancelled->value,
                    'cancelled_at' => now(),
                ])->save();

                OrderStatusHistory::query()->create([
                    'seller_order_id' => $sellerOrder->id,
                    'from_status' => SellerOrderStatus::PendingPayment->value,
                    'to_status' => SellerOrderStatus::Cancelled->value,
                    'actor_type' => 'system',
                    'note' => $reason,
                    'created_at' => now(),
                ]);
            }

            $locked->forceFill([
                'status' => MarketplaceOrderStatus::Cancelled->value,
                'cancelled_at' => now(),
            ])->save();

            OrderStatusHistory::query()->create([
                'marketplace_order_id' => $locked->id,
                'from_status' => MarketplaceOrderStatus::PendingPayment->value,
                'to_status' => MarketplaceOrderStatus::Cancelled->value,
                'actor_type' => 'system',
                'note' => $reason,
                'created_at' => now(),
            ]);

            return true;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Inventory\Actions\ConsumeReservation;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\SellerOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The moment a hold becomes a sale.
 *
 * §30's boundary, drawn now so M5 has somewhere to land: the payment
 * provider's confirmation calls this and nothing else. Everything before
 * it holds stock; everything after it has sold it. There is no third state
 * where units are half gone.
 *
 * The consumption is one movement per line that drops on_hand and reserved
 * together, so availability cannot flicker upward between two writes and
 * let a concurrent reservation take stock that is already sold.
 *
 * IDEMPOTENT: a provider that delivers its webhook twice — which every
 * provider eventually does — must not sell the same units twice. The order
 * is locked and re-read, and the consumption only claims holds still
 * `held`.
 */
final class MarkOrderPaid
{
    public function __construct(private readonly ConsumeReservation $consume) {}

    /** @return bool whether this call was the one that marked it paid */
    public function __invoke(MarketplaceOrder $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            /** @var MarketplaceOrder|null $locked */
            $locked = MarketplaceOrder::query()
                ->whereKey($order->getKey())
                ->where('status', MarketplaceOrderStatus::PendingPayment->value)
                ->lockForUpdate()
                ->first();

            // Already paid, or cancelled while the webhook was in flight.
            if ($locked === null) {
                return false;
            }

            /** @var Collection<int, SellerOrder> $sellerOrders */
            $sellerOrders = SellerOrder::query()
                ->where('marketplace_order_id', $locked->id)
                ->lockForUpdate()
                ->get();

            if ($locked->reservation_reference !== null) {
                // Attributed line by line: the holds share one reference
                // but the sales belong to different sellers, and a
                // movement filed against the wrong one misattributes the
                // sale everywhere downstream.
                ($this->consume)->attributed(
                    $locked->reservation_reference,
                    $this->sellerOrderIdByOfferId($sellerOrders),
                );
            }

            foreach ($sellerOrders as $sellerOrder) {
                if ($sellerOrder->status !== SellerOrderStatus::PendingPayment) {
                    continue;
                }

                $sellerOrder->forceFill(['status' => SellerOrderStatus::Paid->value])->save();

                OrderStatusHistory::query()->create([
                    'seller_order_id' => $sellerOrder->id,
                    'from_status' => SellerOrderStatus::PendingPayment->value,
                    'to_status' => SellerOrderStatus::Paid->value,
                    'actor_type' => 'system',
                    'note' => 'Payment captured.',
                    'created_at' => now(),
                ]);
            }

            $locked->forceFill([
                'status' => MarketplaceOrderStatus::Paid->value,
                // The window is over: nothing sweeps a paid order.
                'payment_expires_at' => null,
            ])->save();

            OrderStatusHistory::query()->create([
                'marketplace_order_id' => $locked->id,
                'from_status' => MarketplaceOrderStatus::PendingPayment->value,
                'to_status' => MarketplaceOrderStatus::Paid->value,
                'actor_type' => 'system',
                'note' => 'Payment captured.',
                'created_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * @param  Collection<int, SellerOrder>  $sellerOrders
     * @return array<int, int>
     */
    private function sellerOrderIdByOfferId($sellerOrders): array
    {
        /** @var array<int, int> $map */
        $map = DB::table('order_items')
            ->whereIn('seller_order_id', $sellerOrders->modelKeys())
            ->whereNotNull('offer_id')
            ->pluck('seller_order_id', 'offer_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $map;
    }
}

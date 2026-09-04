<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Notifications\OrderPaidNotification;
use App\Modules\Payments\Notifications\SellerOrderPaidNotification;
use App\Modules\Sellers\Enums\SellerPermission;
use App\Modules\Sellers\Models\SellerMembership;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * The one place a paid order is announced to the people in it.
 *
 * §26 and §27, and the two halves are different promises:
 *
 *  - The customer gets one confirmation. Providers retry, and a webhook
 *    delivered eight times must not send eight receipts. The guard is not
 *    in here: FinalizePayment transitions the attempt to Succeeded under a
 *    row lock and returns early on every later delivery, so PaymentSucceeded
 *    is dispatched once per payment no matter how often the event arrives.
 *    That is a database-level guarantee, which an "already notified?" check
 *    in a listener would not be.
 *
 *  - Each seller gets exactly their own order. A customer buying from three
 *    sellers produces three seller orders, and each seller sees the one
 *    that is theirs — their items, their total, never the customer's basket
 *    across the marketplace.
 *
 * Runs after the payment transaction has committed (see FinalizePayment),
 * so nothing here can describe a payment the database does not hold.
 */
final class AnnouncePaidOrder implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Never on the payments queue, and never in the webhook request.
     *
     * §65: a mail provider timing out must not fail — or slow — the
     * request that told the platform money arrived. Queued also means
     * retried: a listener that throws is retried on its own, rather than
     * 500ing a webhook whose payment has already been finalized and will
     * therefore never be redelivered to any effect.
     */
    public string $queue = Queues::EMAILS;

    public int $tries = 5;

    public function handle(PaymentSucceeded $event): void
    {
        /** @var MarketplaceOrder|null $order */
        $order = MarketplaceOrder::query()->find($event->marketplaceOrderId);

        if ($order === null) {
            return;
        }

        /** @var Collection<int, SellerOrder> $sellerOrders */
        $sellerOrders = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->with(['items', 'sellerAccount.memberships.user'])
            ->orderBy('position')
            ->get();

        $this->tellCustomer($order, $sellerOrders);

        foreach ($sellerOrders as $sellerOrder) {
            $this->tellSeller($sellerOrder);
        }
    }

    /**
     * @param  Collection<int, SellerOrder>  $sellerOrders
     */
    private function tellCustomer(MarketplaceOrder $order, Collection $sellerOrders): void
    {
        $items = [];

        foreach ($sellerOrders as $sellerOrder) {
            foreach ($sellerOrder->items as $item) {
                $items[] = [
                    'title' => $item->product_title,
                    'quantity' => $item->quantity,
                    'total' => $item->lineTotal()->format(),
                ];
            }
        }

        $notification = new OrderPaidNotification(
            orderReference: $order->reference,
            total: $order->grandTotal(),
            items: $items,
            sellerCount: $sellerOrders->count(),
        );

        /*
         * A signed-in customer is notified as themselves; a guest is
         * notified at the address they gave at checkout. Both bought
         * something, so both get the receipt.
         */
        $user = $order->user;

        if ($user !== null) {
            $user->notify($notification);

            return;
        }

        Notification::route('mail', $order->email)->notify($notification);
    }

    /**
     * Addressed to the members who can act on an order.
     *
     * Membership rather than a single address on the account, matching
     * every other seller notification: the person who can pack and ship is
     * the person who needs to know, and a finance-only member is not it.
     */
    private function tellSeller(SellerOrder $sellerOrder): void
    {
        $seller = $sellerOrder->sellerAccount;

        if ($seller === null) {
            return;
        }

        $notification = new SellerOrderPaidNotification(
            sellerOrderReference: $sellerOrder->reference,
            orderTotal: $sellerOrder->orderTotal(),
            itemCount: $sellerOrder->items->sum(static fn (OrderItem $item): int => $item->quantity),
        );

        $recipients = $seller->memberships->filter(
            static fn (SellerMembership $membership): bool => $membership->accepted_at !== null
                && $membership->role->can(SellerPermission::OrdersView)
        );

        foreach ($recipients as $membership) {
            $membership->user?->notify(clone $notification);
        }
    }
}

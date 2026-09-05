<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Events\ShipmentDelivered;
use App\Modules\Orders\Events\ShipmentShipped;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Notifications\ShipmentDeliveredNotification;
use App\Modules\Orders\Notifications\ShipmentShippedNotification;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Telling the customer where their parcel is.
 *
 * Sent per parcel, because that is what happened: an order going out in
 * two boxes produces two messages, and one covering both would be wrong
 * about the first while the second was still being packed.
 *
 * Exactly once, and the guarantee is not a flag in here. MarkShipmentShipped
 * and MarkShipmentDelivered both refuse a parcel that has already moved,
 * under a row lock, so the event fires once however many times the button
 * is pressed or the job retried.
 *
 * Queued on the emails lane rather than run inline, for the reason every
 * other announcement is: a mail provider timing out must not fail the
 * request that recorded a dispatch, and a listener that throws should be
 * retried on its own rather than rolling back a shipment.
 *
 * Nothing listens for SellerOrderDelivered here. That event starts the
 * earnings clock — a financial fact with no customer-facing message of its
 * own, because the customer was already told about every parcel that
 * arrived.
 */
final class AnnounceFulfilment implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = Queues::EMAILS;

    public int $tries = 5;

    public function shipped(ShipmentShipped $event): void
    {
        $this->notifyCustomer(
            $event->customerUserId,
            $event->customerEmail,
            new ShipmentShippedNotification(
                orderReference: $event->orderReference,
                storeName: $event->storeName,
                carrierName: $event->carrierName,
                trackingNumber: $event->trackingNumber,
                trackingUrl: $event->trackingUrl,
                items: $event->items,
            ),
        );
    }

    public function delivered(ShipmentDelivered $event): void
    {
        /*
         * Whether this parcel finished the whole order changes what the
         * customer is told, and it is read here rather than carried on the
         * event because the seller order's state is decided after the
         * parcel's — one box arriving out of three is not an order
         * arriving.
         */
        $sellerOrder = SellerOrder::query()
            ->withoutGlobalScopes()
            ->whereKey($event->sellerOrderId)
            ->first();

        $this->notifyCustomer(
            $event->customerUserId,
            $event->customerEmail,
            new ShipmentDeliveredNotification(
                orderReference: $event->orderReference,
                storeName: $event->storeName,
                completesTheOrder: $this->everySellerDelivered($sellerOrder),
            ),
        );
    }

    /**
     * A seller order finishing is not the marketplace order finishing.
     *
     * §40's rule applied to the wording as well as the status: a customer
     * who bought from three sellers must not be told their order arrived
     * because one of them delivered.
     */
    private function everySellerDelivered(?SellerOrder $sellerOrder): bool
    {
        if ($sellerOrder === null || ! $sellerOrder->status->isFullyDelivered()) {
            return false;
        }

        return ! SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $sellerOrder->marketplace_order_id)
            ->whereNotIn('status', [
                SellerOrderStatus::Delivered->value,
                SellerOrderStatus::Completed->value,
                SellerOrderStatus::Cancelled->value,
                SellerOrderStatus::Refunded->value,
            ])
            ->exists();
    }

    /** A signed-in customer as themselves; a guest at the address they gave. */
    private function notifyCustomer(?int $userId, string $email, BaseNotification $notification): void
    {
        if ($userId !== null) {
            $user = User::query()->find($userId);

            if ($user !== null) {
                $user->notify($notification);

                return;
            }
        }

        if ($email !== '') {
            Notification::route('mail', $email)->notify($notification);
        }
    }
}

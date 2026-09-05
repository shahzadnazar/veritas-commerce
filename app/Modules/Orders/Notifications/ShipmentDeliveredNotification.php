<?php

declare(strict_types=1);

namespace App\Modules\Orders\Notifications;

use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "It arrived."
 *
 * Worth sending because it is the customer's cue to check the parcel while
 * a problem is still easy to resolve — and because in Phase 1 delivery is
 * recorded by a person, so the customer is the one who can contradict it.
 * The wording says who recorded it rather than implying the marketplace
 * watched it happen.
 */
final class ShipmentDeliveredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $orderReference,
        public readonly string $storeName,
        public readonly bool $completesTheOrder,
    ) {
        $this->onQueue(Queues::EMAILS);
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(
                $this->completesTheOrder
                    ? "Order {$this->orderReference} has arrived"
                    : "Part of order {$this->orderReference} has arrived",
            )
            ->greeting('Delivered.')
            ->line(
                $this->completesTheOrder
                    ? "Everything on order {$this->orderReference} has now been delivered."
                    : "{$this->storeName} has delivered their part of order {$this->orderReference}. "
                        .'Other sellers on this order are still on their way.',
            )
            ->line('This delivery was recorded by the seller — we do not yet receive updates from '
                .'couriers directly. If it has not arrived, tell us and we will look into it.')
            ->action('View your order', url("/account/orders/{$this->orderReference}"));
    }
}

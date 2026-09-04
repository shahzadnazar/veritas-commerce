<?php

declare(strict_types=1);

namespace App\Modules\Payments\Notifications;

use App\Support\Money;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A seller's new order, sent only after the customer's payment cleared.
 *
 * §27 draws this line and it is a commercial one, not a technical one: a
 * seller told to pack an order that was never paid for either ships goods
 * for nothing or learns to distrust the notification. So nothing reaches
 * them until the money is verified.
 *
 * Scoped to one seller order. §27's other half — a seller sees their own
 * items and their own money, never another seller's share of the same
 * customer purchase.
 */
final class SellerOrderPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $sellerOrderReference,
        public readonly Money $orderTotal,
        public readonly int $itemCount,
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
            ->subject("New paid order {$this->sellerOrderReference}")
            ->greeting('You have a new order.')
            ->line("Order {$this->sellerOrderReference} has been paid for and is ready to prepare.")
            ->line($this->itemCount === 1 ? '1 item.' : "{$this->itemCount} items.")
            ->line("Order value: {$this->orderTotal->format()}")
            ->action('Open the order', url("/seller/orders/{$this->sellerOrderReference}"))
            ->line('Your earnings from this order become available for payout after delivery and the clearing period.');
    }
}

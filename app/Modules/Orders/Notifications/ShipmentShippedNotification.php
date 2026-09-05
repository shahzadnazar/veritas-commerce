<?php

declare(strict_types=1);

namespace App\Modules\Orders\Notifications;

use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your parcel is on its way", with the number to check it.
 *
 * Sent per parcel rather than per order, because that is what actually
 * happened: an order going out in two boxes produces two of these, and one
 * message covering both would be wrong about the first the moment the
 * second was still being packed.
 *
 * The tracking link is only included when the platform generated it. A
 * seller-supplied URL in an email the marketplace sends is a link the
 * marketplace is vouching for, and "it was in the tracking field" is not a
 * defence.
 */
final class ShipmentShippedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<int, array{title: string, quantity: int}> $items */
    public function __construct(
        public readonly string $orderReference,
        public readonly string $storeName,
        public readonly ?string $carrierName,
        public readonly ?string $trackingNumber,
        public readonly ?string $trackingUrl,
        public readonly array $items,
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
        $message = (new MailMessage)
            ->subject("Your {$this->storeName} items have been sent")
            ->greeting('On its way.')
            ->line("{$this->storeName} has sent part of order {$this->orderReference}.");

        foreach ($this->items as $item) {
            $message->line("• {$item['quantity']} × {$item['title']}");
        }

        if ($this->carrierName !== null && $this->trackingNumber !== null) {
            $message->line("{$this->carrierName} — {$this->trackingNumber}");
        }

        if ($this->trackingUrl !== null) {
            $message->action('Track this parcel', $this->trackingUrl);
        }

        return $message
            ->line('You can see every part of this order, and its tracking, in your account.')
            ->action('View your order', url("/account/orders/{$this->orderReference}"));
    }
}

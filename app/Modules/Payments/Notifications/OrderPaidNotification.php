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
 * The customer's receipt, sent only after a verified payment.
 *
 * §26 and §13: this is the first message in the whole system that says
 * money arrived, so it is queued from the post-commit event and from
 * nowhere else. A confirmation sent when the browser redirected would be a
 * claim the platform could not support — and the one a customer quotes
 * back when their card was declined and their goods never came.
 *
 * Carries references and amounts. No provider identifiers, no client
 * secret, no payment method beyond a description the provider itself
 * considers safe to show.
 */
final class OrderPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<int, array{title: string, quantity: int, total: string}> $items */
    public function __construct(
        public readonly string $orderReference,
        public readonly Money $total,
        public readonly array $items,
        public readonly int $sellerCount,
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
        $platform = (string) config('veritas.identity.display_name');

        $message = (new MailMessage)
            ->subject("Your {$platform} order {$this->orderReference} is confirmed")
            ->greeting('Thank you — your payment went through.')
            ->line("Order {$this->orderReference} is paid and has gone to ".
                ($this->sellerCount === 1 ? 'the seller' : "{$this->sellerCount} sellers").
                ' to prepare.');

        foreach ($this->items as $item) {
            $message->line("• {$item['quantity']} × {$item['title']} — {$item['total']}");
        }

        return $message
            ->line("Total paid: {$this->total->format()}")
            ->action('View your order', url("/account/orders/{$this->orderReference}"))
            ->line('You will hear from each seller when your items are on their way.');
    }
}

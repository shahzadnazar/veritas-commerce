<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Notifications;

use App\Modules\Inventory\Enums\StockState;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a seller a listing is running out, or has.
 *
 * Says which listing, how many are left and what happens next, because a
 * mail that only says "low stock" makes the seller open the portal to find
 * out which of four hundred listings it meant.
 */
final class StockLevelChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $productTitle,
        public readonly ?string $sku,
        public readonly StockState $state,
        public readonly int $available,
    ) {
        // Mail has its own workers, so a slow provider delays nothing else.
        $this->onQueue(Queues::EMAILS);
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $listing = $this->sku === null
            ? $this->productTitle
            : "{$this->productTitle} ({$this->sku})";

        if ($this->state === StockState::OutOfStock) {
            return (new MailMessage)
                ->subject("{$this->productTitle} has sold out")
                ->greeting('A listing has run out')
                ->line("{$listing} has no units left, so customers can no longer buy it.")
                ->line('The listing itself is untouched — it stays on the product page and starts selling again the moment you add stock.')
                ->action('Update your stock', url('/seller/inventory'));
        }

        return (new MailMessage)
            ->subject("{$this->productTitle} is running low")
            ->greeting('A listing is running low')
            ->line("{$listing} has {$this->available} left, which is at or below the threshold you set.")
            ->action('Update your stock', url('/seller/inventory'));
    }
}

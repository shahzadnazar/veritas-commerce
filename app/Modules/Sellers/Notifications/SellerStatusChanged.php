<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Notifications;

use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a seller their account has been suspended or reopened.
 *
 * A suspension email says plainly what still holds — the orders they owe,
 * the balance they are owed — because the alternative is a seller who
 * assumes their money and their obligations disappeared with their access.
 */
final class SellerStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $storeName,
        public readonly SellerStatus $status,
        public readonly ?string $reason = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $platform = (string) config('veritas.identity.display_name');
        $support = (string) config('veritas.identity.support_email');

        if ($this->status === SellerStatus::Suspended) {
            return (new MailMessage)
                ->subject("{$this->storeName} has been suspended on {$platform}")
                ->greeting('Your account has been suspended')
                ->line("{$this->storeName} is no longer visible to customers and cannot be changed while the suspension stands.")
                ->line($this->reason ?? '')
                ->line('Nothing has been deleted. Your orders, balance and records are intact, and any balance owed to you remains owed.')
                ->line("Reply to {$support} if you believe this is a mistake.");
        }

        return (new MailMessage)
            ->subject("{$this->storeName} is active again on {$platform}")
            ->greeting('Your account is active again')
            ->line("The suspension on {$this->storeName} has been lifted. Your store page is visible to customers again.")
            ->action('Open your seller portal', url('/seller'));
    }
}

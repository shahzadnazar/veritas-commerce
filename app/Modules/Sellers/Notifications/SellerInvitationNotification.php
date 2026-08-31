<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Queued so a slow mail provider never sits in a request.
 *
 * The token appears here and in no other record: the database holds only
 * its hash.
 */
final class SellerInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $storeName,
        public readonly string $invitationPublicId,
        public readonly string $token,
        public readonly Carbon $expiresAt,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url("/seller/invitations/{$this->invitationPublicId}?token={$this->token}");

        return (new MailMessage)
            ->subject("Join {$this->storeName} on ".config('veritas.identity.display_name'))
            ->greeting('You have been invited')
            ->line("{$this->storeName} has invited you to help run their store on ".config('veritas.identity.display_name').'.')
            ->action('Accept the invitation', $url)
            ->line('This link works once, and expires on '.$this->expiresAt->toFormattedDateString().'.')
            ->line('If you were not expecting this, you can ignore it — nothing happens until you accept.');
    }
}

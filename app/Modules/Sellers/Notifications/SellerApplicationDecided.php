<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Notifications;

use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an applicant what happened, and why where a reason exists.
 *
 * One notification for every outcome, so the wording of an approval and a
 * rejection cannot drift apart into different tones.
 */
final class SellerApplicationDecided extends Notification implements ShouldQueue
{
    // Mail has its own workers, so a slow provider delays nothing else.
    use Queueable;

    public function __construct(
        public readonly string $reference,
        public readonly SellerApplicationStatus $status,
        public readonly ?string $reason = null,
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

        $message = (new MailMessage)->subject(match ($this->status) {
            SellerApplicationStatus::Approved => "You're approved to sell on {$platform}",
            SellerApplicationStatus::Rejected => "About your {$platform} seller application",
            SellerApplicationStatus::ChangesRequested => "We need a change to your {$platform} application",
            default => "Your {$platform} seller application",
        });

        return match ($this->status) {
            SellerApplicationStatus::Approved => $message
                ->greeting('You are approved')
                ->line("Application {$this->reference} has been approved.")
                ->line('Set up your store — name, address, logo and policies — and your public page goes live.')
                ->action('Set up your store', url('/seller/store')),

            SellerApplicationStatus::ChangesRequested => $message
                ->greeting('One thing to fix')
                ->line("We need a change to application {$this->reference} before we can decide it.")
                ->line($this->reason ?? '')
                ->action('Update your application', url('/seller/apply')),

            SellerApplicationStatus::Rejected => $message
                ->greeting('We could not approve this application')
                ->line("Application {$this->reference} was not approved.")
                ->line($this->reason ?? '')
                ->line('You are welcome to apply again once the details are corrected.'),

            default => $message
                ->greeting('Application received')
                ->line("We have your application, {$this->reference}. The marketplace team reviews it next."),
        };
    }
}

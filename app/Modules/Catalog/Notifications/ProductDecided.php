<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Notifications;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the proposing seller what happened to their product.
 *
 * One notification for every outcome, so an approval and a rejection
 * cannot drift apart into different tones — and a request for changes
 * says what to change, because a request that does not is a rejection in
 * slow motion.
 */
final class ProductDecided extends Notification implements ShouldQueue
{
    // Mail has its own workers, so a slow provider delays nothing else.
    use Queueable;

    public function __construct(
        public readonly string $productTitle,
        public readonly ProductStatus $status,
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
            ProductStatus::Approved, ProductStatus::Published => "“{$this->productTitle}” is in the {$platform} catalogue",
            ProductStatus::ChangesRequested => "One change needed to “{$this->productTitle}”",
            ProductStatus::Rejected => "About “{$this->productTitle}”",
            ProductStatus::Suspended => "“{$this->productTitle}” has been suspended",
            default => "Your {$platform} product proposal",
        });

        return match ($this->status) {
            ProductStatus::Approved, ProductStatus::Published => $message
                ->greeting('Your product is in the catalogue')
                ->line("“{$this->productTitle}” has been accepted.")
                ->line('It belongs to the marketplace catalogue now, which means other sellers may list against it too — and your listing sits alongside theirs on one page.')
                ->action('Set your price', url('/seller/offers')),

            ProductStatus::ChangesRequested => $message
                ->greeting('One thing to fix')
                ->line("A moderator has asked for a change to “{$this->productTitle}” before it can be accepted.")
                ->line($this->reason ?? '')
                ->action('Update your proposal', url('/seller/products')),

            ProductStatus::Rejected => $message
                ->greeting('We could not accept this product')
                ->line("“{$this->productTitle}” was not accepted into the catalogue.")
                ->line($this->reason ?? ''),

            ProductStatus::Suspended => $message
                ->greeting('A product you list has been suspended')
                ->line("“{$this->productTitle}” has been pulled from sale, so listings against it are no longer visible.")
                ->line($this->reason ?? '')
                ->line('Your listing and its history are intact.'),

            default => $message
                ->greeting('Proposal received')
                ->line("“{$this->productTitle}” is with the catalogue team."),
        };
    }
}

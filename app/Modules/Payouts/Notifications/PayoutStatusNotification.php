<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Notifications;

use App\Support\Money;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What the seller is told about their payout. §61.
 *
 * One class rather than five, because the five messages differ only in
 * their wording — and five near-identical notification classes is how the
 * approved one ends up saying the money has arrived, which is the single
 * most important thing these must not do.
 *
 * NOTHING SENSITIVE TRAVELS. The amount, the reference and the date, plus
 * the rejection reason when there is one. No account number, no
 * destination reference, no provider identifier: an email is forwarded,
 * printed and left open on screens.
 *
 * Sent once per transition, and the guarantee is not in here. Every action
 * that dispatches one of these refuses a request that has already moved,
 * under a row lock, so a double-clicked approval fires one event and a
 * retried job re-sends nothing (§113).
 */
final class PayoutStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $kind,
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $reason = null,
        public readonly ?string $settlementReference = null,
        public readonly ?string $paidAt = null,
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
        $amount = Money::of($this->amountMinor, $this->currency)->format();
        $message = new MailMessage;

        return match ($this->kind) {
            self::REQUESTED => $message
                ->subject("Payout {$this->reference} requested")
                ->greeting('We have your request.')
                ->line("You asked to withdraw {$amount}. It is now reserved and is no longer part of your available balance.")
                ->line('Our finance team will review it and let you know.')
                ->action('View this payout', url("/seller/payouts/{$this->reference}")),

            // Deliberately not "your money is on its way". It is not.
            self::APPROVED => $message
                ->subject("Payout {$this->reference} approved")
                ->greeting('Approved.')
                ->line("Your withdrawal of {$amount} has been approved and is queued for settlement.")
                ->line('We will email you again once the transfer has actually been made.')
                ->action('View this payout', url("/seller/payouts/{$this->reference}")),

            self::REJECTED => $message
                ->subject("Payout {$this->reference} was not approved")
                ->greeting('We could not process this one.')
                ->line("Your withdrawal of {$amount} was not approved.")
                ->line($this->reason ?? 'Contact support if you would like to know more.')
                ->line('The money is back in your available balance and you can request it again.')
                ->action('View your earnings', url('/seller/earnings')),

            self::PAID => $message
                ->subject("Payout {$this->reference} sent — {$amount}")
                ->greeting('Sent.')
                ->line("We have transferred {$amount} to you.")
                ->line($this->settlementReference === null
                    ? 'It may take a few working days to appear.'
                    : "Reference {$this->settlementReference}. It may take a few working days to appear.")
                ->line($this->paidAt === null ? '' : "Sent on {$this->paidAt}.")
                ->action('View this payout', url("/seller/payouts/{$this->reference}")),

            default => $message
                ->subject("Payout {$this->reference} could not be completed")
                ->greeting('Something went wrong with this transfer.')
                ->line("Our attempt to send {$amount} did not go through.")
                ->line($this->reason ?? 'Our finance team is looking into it.')
                ->line('Your money is still reserved for this payout while we sort it out.')
                ->action('View this payout', url("/seller/payouts/{$this->reference}")),
        };
    }
}

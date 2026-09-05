<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Listeners;

use App\Modules\Identity\Models\User;
use App\Modules\Payouts\Events\PayoutApproved;
use App\Modules\Payouts\Events\PayoutFailed;
use App\Modules\Payouts\Events\PayoutPaid;
use App\Modules\Payouts\Events\PayoutRejected;
use App\Modules\Payouts\Events\PayoutRequested;
use App\Modules\Payouts\Notifications\PayoutStatusNotification;
use App\Modules\Sellers\Models\SellerMembership;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Telling the seller what happened to their money.
 *
 * WHO IS TOLD: the store's owners. Not every member — a catalogue manager
 * does not need to know the store withdrew $600, and a payout email is one
 * of the few in this system worth attacking. The owner is also the only
 * role that can request one, so they are the person who asked.
 *
 * EXACTLY ONCE, and the guarantee is upstream. Every action that dispatches
 * one of these refuses a request that has already moved, under a row lock,
 * so a double-clicked approve produces one event; a retried job re-delivers
 * this listener rather than re-firing the transition (§113).
 *
 * Queued on the emails lane. A mail provider timing out must not roll back
 * a settlement that has already posted a ledger debit.
 *
 * Nothing here moves money. §81: the ledger writes are all in the actions,
 * before the commit that dispatched the event.
 */
final class AnnouncePayout implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = Queues::EMAILS;

    public int $tries = 5;

    public function requested(PayoutRequested $event): void
    {
        $this->notifyOwners($event->sellerAccountId, new PayoutStatusNotification(
            kind: PayoutStatusNotification::REQUESTED,
            reference: $event->reference,
            amountMinor: $event->amountMinor,
            currency: $event->currency,
        ));
    }

    public function approved(PayoutApproved $event): void
    {
        $this->notifyOwners($event->sellerAccountId, new PayoutStatusNotification(
            kind: PayoutStatusNotification::APPROVED,
            reference: $event->reference,
            amountMinor: $event->amountMinor,
            currency: $event->currency,
        ));
    }

    public function rejected(PayoutRejected $event): void
    {
        $this->notifyOwners($event->sellerAccountId, new PayoutStatusNotification(
            kind: PayoutStatusNotification::REJECTED,
            reference: $event->reference,
            amountMinor: $event->amountMinor,
            currency: $event->currency,
            // Shown verbatim, which is why the domain refuses a rejection
            // without one.
            reason: $event->reason,
        ));
    }

    public function paid(PayoutPaid $event): void
    {
        $this->notifyOwners($event->sellerAccountId, new PayoutStatusNotification(
            kind: PayoutStatusNotification::PAID,
            reference: $event->reference,
            amountMinor: $event->amountMinor,
            currency: $event->currency,
            settlementReference: $event->settlementReference,
            paidAt: $event->paidAt,
        ));
    }

    public function failed(PayoutFailed $event): void
    {
        $this->notifyOwners($event->sellerAccountId, new PayoutStatusNotification(
            kind: PayoutStatusNotification::FAILED,
            reference: $event->reference,
            amountMinor: $event->amountMinor,
            currency: $event->currency,
            reason: $event->reason,
        ));
    }

    private function notifyOwners(int $sellerAccountId, BaseNotification $notification): void
    {
        $userIds = SellerMembership::query()
            ->withoutGlobalScopes()
            ->where('seller_account_id', $sellerAccountId)
            ->where('role', 'owner')
            ->whereNotNull('accepted_at')
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        $owners = User::query()->whereIn('id', $userIds)->get();

        if ($owners->isEmpty()) {
            return;
        }

        Notification::send($owners, $notification);
    }
}

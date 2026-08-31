<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Listeners;

use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Events\SellerApproved;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Notifications\SellerApplicationDecided;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The approval email.
 *
 * A listener rather than a line in the approve action: the account is the
 * transaction's job, telling the owner about it is not, and a mail server
 * being down must never roll an approval back. The event itself is
 * dispatched after commit, so this only ever runs for an approval that
 * actually stuck.
 */
final class NotifyApprovedSeller implements ShouldQueue
{
    public function handle(SellerApproved $event): void
    {
        $user = User::query()->find($event->ownerUserId);
        $reference = SellerApplication::query()->whereKey($event->applicationId)->value('reference');

        if ($user === null || $reference === null) {
            return;
        }

        $user->notify(new SellerApplicationDecided(
            reference: (string) $reference,
            status: SellerApplicationStatus::Approved,
        ));
    }
}

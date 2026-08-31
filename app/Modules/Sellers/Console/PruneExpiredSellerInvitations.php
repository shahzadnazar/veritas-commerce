<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Console;

use App\Modules\Sellers\Enums\InvitationStatus;
use App\Modules\Sellers\Models\SellerInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Marks invitations that were never accepted before their deadline.
 *
 * Expiry is a state the record moves into, not a query condition evaluated
 * everywhere — so an expired invitation reads the same in the members list,
 * the audit trail and the acceptance route.
 */
final class PruneExpiredSellerInvitations extends Command
{
    protected $signature = 'sellers:prune-invitations';

    protected $description = 'Expire seller invitations whose deadline has passed';

    public function handle(): int
    {
        $expired = SellerInvitation::query()
            ->where('status', InvitationStatus::Pending->value)
            ->where('expires_at', '<=', Carbon::now())
            ->update([
                'status' => InvitationStatus::Expired->value,
                'updated_at' => Carbon::now(),
            ]);

        $this->info("Expired {$expired} invitation(s).");

        return self::SUCCESS;
    }
}

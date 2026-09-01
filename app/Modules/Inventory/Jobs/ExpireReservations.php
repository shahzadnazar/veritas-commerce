<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Log;

/**
 * Returns stock that a checkout held and never came back for.
 *
 * A hold that outlives its TTL is the abandoned-cart case: the customer
 * closed the tab, the payment never captured, and the units are sitting
 * unsellable. Without this they stay that way forever, and a busy offer
 * slowly becomes unbuyable while its shelf is full.
 *
 * IDEMPOTENT, which is the requirement rather than a nicety. Running it
 * twice must not restore the same units twice, and it cannot: the release
 * selects only rows still `held`, FOR UPDATE, so a second sweep — or a
 * retried job, or two workers racing — finds nothing left to claim. The
 * ledger's CHECK on non-negative `reserved` is the backstop underneath
 * that.
 *
 * Reservations are released one at a time rather than in a single
 * transaction, so one wedged offer cannot hold up the sweep for every
 * other seller.
 */
final class ExpireReservations implements ShouldQueue
{
    use FoundationQueueable;
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $limit = 500)
    {
        $this->onQueue(Queues::CRITICAL);
    }

    public function handle(ReleaseReservation $release): void
    {
        $expired = InventoryReservation::query()
            ->where('status', ReservationStatus::Held->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($this->limit)
            ->get();

        $released = 0;

        foreach ($expired as $reservation) {
            // Per reservation, not per reference: a future multi-seller
            // checkout can share a reference, and only the rows that
            // actually expired should be swept.
            if ($release->one($reservation, ReservationStatus::Expired)) {
                $released++;
            }
        }

        if ($released > 0) {
            Log::info('Expired inventory reservations released.', [
                'released' => $released,
                'examined' => $expired->count(),
            ]);
        }
    }
}

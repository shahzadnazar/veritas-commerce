<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Enums\CheckoutStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Log;

/**
 * Closes checkouts that reserved stock and never became an order.
 *
 * The other half of the abandonment story. An unpaid ORDER is swept by
 * ExpireUnpaidOrders; this is the customer who reached the payment page,
 * held a seller's last unit, and closed the tab before an order existed at
 * all. Without it those holds are only cleared by the inventory sweep, and
 * the attempt row stays Reserved — which would let a retry of its
 * idempotency key walk into a checkout whose stock is long gone.
 *
 * IDEMPOTENT: the attempt is claimed by a conditional update, so two
 * workers cannot both sweep it, and the release only takes holds still
 * `held`.
 */
final class ExpireCheckoutAttempts implements ShouldQueue
{
    use FoundationQueueable;
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $limit = 200)
    {
        $this->onQueue(Queues::CRITICAL);
    }

    public function handle(ReleaseReservation $release): void
    {
        $stale = CheckoutAttempt::query()
            ->where('status', CheckoutStatus::Reserved->value)
            ->whereNull('marketplace_order_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($this->limit)
            ->get();

        $expired = 0;

        foreach ($stale as $attempt) {
            /*
             * Claimed by a conditional update rather than a read: the
             * WHERE is the lock, so a second worker's update matches
             * nothing and it moves on.
             */
            $claimed = CheckoutAttempt::query()
                ->whereKey($attempt->getKey())
                ->where('status', CheckoutStatus::Reserved->value)
                ->update([
                    'status' => CheckoutStatus::Expired->value,
                    'failure_reason' => 'The checkout timed out before it was paid.',
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                continue;
            }

            $release($attempt->reservationReference());
            $expired++;
        }

        if ($expired > 0) {
            Log::info('Abandoned checkout attempts expired.', [
                'expired' => $expired,
                'examined' => $stale->count(),
            ]);
        }
    }
}

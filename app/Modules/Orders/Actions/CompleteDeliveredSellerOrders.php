<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Ledger\Actions\ReleaseClearedEarnings;
use App\Modules\Ledger\Events\SellerEarningAvailable;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Events\SellerOrderCompleted;
use App\Modules\Orders\Models\SellerOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The sweep that turns cleared money into spendable money, and closes the
 * order it came from.
 *
 * §32 and §70. Both happen at the same moment and for the same reason —
 * the clearing period has passed with nothing blocking — so they are one
 * pass rather than two jobs racing each other to the same rows.
 *
 * RE-RUNNING IT CANNOT DUPLICATE MONEY, and not because it remembers what
 * it did: no money is written at all. Every amount was fixed when the
 * payment was verified, from the purchase snapshot. This moves a status
 * column and a completion date. Run it a hundred times and the same
 * entries are available, which is once.
 *
 * Concurrency is handled the same way as everywhere else in this codebase:
 * a conditional UPDATE, whose WHERE is the lock. Two workers sweeping
 * together both narrow to the same rows, and the second matches nothing.
 *
 * A seller cannot call this and cannot accelerate it (§63): the only input
 * is a date the platform wrote at delivery from a period the platform
 * resolved.
 */
final class CompleteDeliveredSellerOrders
{
    public function __construct(
        private readonly ReleaseClearedEarnings $release,
        private readonly AdvanceSellerOrder $advance,
    ) {}

    /** @return array{released: int, completed: int} */
    public function __invoke(int $limit = 500): array
    {
        $released = 0;
        $completed = 0;

        foreach ($this->due($limit) as $sellerOrderId) {
            $result = $this->settle($sellerOrderId);

            $released += $result['released'];
            $completed += $result['completed'];
        }

        return ['released' => $released, 'completed' => $completed];
    }

    /**
     * Seller orders whose clearing date has passed and which are still open.
     *
     * Read from the denormalised date on the seller order rather than by
     * walking the ledger: an indexed range scan over orders due, not a
     * table scan over every entry the marketplace has ever posted.
     *
     * @return array<int, int>
     */
    public function due(int $limit = 500): array
    {
        return SellerOrder::query()
            ->withoutGlobalScopes()
            ->whereNotNull('earnings_clear_at')
            ->where('earnings_clear_at', '<=', now())
            ->whereNull('completed_at')
            ->whereIn('status', [
                SellerOrderStatus::Delivered->value,
                SellerOrderStatus::PartiallyRefunded->value,
            ])
            ->orderBy('earnings_clear_at')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return array{released: int, completed: int} */
    private function settle(int $sellerOrderId): array
    {
        /** @var array{released: int, completed: int, events: array<int, object>} $outcome */
        $outcome = DB::transaction(function () use ($sellerOrderId): array {
            /** @var SellerOrder|null $sellerOrder */
            $sellerOrder = SellerOrder::query()
                ->withoutGlobalScopes()
                ->whereKey($sellerOrderId)
                ->whereNull('completed_at')
                ->lockForUpdate()
                ->first();

            // Another worker got here first, or the order was closed by
            // something else while this pass was in flight.
            if ($sellerOrder === null) {
                return ['released' => 0, 'completed' => 0, 'events' => []];
            }

            $released = ($this->release)($sellerOrder->id);

            $events = [];

            if ($released > 0) {
                $events[] = new SellerEarningAvailable(
                    sellerAccountId: (int) $sellerOrder->seller_account_id,
                    sellerOrderId: $sellerOrder->id,
                    sellerOrderReference: $sellerOrder->reference,
                    entryCount: $released,
                );
            }

            $completed = 0;

            /*
             * A disputed or fully refunded order is not completed by a
             * clock. Those are decisions somebody made, and the sweep does
             * not walk past them.
             */
            if (in_array($sellerOrder->status, [
                SellerOrderStatus::Delivered,
                SellerOrderStatus::PartiallyRefunded,
            ], true)) {
                if (($this->advance)(
                    $sellerOrder,
                    SellerOrderStatus::Completed,
                    actorType: 'system',
                    note: 'Clearing period elapsed.',
                )) {
                    $completed = 1;

                    $events[] = new SellerOrderCompleted(
                        sellerOrderId: $sellerOrder->id,
                        sellerOrderReference: $sellerOrder->reference,
                        sellerAccountId: (int) $sellerOrder->seller_account_id,
                        marketplaceOrderId: (int) $sellerOrder->marketplace_order_id,
                    );
                }
            }

            return ['released' => $released, 'completed' => $completed, 'events' => $events];
        });

        $events = $outcome['events'];

        DB::afterCommit(static function () use ($events): void {
            foreach ($events as $event) {
                Event::dispatch($event);
            }
        });

        return ['released' => $outcome['released'], 'completed' => $outcome['completed']];
    }
}

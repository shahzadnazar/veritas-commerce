<?php

declare(strict_types=1);

namespace App\Modules\Orders\Jobs;

use App\Modules\Orders\Actions\CancelUnpaidOrder;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Log;

/**
 * Closes orders whose payment window ran out.
 *
 * §29. The inventory sweep already returns an expired hold to the shelf,
 * but on its own that leaves the order behind it sitting in
 * pending_payment forever — a row a customer can still see, a seller can
 * still see, and nobody can fulfil. This is what closes the pair.
 *
 * Deliberately independent of whether the holds are still held: whichever
 * sweep gets there first, the release is idempotent and the outcome is the
 * same. What matters is that no order is left claiming stock it does not
 * have.
 *
 * One order at a time rather than one transaction for the batch, so a
 * single wedged order cannot hold up every other customer's release.
 */
final class ExpireUnpaidOrders implements ShouldQueue
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

    public function handle(CancelUnpaidOrder $cancel): void
    {
        $expired = MarketplaceOrder::query()
            ->where('status', MarketplaceOrderStatus::PendingPayment->value)
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now())
            ->orderBy('id')
            ->limit($this->limit)
            ->get();

        $cancelled = 0;

        foreach ($expired as $order) {
            if ($cancel($order, 'Payment window closed before the order was paid.')) {
                $cancelled++;
            }
        }

        if ($cancelled > 0) {
            Log::info('Unpaid orders cancelled and their stock released.', [
                'cancelled' => $cancelled,
                'examined' => $expired->count(),
            ]);
        }
    }
}

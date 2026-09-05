<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Payments\Enums\ProviderEventStatus;
use App\Modules\Payments\Jobs\ProcessProviderEvent;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Provider events that were written down and then never picked up.
 *
 * The webhook endpoint commits the event row first and queues the work
 * second, which is the right order — the durable record exists before
 * anything can go wrong with the queue. But it means there is a window
 * where the row exists and the job does not: Redis away for the second
 * the push ran, Redis flushed, a worker pool that lost the job. The row
 * then sits at `received` and nothing on earth is going to look at it.
 *
 * That window cost a real payment during the M9 failure drills. The
 * endpoint now requeues a redelivered event that is still merely
 * received, which closes it for as long as the provider keeps trying.
 * This closes it for good: providers give up eventually, and the last
 * delivery can be the one that failed.
 *
 * There is deliberately no new table. `provider_webhook_events` already
 * *is* the durable side-effect record — signed, verified and committed
 * before the queue was ever involved — so the recovery mechanism is a
 * query over it rather than an outbox alongside it.
 *
 * Safe to run as often as you like. `ProcessProviderEvent` claims its
 * event with a conditional UPDATE, so a replay that overlaps a worker
 * already holding the event finds nothing to claim and returns.
 */
final class ReplayStrandedPaymentEvents extends Command
{
    protected $signature = 'payments:replay-stranded
        {--minutes=15 : How old a received event must be before it counts as stranded}
        {--limit=200 : Most events to requeue in one pass}
        {--dry-run : List what would be requeued and change nothing}';

    protected $description = 'Requeue provider webhook events that were recorded but never processed';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));

        /*
         * `received` only, and old enough that a healthy queue would have
         * claimed it.
         *
         * Not `failed`: an event in that state is either inside the
         * queue's own retry schedule or has exhausted it and is waiting
         * for a person, and requeueing it from here would be a second,
         * uncoordinated retry loop over the same row. Not `processed` or
         * `ignored`, which are finished.
         */
        /** @var Collection<int, ProviderWebhookEvent> $stranded */
        $stranded = ProviderWebhookEvent::query()
            ->where('status', ProviderEventStatus::Received->value)
            ->where('received_at', '<', now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($stranded->isEmpty()) {
            $this->info('No stranded provider events.');

            return self::SUCCESS;
        }

        foreach ($stranded as $event) {
            $this->line(sprintf(
                '  %s %s (%s), received %s',
                $this->option('dry-run') ? 'would requeue' : 'requeued',
                $event->event_id,
                $event->type,
                $event->received_at->diffForHumans(),
            ));

            if (! $this->option('dry-run')) {
                ProcessProviderEvent::dispatch($event->id);
            }
        }

        /*
         * Logged as a warning even when it worked, because it working
         * means something else did not: every event here is one the queue
         * should already have handled, and a run that finds them
         * regularly is evidence of a queue problem rather than a healthy
         * safety net.
         */
        if (! $this->option('dry-run')) {
            Log::warning('Requeued stranded provider webhook events.', [
                'count' => $stranded->count(),
                'oldest_event_id' => $stranded->first()->event_id,
            ]);
        }

        $this->warn(sprintf(
            '%d provider event(s) had been recorded but never processed.',
            $stranded->count(),
        ));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Search\Jobs;

use App\Modules\Search\Contracts\IndexableProductSource;
use App\Modules\Search\Contracts\SearchIndex;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;

/**
 * Brings one product's search document up to date.
 *
 * Carries an id, not a model: by the time a worker picks this up the
 * product may have been approved, suspended or renamed, and the index
 * should reflect what is true now rather than what was true when the job
 * was queued.
 *
 * Idempotent by construction — it upserts one row keyed by product id — so
 * a retry, a redelivery or three overlapping copies all leave the same
 * single document.
 */
final class ReindexProduct implements ShouldQueue
{
    use FoundationQueueable;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $productId)
    {
        // Its own queue: a reindex may lag by a minute without anyone
        // noticing, and must never delay a payment or an approval email.
        $this->onQueue(Queues::SEARCH);
    }

    public function handle(IndexableProductSource $source, SearchIndex $index): void
    {
        $document = $source->describe($this->productId);

        if ($document === null) {
            // Gone, or merged away. Removing is also idempotent.
            $index->forget($this->productId);

            return;
        }

        $index->index($document);
    }
}

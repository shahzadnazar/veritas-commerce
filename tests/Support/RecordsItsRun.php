<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Cache;

/**
 * A job that leaves a trace, used to prove the queue runtime works.
 *
 * It is deliberately idempotent — it counts runs in the cache under a key
 * the caller chooses — so the retry test can tell "ran twice" from "ran
 * once and was retried".
 */
final class RecordsItsRun implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;
    use Queueable;

    public function __construct(public readonly string $key)
    {
        $this->onQueue(Queues::CATALOGUE);
    }

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function handle(): void
    {
        Cache::increment("job-runs:{$this->key}");
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * A job that never succeeds, used to prove retries and the failed-job
 * table behave as configured rather than as hoped.
 */
final class AlwaysFails implements ShouldQueue
{
    use FoundationQueueable;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $key)
    {
        $this->onQueue(Queues::CATALOGUE);
    }

    public function backoff(): int
    {
        // No waiting in a test; the retry policy itself is what is under
        // test, not the delay.
        return 0;
    }

    public function handle(): void
    {
        Cache::increment("job-attempts:{$this->key}");

        throw new RuntimeException('This job is supposed to fail.');
    }
}

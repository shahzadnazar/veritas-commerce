<?php

declare(strict_types=1);

namespace App\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proof that something is draining a queue.
 *
 * It carries no meaning of its own: it writes one cache key so an operator
 * — or CI — can tell the difference between "the worker is running" and
 * "the worker is running and actually picking work up", which are not the
 * same thing and fail differently.
 */
final class QueueHeartbeat implements ShouldQueue
{
    use FoundationQueueable;
    use Queueable;

    public int $tries = 1;

    public function __construct(private readonly string $token, string $queue)
    {
        $this->onQueue($queue);
    }

    public static function cacheKey(string $token): string
    {
        return "queue-heartbeat:{$token}";
    }

    public function handle(): void
    {
        // Long enough for a slow poller to still find it, short enough not
        // to litter the cache.
        Cache::put(self::cacheKey($this->token), true, now()->addMinutes(5));
    }
}

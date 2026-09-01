<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Jobs\QueueHeartbeat;
use App\Support\Queues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Dispatches a heartbeat and waits for a worker to run it.
 *
 * `horizon:status` says a supervisor is alive; this says work is actually
 * being drained, which is the thing that breaks — a queue nobody listens
 * to looks perfectly healthy from the dispatching side.
 */
final class QueueSmoke extends Command
{
    protected $signature = 'queues:smoke
        {--queue=* : Queues to test; defaults to every declared queue}
        {--timeout=45 : Seconds to wait for each heartbeat}';

    protected $description = 'Dispatch a heartbeat job to each queue and wait for a worker to run it';

    public function handle(): int
    {
        /** @var array<int, string> $requested */
        $requested = $this->option('queue');
        $queues = $requested === [] ? Queues::all() : $requested;
        $timeout = max(1, (int) $this->option('timeout'));

        $failed = [];

        foreach ($queues as $queue) {
            $token = Str::lower((string) Str::ulid());

            Cache::forget(QueueHeartbeat::cacheKey($token));
            QueueHeartbeat::dispatch($token, $queue);

            $deadline = microtime(true) + $timeout;
            $drained = false;

            while (microtime(true) < $deadline) {
                if (Cache::get(QueueHeartbeat::cacheKey($token)) === true) {
                    $drained = true;
                    break;
                }

                usleep(250_000);
            }

            Cache::forget(QueueHeartbeat::cacheKey($token));

            if ($drained) {
                $this->line("  <fg=green>ok</> {$queue}");

                continue;
            }

            $this->line("  <fg=red>no worker</> {$queue}");
            $failed[] = $queue;
        }

        if ($failed !== []) {
            $this->newLine();
            $this->error('Nothing drained: '.implode(', ', $failed));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Every queue was drained.');

        return self::SUCCESS;
    }
}

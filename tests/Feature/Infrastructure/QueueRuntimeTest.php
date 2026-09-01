<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Support\Queues;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AlwaysFails;
use Tests\Support\RecordsItsRun;
use Tests\TestCase;

/**
 * The queue runtime, exercised through Redis rather than mocked.
 *
 * The rest of the suite runs on the sync driver, which proves a job's logic
 * but not that it can be serialised, enqueued, drained and retried. This
 * one pushes real payloads at a real Redis and runs a real worker.
 */
final class QueueRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'redis']);

        // Its own Redis database, flushed each time, so a developer's
        // running Horizon cannot eat the test's jobs and vice versa.
        Redis::connection('default')->client()->flushdb();
        Cache::flush();
    }

    #[Test]
    public function a_job_survives_the_round_trip_through_redis(): void
    {
        RecordsItsRun::dispatch('round-trip');

        // It is on the wire, not in this process.
        $this->assertSame(0, (int) Cache::get('job-runs:round-trip'));
        $this->assertSame(1, Queue::connection('redis')->size(Queues::CATALOGUE));

        $this->workOnce(Queues::CATALOGUE);

        $this->assertSame(1, (int) Cache::get('job-runs:round-trip'));
        $this->assertSame(0, Queue::connection('redis')->size(Queues::CATALOGUE));
    }

    #[Test]
    public function a_job_lands_on_the_queue_it_names(): void
    {
        RecordsItsRun::dispatch('named-queue');

        $this->assertSame(1, Queue::connection('redis')->size(Queues::CATALOGUE));
        $this->assertSame(0, Queue::connection('redis')->size(Queues::CRITICAL));
        $this->assertSame(0, Queue::connection('redis')->size(Queues::MEDIA));
    }

    #[Test]
    public function every_declared_queue_is_drained_by_a_horizon_supervisor(): void
    {
        $supervised = [];

        foreach (config('horizon.defaults') as $supervisor) {
            foreach ($supervisor['queue'] as $queue) {
                $supervised[] = $queue;
            }
        }

        foreach (Queues::all() as $queue) {
            $this->assertContains(
                $queue,
                $supervised,
                "Nothing drains the {$queue} queue: a job dispatched to it would sit there forever.",
            );
        }
    }

    #[Test]
    public function a_failing_job_is_retried_and_then_recorded(): void
    {
        AlwaysFails::dispatch('doomed');

        // tries = 3, so it runs three times and is then filed rather than
        // retried forever.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->workOnce(Queues::CATALOGUE);
        }

        $this->assertSame(3, (int) Cache::get('job-attempts:doomed'), 'The job should stop after its third attempt.');
        $this->assertSame(0, Queue::connection('redis')->size(Queues::CATALOGUE));

        // Observable: it is in the failed table with its exception, which
        // is what makes it retryable by hand later.
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseHas('failed_jobs', ['queue' => Queues::CATALOGUE]);

        $failed = (string) app('db')->table('failed_jobs')->value('exception');
        $this->assertStringContainsString('This job is supposed to fail.', $failed);
    }

    #[Test]
    public function a_duplicate_of_a_queued_job_is_not_enqueued_twice(): void
    {
        // Retry safety at the dispatch end: the same unique job dispatched
        // twice before it runs is one job, not two.
        RecordsItsRun::dispatch('unique-key');
        RecordsItsRun::dispatch('unique-key');

        $this->assertSame(1, Queue::connection('redis')->size(Queues::CATALOGUE));

        $this->workOnce(Queues::CATALOGUE);

        $this->assertSame(1, (int) Cache::get('job-runs:unique-key'));
    }

    /** Run exactly one job, the way a worker process would. */
    private function workOnce(string $queue): void
    {
        // Straight to the kernel: artisan() returns a pending assertion
        // object, and there is nothing here to assert about the worker's
        // output — only about what it did to the queue.
        $this->app[Kernel::class]->call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--once' => true,
        ]);
    }
}

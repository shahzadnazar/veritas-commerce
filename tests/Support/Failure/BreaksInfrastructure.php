<?php

declare(strict_types=1);

namespace Tests\Support\Failure;

use App\Modules\Media\Contracts\ObjectStore;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Takes a dependency away for the duration of a closure, and gives it back.
 *
 * The rule these follow is the one that makes a failure drill worth
 * running: **do not mock away the component whose failure is being
 * tested**. A test that swaps `DB` for a fake and asserts the fake was
 * called has proved that the test can call a fake. So PostgreSQL and
 * Redis are taken away by pointing the application at a closed port —
 * a real connection refusal, through the real driver, raising the real
 * exception type that production would raise.
 *
 * Port 1 is used because it is reserved (tcpmux) and never listening, so
 * the kernel refuses immediately: the drill measures the application's
 * behaviour rather than a network timeout.
 *
 * The two dependencies with no local endpoint to break — the mail
 * provider and object storage — get controlled fakes instead, because
 * there is no SMTP server or S3 bucket in this environment to unplug.
 * Those fakes fail at the seam the real provider fails at: a transport
 * that throws on send, and a store that throws on the operation named.
 *
 * Everything is restored in a `finally`, including after the assertion
 * inside the closure fails, because a drill that leaves the suite's own
 * database pointed at a closed port takes every later test with it.
 */
trait BreaksInfrastructure
{
    /**
     * Run a closure with PostgreSQL unreachable.
     *
     * The default connection is repointed rather than purged. Purging
     * would tear down the connection `RefreshDatabase` is holding its
     * transaction on, and the suite would lose its rollback — so the
     * working connection stays exactly where it is and the application
     * is simply told to use a different one.
     */
    protected function withDatabaseDown(Closure $work): mixed
    {
        $original = (string) config('database.default');

        config([
            'database.connections.unreachable_db' => [
                ...config('database.connections.pgsql'),
                'host' => '127.0.0.1',
                'port' => 1,
                // A password that must never appear in any response or
                // rendered error, and is checked for by the drills.
                'password' => 'drill-secret-password',
            ],
            'database.default' => 'unreachable_db',
        ]);

        try {
            return $work();
        } finally {
            config(['database.default' => $original]);
            DB::purge('unreachable_db');
        }
    }

    /**
     * Run a closure with Redis unreachable, for cache and for queueing.
     *
     * Both Redis connections move, because an outage does not politely
     * confine itself to one database number. The cache store is switched
     * to the redis driver as well: the suite runs on the array store, and
     * a cache outage drill against an in-memory array proves nothing.
     */
    protected function withRedisDown(Closure $work): mixed
    {
        $cacheStore = (string) config('cache.default');
        $queue = (string) config('queue.default');

        // Captured rather than re-read from the environment afterwards:
        // `env()` returns null once the configuration is cached, and a
        // restore that quietly wrote null would leave every later test
        // pointing at nothing.
        $redis = [
            'default' => config('database.redis.default'),
            'cache' => config('database.redis.cache'),
        ];

        config([
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
            'database.redis.cache.host' => '127.0.0.1',
            'database.redis.cache.port' => 1,
            'cache.default' => 'redis',
            'queue.default' => 'redis',
        ]);

        $this->forgetRedis();

        try {
            return $work();
        } finally {
            config([
                'database.redis.default' => $redis['default'],
                'database.redis.cache' => $redis['cache'],
                'cache.default' => $cacheStore,
                'queue.default' => $queue,
            ]);

            $this->forgetRedis();
        }
    }

    /**
     * Drop every cached handle that remembers where Redis used to be.
     *
     * Laravel caches a resolved connection per name in three places —
     * the Redis manager, the cache manager and the queue manager — and a
     * drill that changed the configuration without clearing all three
     * would keep talking to the working server and pass for the wrong
     * reason.
     */
    private function forgetRedis(): void
    {
        foreach (['default', 'cache'] as $connection) {
            try {
                Redis::purge($connection);
            } catch (Throwable) {
                // Never connected; nothing to forget.
            }
        }

        Cache::purge('redis');

        /*
         * The queue manager caches a connection per name and offers no
         * way to forget one, so the whole manager goes. Resolving it
         * again rebuilds the Redis queue against whatever the config now
         * says — which, inside a drill, is a closed port.
         */
        $this->app->forgetInstance('queue');
        $this->app->forgetInstance('redis');
        $this->app->forgetInstance('redis.connection');
    }

    /**
     * Run a closure with the mail transport rejecting everything.
     *
     * A transport rather than `Mail::fake()`, because the drill is about
     * what happens when sending *fails* — faking mail proves the opposite,
     * that it succeeded without leaving the process.
     */
    protected function withMailFailing(Closure $work, bool $permanent = false): mixed
    {
        $original = (string) config('mail.default');

        Mail::extend('drill_failing', static fn (): FailingMailTransport => new FailingMailTransport($permanent));

        config([
            'mail.mailers.drill_failing' => ['transport' => 'drill_failing'],
            'mail.default' => 'drill_failing',
        ]);

        try {
            return $work();
        } finally {
            config(['mail.default' => $original]);
            $this->app->forgetInstance('mail.manager');
        }
    }

    /**
     * Run a closure with object storage failing on the named operations.
     *
     * @param  array<int, string>  $operations  put, putContents, url, temporaryUrl, readStream, exists, delete
     */
    protected function withObjectStoreFailing(array $operations, Closure $work): mixed
    {
        /** @var ObjectStore $real */
        $real = $this->app->make(ObjectStore::class);

        $this->app->instance(ObjectStore::class, new FailingObjectStore($real, $operations));

        try {
            return $work();
        } finally {
            $this->app->instance(ObjectStore::class, $real);
        }
    }
}

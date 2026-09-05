<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M9 block C — the two questions an orchestrator asks.
 *
 * The distinction these tests defend is the one that matters
 * operationally: a failing liveness probe kills and replaces the
 * container, while a failing readiness probe drains it and leaves it
 * running. If liveness depended on PostgreSQL, a database outage would
 * restart every application container in a loop — turning a recoverable
 * incident into a thundering herd against a database that is already in
 * trouble.
 *
 * So the central assertions are not "200 when healthy". They are "still
 * 200 when the dependency is gone" for liveness, and "503 and no
 * credentials" for readiness.
 */
final class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_CONNECTION = 'pgsql';

    protected function tearDown(): void
    {
        // Restored before the harness tears down: RefreshDatabase rolls
        // back on whatever the DEFAULT connection is at teardown, so
        // leaving it pointed at a dead server would break every test that
        // follows rather than just this one.
        Config::set('database.default', self::REAL_CONNECTION);
        DB::purge('unreachable');

        parent::tearDown();
    }

    /**
     * Point the default connection at somewhere nothing is listening.
     *
     * A SPARE connection rather than the real one. Rewriting the pgsql
     * config in place would destroy the connection RefreshDatabase is
     * holding its transaction on — the test would take down its own
     * harness, and the failure would look like the thing being tested.
     */
    private function breakDatabase(): void
    {
        Config::set('database.connections.unreachable', array_merge(
            (array) Config::get('database.connections.pgsql'),
            [
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'nothing_here',
                'username' => 'nobody',
                'password' => 'a-password-that-must-not-be-echoed',
            ],
        ));

        Config::set('database.default', 'unreachable');
        DB::purge('unreachable');
    }

    private function breakRedis(): void
    {
        Config::set('database.redis.default.host', '127.0.0.1');
        Config::set('database.redis.default.port', 1);
        Config::set('database.redis.default.password', 'a-redis-password-that-must-not-be-echoed');

        Redis::purge('default');
    }

    #[Test]
    public function liveness_answers_without_touching_anything(): void
    {
        $response = $this->getJson('/health/live');

        $response->assertOk();
        $response->assertJsonPath('status', 'live');

        // Nothing about dependencies, because it did not look.
        $response->assertJsonMissingPath('dependencies');
    }

    #[Test]
    public function readiness_reports_its_dependencies_when_they_are_up(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertOk();
        $response->assertJsonPath('status', 'ready');
        $response->assertJsonPath('dependencies.database', 'up');
        $response->assertJsonPath('dependencies.redis', 'up');
    }

    #[Test]
    public function a_database_outage_drains_the_container_rather_than_killing_it(): void
    {
        $this->breakDatabase();

        // The whole point: still alive, so the orchestrator does not
        // restart it during a database incident.
        $this->getJson('/health/live')->assertOk()->assertJsonPath('status', 'live');

        $ready = $this->getJson('/health/ready');

        $ready->assertStatus(503);
        $ready->assertJsonPath('status', 'unready');
        $ready->assertJsonPath('dependencies.database', 'down');
    }

    #[Test]
    public function a_redis_outage_drains_the_container_rather_than_killing_it(): void
    {
        $this->breakRedis();

        $this->getJson('/health/live')->assertOk()->assertJsonPath('status', 'live');

        $ready = $this->getJson('/health/ready');

        $ready->assertStatus(503);
        $ready->assertJsonPath('status', 'unready');
        $ready->assertJsonPath('dependencies.redis', 'down');
    }

    #[Test]
    public function a_failing_probe_publishes_nothing_about_the_infrastructure(): void
    {
        /*
         * A connection exception carries the host, the port, the database
         * name and often the username; some drivers include the password.
         * A probe that returned it would be publishing the topology to
         * anybody who curls it.
         */
        $this->breakDatabase();
        $this->breakRedis();

        $body = (string) $this->getJson('/health/ready')->getContent();

        foreach ([
            'the database password' => 'a-password-that-must-not-be-echoed',
            'the redis password' => 'a-redis-password-that-must-not-be-echoed',
            'the database name' => 'nothing_here',
            'the database user' => 'nobody',
            'a driver message' => 'SQLSTATE',
            'a connection string' => 'pgsql:',
            'a stack trace' => 'vendor/laravel',
        ] as $what => $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $body,
                "The readiness payload leaked {$what}.",
            );
        }

        // What it does say is enough to act on and nothing more.
        $this->assertJson($body);
        $this->assertSame(
            ['dependencies', 'status'],
            $this->sortedKeys($body),
            'The readiness payload grew a field; check it discloses nothing.',
        );
    }

    #[Test]
    public function probes_are_never_cached(): void
    {
        // A cached "ready" is a load balancer sending traffic to a process
        // that stopped being ready ten minutes ago.
        foreach (['/health/live', '/health/ready'] as $url) {
            $cache = (string) $this->getJson($url)->baseResponse->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $cache, "{$url} may be cached.");
        }
    }

    #[Test]
    public function probes_do_not_start_a_session(): void
    {
        /*
         * The structural reason liveness survives a Redis outage: the
         * routes are outside every middleware group, so no session is
         * started. If they were ever moved into `web`, sessions live in
         * Redis in production and liveness would begin depending on it —
         * which is the failure this whole design avoids.
         */
        foreach (['health.live', 'health.ready'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "The route {$name} has disappeared.");

            $middleware = $route->gatherMiddleware();

            $this->assertNotContains('web', $middleware, "{$name} joined the web group.");
            $this->assertSame(
                [],
                array_values(array_filter(
                    $middleware,
                    static fn (mixed $entry): bool => is_string($entry)
                        && (str_contains($entry, 'Session') || str_contains($entry, 'throttle')),
                )),
                "{$name} acquired session or throttle middleware.",
            );
        }
    }

    #[Test]
    public function readiness_stays_cheap(): void
    {
        // Polled every few seconds by every replica forever, so anything
        // heavier than a round trip becomes a self-inflicted load test
        // against the database it exists to protect.
        DB::connection(self::REAL_CONNECTION)->enableQueryLog();

        $this->getJson('/health/ready')->assertOk();

        $queries = DB::connection(self::REAL_CONNECTION)->getQueryLog();

        DB::connection(self::REAL_CONNECTION)->disableQueryLog();

        $this->assertLessThanOrEqual(1, count($queries), 'Readiness ran more than one query.');

        foreach ($queries as $query) {
            $this->assertStringContainsStringIgnoringCase('select 1', (string) $query['query']);
        }
    }

    #[Test]
    public function schema_currency_is_a_deployment_gate_and_not_a_probe(): void
    {
        /*
         * Deliberately excluded, and worth stating: asking "are migrations
         * pending?" on every probe means a rolling deploy pulls the OLD,
         * working containers out of service the moment a new migration is
         * pending — the opposite of what the check is for. It is owned by
         * app:pre-deploy, which runs once before traffic is switched.
         */
        $source = (string) file_get_contents(base_path('app/Http/Controllers/HealthController.php'));

        $this->assertStringNotContainsString('migrations', $source);
        $this->assertStringNotContainsString('migrate', $source);

        $preDeploy = (string) file_get_contents(base_path('app/Console/Commands/PreDeployCheckCommand.php'));
        $this->assertNotSame('', $preDeploy, 'The deployment gate that owns this must still exist.');
    }

    /** @return array<int, string> */
    private function sortedKeys(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $keys = array_keys($decoded);
        sort($keys);

        return $keys;
    }
}

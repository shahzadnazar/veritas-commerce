<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Two questions an orchestrator asks, which are not the same question.
 *
 * LIVENESS — "is this process worth keeping?" A failing liveness probe
 * gets the container killed and replaced. So it must depend on nothing
 * but the process itself: if PostgreSQL goes down and liveness starts
 * failing, every application container is restarted in a loop for the
 * duration of a database outage, turning a recoverable incident into a
 * thundering herd against a database that is already struggling. It
 * touches no dependency, and that is the entire design.
 *
 * READINESS — "should this process receive traffic right now?" A failing
 * readiness probe takes the container out of the load balancer and leaves
 * it running. That is the right response to a dependency being away: stop
 * sending requests that will fail, keep the process so it can rejoin when
 * the dependency returns.
 *
 * Neither says anything an attacker can use. A probe that helpfully
 * reported "could not connect to postgres.internal:5432 as veritas" would
 * be publishing the topology to anybody who curls it, so the payload is a
 * coarse per-dependency state and nothing else — no host, no user, no
 * driver message, no stack trace. The detail belongs in the log, where it
 * is already going.
 *
 * MIGRATIONS are deliberately not checked here. Whether the schema is
 * current is a deployment gate, and it is owned by `app:pre-deploy`, which
 * runs once before traffic is switched. Asking it on every probe would
 * mean a rolling deploy pulls the OLD, working containers out of service
 * the moment the new migration is pending — the opposite of what the check
 * is for.
 */
final class HealthController
{
    /**
     * The process answered. That is the whole claim.
     */
    public function live(): JsonResponse
    {
        return $this->payload(['status' => 'live'], Response::HTTP_OK);
    }

    /**
     * The dependencies this process needs to serve a request.
     *
     * One trivial round trip each — `select 1` and a Redis ping — because
     * a readiness endpoint is polled every few seconds by every replica
     * forever, and anything heavier becomes a self-inflicted load test
     * against the database it is meant to be protecting.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->reachable(static fn () => DB::connection()->select('select 1')),
            'redis' => $this->reachable(static fn () => Redis::connection()->command('ping', [])),
        ];

        $ready = ! in_array(false, $checks, true);

        return $this->payload(
            [
                'status' => $ready ? 'ready' : 'unready',
                // Coarse on purpose: "up" or "down" tells an operator
                // which dependency to look at without telling a stranger
                // what it is called or where it lives.
                'dependencies' => array_map(
                    static fn (bool $ok): string => $ok ? 'up' : 'down',
                    $checks,
                ),
            ],
            $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * Whether a dependency answered, with the reason kept out of the body.
     *
     * The exception carries a DSN, a username and often a password in its
     * message. It is caught here and never travels further than the
     * boolean — the connection error is already logged by the driver
     * layer, which is where an operator should be reading it from.
     */
    private function reachable(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $body */
    private function payload(array $body, int $status): JsonResponse
    {
        return response()
            ->json($body, $status)
            // Never cached. A cached "ready" is a load balancer sending
            // traffic to a process that stopped being ready ten minutes
            // ago.
            ->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }
}

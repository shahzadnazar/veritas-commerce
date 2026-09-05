<?php

declare(strict_types=1);

namespace Tests\Feature\Failure;

use DB;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Failure\BreaksInfrastructure;
use Tests\TestCase;
use Throwable;

/**
 * PostgreSQL is gone. What does the deployment do?
 *
 * The database is taken away by pointing the application at a closed
 * port — a real connection refusal through the real driver — rather than
 * by mocking `DB`, because a mocked `DB` proves only that the mock was
 * called.
 *
 * Three things have to hold at once, and they pull in different
 * directions. The process must stay alive, or an orchestrator restarts
 * every container in a loop and turns a recoverable database incident
 * into a thundering herd. It must stop receiving traffic, or it serves
 * errors it could have declined. And whatever it does say must not
 * describe the database it cannot reach.
 */
final class DatabaseOutageTest extends TestCase
{
    use BreaksInfrastructure;
    use RefreshDatabase;

    /**
     * Liveness touches nothing, so nothing can take it down.
     *
     * This is the difference between an outage and an outage plus a
     * restart storm.
     */
    #[Test]
    public function liveness_stays_up_while_the_database_is_gone(): void
    {
        $this->withDatabaseDown(function (): void {
            $this->getJson('/health/live')
                ->assertOk()
                ->assertJsonPath('status', 'live');
        });
    }

    /**
     * Readiness stops the traffic instead, and names the dependency
     * coarsely enough to be useful without being a map.
     */
    #[Test]
    public function readiness_reports_unready_while_the_database_is_gone(): void
    {
        $this->withDatabaseDown(function (): void {
            $this->getJson('/health/ready')
                ->assertStatus(503)
                ->assertJsonPath('status', 'unready')
                ->assertJsonPath('dependencies.database', 'down');
        });
    }

    /**
     * Coarse on purpose.
     *
     * A readiness probe is unauthenticated and polled by anything that
     * can reach the port. "down" tells an operator which dependency to
     * look at; a driver message would tell a stranger the host, the port
     * and the user.
     */
    #[Test]
    public function the_readiness_payload_names_no_host_user_or_credential(): void
    {
        $this->withDatabaseDown(function (): void {
            $body = $this->getJson('/health/ready')->assertStatus(503)->getContent();

            $this->assertIsString($body);

            foreach (['drill-secret-password', '127.0.0.1', 'pgsql', 'SQLSTATE', 'veritas_test'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "The readiness payload disclosed \"{$leak}\".");
            }
        });
    }

    /**
     * An ordinary page fails, and fails without narrating the database.
     *
     * Debug mode is turned off explicitly: the interesting assertion is
     * about the production renderer, and the suite runs with debug on.
     */
    #[Test]
    public function a_database_backed_page_fails_without_disclosing_the_connection(): void
    {
        config(['app.debug' => false]);

        $this->withDatabaseDown(function (): void {
            $response = $this->withHeaders(['Accept' => 'text/html'])->get('/search?q=kettle');

            $this->assertSame(500, $response->getStatusCode(), 'A page that needs the database should fail loudly.');

            $body = $response->getContent();
            $this->assertIsString($body);

            foreach (['drill-secret-password', 'SQLSTATE', 'veritas_test', 'PDOException', 'Connection refused'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "The error page disclosed \"{$leak}\".");
            }
        });
    }

    /**
     * The failure is a database failure, and says so where it is safe to.
     *
     * An operator needs to know which dependency broke; the exception
     * reaching the handler carries that, and the handler is what decides
     * how much of it a stranger sees. Asserting on the thrown exception
     * rather than on the log keeps this a test about the application
     * instead of a test about the logging configuration.
     */
    #[Test]
    public function the_underlying_failure_identifies_the_database_for_the_operator(): void
    {
        $this->withDatabaseDown(function (): void {
            $caught = null;

            try {
                DB::connection()->select('select 1');
            } catch (Throwable $e) {
                $caught = $e;
            }

            $this->assertNotNull($caught, 'Querying an unreachable database should throw.');
            $this->assertInstanceOf(QueryException::class, $caught);
        });
    }
}

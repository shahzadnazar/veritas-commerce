<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Support\Database\ConfigurePostgresSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * PostgreSQL ships its three timeouts disabled, and "disabled" means a
 * statement may hold its connection until the heat death of the universe.
 *
 * That is not a theoretical problem. There are a hundred connections. A
 * handful of unbounded queries takes them all, and then every request —
 * including the healthy ones — queues behind a page nobody is still
 * waiting for. The idle-in-transaction case is quieter and worse: a
 * worker that dies mid-transaction holds its locks and blocks VACUUM
 * until a person notices, which is where table bloat and stuck DDL come
 * from.
 *
 * The split between web and console is the part worth pinning. A
 * statement timeout that killed a migration halfway through would be the
 * cure doing the damage, so console processes are exempt from the two
 * that bound work — and exempt on purpose, not by omission.
 */
final class DatabaseSessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_web_session_is_bounded_in_time_and_in_lock_waiting(): void
    {
        $statements = ConfigurePostgresSession::statements(console: false);

        $this->assertContains('SET statement_timeout = 15000', $statements);
        $this->assertContains('SET lock_timeout = 5000', $statements);
        $this->assertContains('SET idle_in_transaction_session_timeout = 60000', $statements);
    }

    #[Test]
    public function a_console_session_may_take_as_long_as_the_work_takes(): void
    {
        $statements = ConfigurePostgresSession::statements(console: true);

        $this->assertNotContains('SET statement_timeout = 15000', $statements);
        $this->assertSame(
            [],
            array_values(array_filter($statements, static fn (string $s): bool => str_contains($s, 'lock_timeout'))),
            'A migration waiting for a lock should wait, not fail.',
        );
    }

    /**
     * The one limit a console process does not escape.
     *
     * A backup or a rebuild is allowed to run for an hour; neither is
     * allowed to sit inside an open transaction doing nothing.
     */
    #[Test]
    public function a_console_session_is_still_bounded_when_it_is_idle_in_a_transaction(): void
    {
        $this->assertContains(
            'SET idle_in_transaction_session_timeout = 60000',
            ConfigurePostgresSession::statements(console: true),
        );
    }

    #[Test]
    public function zero_means_no_limit_and_is_available_as_an_escape_hatch(): void
    {
        config(['veritas.database.timeouts.statement_ms' => 0]);

        $this->assertContains('SET statement_timeout = 0', ConfigurePostgresSession::statements(console: false));
    }

    /**
     * Not just generated — actually applied.
     *
     * The suite runs in the console, so this is the console profile: the
     * idle-in-transaction limit is set on the live connection and the
     * statement limit is not. Asserting the statements a pure function
     * returns would prove the arithmetic; this proves the wiring.
     */
    #[Test]
    public function the_settings_reach_the_connection(): void
    {
        $this->assertSame('1min', DB::scalar("select current_setting('idle_in_transaction_session_timeout')"));
        $this->assertSame('0', DB::scalar("select current_setting('statement_timeout')"));
    }

    /**
     * A database that cannot be reached fails, rather than recursing.
     *
     * `ConnectionEstablished` fires when Laravel builds the connection
     * object, which is before its PDO is opened. The first version of
     * this listener applied its settings through
     * `Connection::statement()`, which opened the PDO — and when that
     * failed, Laravel's lost-connection recovery called `reconnect()`,
     * which dispatched `ConnectionEstablished` again. One unreachable
     * database recursed until the kernel killed the process at six
     * gigabytes, and the symptom was the whole test suite dying with no
     * output and no failing test.
     *
     * The time bound is the assertion that matters. A connection refused
     * on a closed port is immediate; anything that takes seconds is the
     * recursion coming back.
     */
    #[Test]
    public function an_unreachable_database_fails_rather_than_recursing(): void
    {
        config([
            'database.connections.unreachable' => [
                ...config('database.connections.pgsql'),
                'host' => '127.0.0.1',
                'port' => 1,
            ],
        ]);

        $startedAt = microtime(true);
        $threw = false;

        try {
            DB::connection('unreachable')->select('select 1');
        } catch (Throwable) {
            $threw = true;
        } finally {
            DB::purge('unreachable');
        }

        $this->assertTrue($threw, 'A connection to a closed port should have failed.');
        $this->assertLessThan(
            10.0,
            microtime(true) - $startedAt,
            'Connecting to a closed port took seconds. That is the reconnect recursion, not the network.',
        );
    }

    /**
     * Every connection, not just the first.
     *
     * Queue workers, PHP-FPM children and the concurrency tests all open
     * their own. A listener bound to the wrong event would configure one
     * session and look correct in a single-connection test.
     */
    #[Test]
    public function a_second_connection_is_configured_too(): void
    {
        $this->assertSame(
            '1min',
            DB::connection('concurrent')->scalar("select current_setting('idle_in_transaction_session_timeout')"),
        );
    }
}

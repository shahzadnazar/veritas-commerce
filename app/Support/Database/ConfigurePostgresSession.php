<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Modules\Search\Adapters\PostgresSearchIndex;
use Illuminate\Database\Events\ConnectionEstablished;
use Throwable;

/**
 * Everything a new PostgreSQL session is told before it is used.
 *
 * Two unrelated things, in one place because they share one trigger: the
 * fuzzy-search threshold, and the three timeouts PostgreSQL ships
 * disabled.
 *
 * ## Fuzzy search
 *
 * Teaching every session what "close enough" means.
 *
 * `pg_trgm` answers fuzzy matching two ways, and only one of them can use
 * an index. `word_similarity(a, b) > 0.3` is a function call over a
 * column, so PostgreSQL must compute it for every row; `a <% b` is an
 * operator the GIN trigram index implements directly. The two are
 * equivalent — but only when the session's threshold is the one the
 * application means, because the operator takes its cutoff from a session
 * setting rather than from the query.
 *
 * That difference was worth 25.7 ms against 0.2 ms on a ten-thousand
 * document index, and the gap widens with the catalogue: the function
 * form reads every row whatever the phrase, and the operator form reads
 * only the rows the index points at. Worse, the fuzzy branch is OR'd with
 * the full-text and identifier branches, so its unindexability was
 * disabling all three — a keyword search was a full scan of the search
 * table, five times over, every time.
 *
 * Setting it here rather than before each query costs one round trip per
 * connection instead of one per search. The risk of a session-level
 * setting is that a connection which somehow missed it would quietly
 * search at PostgreSQL's default of 0.6 and return fewer typo matches,
 * so `app:production-check` reads the value back and
 * `SearchThresholdTest` asserts it — a silent narrowing of recall is
 * exactly the kind of failure that would otherwise be found by a customer
 * rather than by us.
 */
final class ConfigurePostgresSession
{
    /**
     * Connections currently being configured, by name.
     *
     * A re-entrancy guard, and it is not defensive programming for its
     * own sake — the first version of this deadlocked the test suite into
     * a six-gigabyte process. `ConnectionEstablished` fires when the
     * connection object is made, which for Laravel is before its PDO is
     * opened. Running the settings through `Connection::statement()` then
     * opened it, and if opening failed, Laravel's lost-connection
     * recovery called `reconnect()` — which dispatches
     * `ConnectionEstablished` again. One unreachable database, and the
     * recursion ran until the kernel killed it.
     *
     * @var array<string, true>
     */
    private static array $configuring = [];

    public function handle(ConnectionEstablished $event): void
    {
        $connection = $event->connection;

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $name = (string) $connection->getName();

        if (isset(self::$configuring[$name])) {
            return;
        }

        self::$configuring[$name] = true;

        try {
            /*
             * Straight at the PDO handle rather than through
             * `Connection::statement()`, which is the other half of the
             * fix above: `statement()` routes failures into the
             * reconnect machinery, and `exec()` simply throws.
             */
            $pdo = $connection->getPdo();

            foreach (self::statements(app()->runningInConsole()) as $statement) {
                $pdo->exec($statement);
            }
        } catch (Throwable) {
            /*
             * A session that cannot be configured is a connection that
             * cannot be used, and the next real query will say so with a
             * far better message than this could. Swallowing it here also
             * means a diagnostic that deliberately points at an
             * unreachable database still reports "unreachable" rather
             * than whatever happened while trying to set a threshold on
             * it.
             *
             * The failure mode this leaves — settings silently not
             * applied on a connection that otherwise works — is covered
             * by `DatabaseSessionTest`, `TrigramThresholdTest` and the
             * two `app:production-check` checks that read the values back
             * from a live session.
             */
        } finally {
            unset(self::$configuring[$name]);
        }
    }

    /**
     * Everything a new session is told, as SQL.
     *
     * A pure function of the configuration rather than something that
     * only happens inside a database event, so what a session ends up
     * with can be asserted without opening one.
     *
     * Nothing here is interpolated from input. `SET` does not take bound
     * parameters, so the values have to go into the string — which means
     * the only acceptable sources are a constant in this repository and
     * integers from config, cast on the way through.
     *
     * @return array<int, string>
     */
    public static function statements(bool $console): array
    {
        // `similarity()` for whole-string comparison, `word_similarity()`
        // for the best-matching run inside a title. The search adapter
        // uses both and they are separate settings.
        $threshold = sprintf('%.4F', PostgresSearchIndex::FUZZY_THRESHOLD);

        $statements = [
            'SET pg_trgm.similarity_threshold = '.$threshold,
            'SET pg_trgm.word_similarity_threshold = '.$threshold,
        ];

        /*
         * A request nobody is waiting for any more should stop costing a
         * connection, and a lock nobody can take should fail rather than
         * queue every request behind it. Console processes are exempt:
         * migrations, backups and the analytics rebuild are supposed to
         * take minutes, and a statement timeout that killed a migration
         * halfway would be the cure doing the damage.
         */
        if (! $console) {
            $statements[] = 'SET statement_timeout = '.self::milliseconds('statement_ms');
            $statements[] = 'SET lock_timeout = '.self::milliseconds('lock_ms');
        }

        /*
         * This one applies everywhere. It fires only when a session is
         * doing nothing at all inside an open transaction, which no
         * legitimate path does for a minute — but a crashed worker does
         * forever, holding its locks and blocking VACUUM the whole time.
         */
        $statements[] = 'SET idle_in_transaction_session_timeout = '.self::milliseconds('idle_in_transaction_ms');

        return $statements;
    }

    /** Zero means "no limit" to PostgreSQL, which is also what it means here. */
    private static function milliseconds(string $key): string
    {
        return (string) max(0, (int) config("veritas.database.timeouts.{$key}", 0));
    }
}

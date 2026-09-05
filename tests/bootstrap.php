<?php

declare(strict_types=1);

/*
 * One PHPUnit run at a time against one test database.
 *
 * Two suites sharing `veritas_test` do not merely interleave: they
 * migrate against each other. One run's `migrate:fresh` drops tables the
 * other is mid-transaction on, PostgreSQL reports a deadlock on the DDL
 * rather than on anything a test did, and what is left is a half-migrated
 * schema whose failures look like real defects in code that is fine. That
 * happened during M8 and cost a re-run of the whole suite to diagnose.
 *
 * A session-level advisory lock makes the second run say so and stop.
 * `pg_try_advisory_lock` returns immediately rather than blocking, so a
 * developer gets a sentence instead of a hang, and CI gets a failed step
 * instead of a confusing one.
 *
 * The lock is held by a PDO connection kept alive in a static for the
 * lifetime of the process; PostgreSQL releases it when the connection
 * closes, so a crashed run does not leave the lock stuck.
 *
 * GitHub Actions already runs the two PHPUnit steps serially — steps in a
 * job never overlap — so this changes nothing there. It exists for the
 * local case CI cannot police, and to make a future `--parallel` fail
 * loudly rather than intermittently.
 */

require __DIR__.'/../vendor/autoload.php';

final class TestDatabaseLock
{
    /** Kept so the connection, and therefore the lock, outlives this call. */
    private static ?PDO $connection = null;

    /** Whether this process is holding the lock. */
    public static function isHeld(): bool
    {
        return self::$connection instanceof PDO;
    }

    public static function acquire(): void
    {
        if (self::isHeld()) {
            // Bootstrap ran twice in one process. Taking the lock again
            // would succeed — PostgreSQL advisory locks are re-entrant
            // within a session — and leave a second reference nothing
            // ever releases.
            return;
        }

        $database = getenv('DB_DATABASE') ?: 'veritas_test';

        // Opt out deliberately — an isolated database per worker is the
        // other correct answer, and somebody who has built that should
        // not have to fight this.
        if (getenv('VERITAS_ALLOW_CONCURRENT_TESTS') === '1') {
            return;
        }

        $connection = self::connect($database);

        if ($connection === null) {
            // No database to lock is not this file's problem to report:
            // the first test that needs one will say so far more clearly.
            return;
        }

        // Derived from the database name, so two genuinely separate test
        // databases never contend with each other.
        $key = self::keyFor($database);

        $statement = $connection->query("select pg_try_advisory_lock({$key}) as locked");
        $held = $statement === false ? null : $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($held) && filter_var($held['locked'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            self::$connection = $connection;

            return;
        }

        fwrite(STDERR, <<<TEXT

            Another PHPUnit run is already using the "{$database}" database.

            Two runs against one test database migrate against each other:
            you get deadlocks on DDL and a half-migrated schema, and the
            failures look like defects in code that is fine.

            Wait for the other run to finish, or give this one its own
            database:

                DB_DATABASE=veritas_test_2 ./vendor/bin/phpunit

            If you have genuinely isolated the databases already, set
            VERITAS_ALLOW_CONCURRENT_TESTS=1.


            TEXT);

        exit(1);
    }

    private static function connect(string $database): ?PDO
    {
        try {
            return new PDO(
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    self::setting('DB_HOST', '127.0.0.1'),
                    self::setting('DB_PORT', '5432'),
                    $database,
                ),
                self::setting('DB_USERNAME', 'veritas'),
                self::setting('DB_PASSWORD', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * One setting, from the environment or from .env.
     *
     * The framework has not booted at bootstrap time, so `.env` is not in
     * `getenv()` yet. Reading the handful of keys needed here is cheaper
     * and clearer than booting Laravel to find out where its database is
     * — and a real environment variable still wins, which is what CI and
     * a per-worker database both rely on.
     */
    private static function setting(string $key, string $default): string
    {
        $value = getenv($key);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return self::fromDotEnv()[$key] ?? $default;
    }

    /** @return array<string, string> */
    private static function fromDotEnv(): array
    {
        static $parsed = null;

        if ($parsed !== null) {
            return $parsed;
        }

        $parsed = [];
        $path = __DIR__.'/../.env';

        if (! is_readable($path)) {
            return $parsed;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! str_contains($line, '=') || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $parsed[trim($key)] = trim($value, " \t\"'");
        }

        return $parsed;
    }

    /** A stable 32-bit key from the database name. */
    private static function keyFor(string $database): int
    {
        return (int) sprintf('%d', crc32('veritas-phpunit:'.$database));
    }
}

TestDatabaseLock::acquire();

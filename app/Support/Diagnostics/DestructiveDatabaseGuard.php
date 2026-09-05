<?php

declare(strict_types=1);

namespace App\Support\Diagnostics;

/**
 * Nothing destroys a database without naming it first.
 *
 * This exists because of a real mistake made during M9. The command was
 * `php artisan migrate:fresh --seed --env=testing`, the intent was to
 * rebuild the PHPUnit database, and the result was that the development
 * database was dropped instead — because the repository had no
 * `.env.testing`, so `--env=testing` loaded `.env` and resolved to
 * whatever `DB_DATABASE` said. PHPUnit sets its own database through
 * `phpunit.xml`; an ordinary artisan command does not inherit that, and
 * `--env=testing` is not evidence of anything.
 *
 * Two things follow, and both are needed.
 *
 * The repository now ships `.env.testing`, so the flag means what people
 * assume it means. That fixes the specific trap.
 *
 * And this guard makes the class of trap survivable: before a destructive
 * command runs, the database it is about to destroy is **named out loud**,
 * and a database that has been declared protected refuses outright. An
 * operator who sees "about to destroy: veritas" when they expected
 * "veritas_test" stops. That is the whole mechanism — nobody misreads a
 * database name they were shown.
 *
 * Deliberately not a denylist of names baked into the application. One
 * developer's "veritas" is another deployment's throwaway. Protection is
 * declared by the environment that knows: `APP_ENV=production` is
 * protected unconditionally, and `VERITAS_PROTECTED_DATABASES` names any
 * others.
 */
final class DestructiveDatabaseGuard
{
    /**
     * Artisan commands that drop or truncate application data.
     *
     * `migrate:rollback` is deliberately absent: it is destructive in a
     * bounded, intentional way that an operator invokes knowing exactly
     * which batch they are undoing, and gating it here would make routine
     * deployment recovery need an override flag.
     */
    public const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'db:wipe',
    ];

    /** APP_ENV values that name a live deployment. */
    public const PRODUCTION_ENVIRONMENTS = ['production', 'prod', 'live'];

    /** The one way past this, named so it cannot be typed by accident. */
    public const OVERRIDE = 'VERITAS_ALLOW_DESTRUCTIVE_DB';

    public function __construct(
        private readonly string $environment,
        private readonly ?string $connection = null,
    ) {}

    public static function forCurrentRequest(): self
    {
        return new self((string) config('app.env'));
    }

    public function isDestructive(string $command): bool
    {
        return in_array($command, self::DESTRUCTIVE_COMMANDS, true);
    }

    /** The database a destructive command would actually act on. */
    public function targetDatabase(): string
    {
        $connection = $this->connection ?? (string) config('database.default');

        return (string) config("database.connections.{$connection}.database", '');
    }

    public function targetConnection(): string
    {
        return $this->connection ?? (string) config('database.default');
    }

    /**
     * Why this must not run, or null if it may.
     *
     * The message names the database, because the failure this prevents
     * is somebody acting on a database they had not realised they were
     * pointed at.
     */
    public function refusalReason(): ?string
    {
        if ($this->isOverridden()) {
            return null;
        }

        $database = $this->targetDatabase();

        if ($this->isProductionEnvironment()) {
            return sprintf(
                'APP_ENV is "%s" and the target database is "%s". Refusing.',
                $this->environment,
                $database,
            );
        }

        if ($this->isProtected($database)) {
            return sprintf(
                'Database "%s" is listed in %s. Refusing.',
                $database,
                'VERITAS_PROTECTED_DATABASES',
            );
        }

        return null;
    }

    /**
     * Whether an operator has explicitly consented to this.
     *
     * Read from the process environment rather than through config, and
     * that is the opposite of the choice made for the protected list one
     * method below — deliberately, because the two are opposite kinds of
     * value.
     *
     * The protected list is policy: it must hold even when `config:cache`
     * has severed `env()` from the `.env` file, so it goes through config.
     * The override is a decision taken once, inline, on a single command:
     *
     *     VERITAS_ALLOW_DESTRUCTIVE_DB=1 php artisan migrate:fresh
     *
     * That value never reaches a cached config, and it should not — an
     * override baked into a deployment's configuration is the same as no
     * guard at all. If this read ever fails, it fails towards refusing.
     */
    public function isOverridden(): bool
    {
        $value = getenv(self::OVERRIDE);

        if ($value === false) {
            $value = $_SERVER[self::OVERRIDE] ?? $_ENV[self::OVERRIDE] ?? '';
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
    }

    public function isProtected(string $database): bool
    {
        if ($database === '') {
            return false;
        }

        return in_array($database, $this->protectedDatabases(), true);
    }

    /**
     * The databases the environment has declared off limits.
     *
     * The UNION of two sources, and it took losing a database twice to
     * get this right.
     *
     * Config is the first, because `config:cache` severs `env()` from the
     * `.env` file — a list read only through `env()` would empty itself on
     * exactly the deployments that cache their configuration.
     *
     * But config caching freezes the list at BUILD time, which M9 found
     * the hard way: with a cache built before the variable was set,
     * `VERITAS_PROTECTED_DATABASES=veritas php artisan migrate:fresh`
     * announced the right database and then dropped it, because the
     * cached list was empty. An operator reaching for the variable in the
     * moment is the case this guard exists for, and it was the one case
     * that did not work.
     *
     * So the live process environment is read as well, and the two are
     * combined rather than chosen between. A union can only ever protect
     * MORE than either source alone — which is the correct direction for
     * a guard to fail in.
     *
     * @return array<int, string>
     */
    public function protectedDatabases(): array
    {
        $names = [];

        $configured = config('veritas.database.protected', []);

        if (is_array($configured)) {
            foreach ($configured as $name) {
                $names[] = (string) $name;
            }
        }

        foreach (explode(',', $this->environmentList()) as $name) {
            $names[] = $name;
        }

        $names = array_filter(array_map(trim(...), $names), static fn (string $n): bool => $n !== '');

        return array_values(array_unique($names));
    }

    /**
     * The protected list as this process was actually started with.
     *
     * Read from the process environment rather than through `env()`, for
     * the same reason the override is: this has to work when the
     * configuration cache has already been built, and that is precisely
     * when `env()` stops seeing anything.
     */
    private function environmentList(): string
    {
        $value = getenv('VERITAS_PROTECTED_DATABASES');

        if ($value === false) {
            $value = $_SERVER['VERITAS_PROTECTED_DATABASES'] ?? $_ENV['VERITAS_PROTECTED_DATABASES'] ?? '';
        }

        return (string) $value;
    }

    /**
     * The line an operator reads before their data disappears.
     *
     * Includes the resolved server, not just the database name, because
     * "veritas_test" on the wrong host is the same accident wearing a
     * safer-looking name.
     */
    public function announcement(): string
    {
        $connection = $this->targetConnection();

        return sprintf(
            'Destructive command targeting database "%s" on %s:%s (connection %s, APP_ENV %s).',
            $this->targetDatabase(),
            (string) config("database.connections.{$connection}.host", 'unknown host'),
            (string) config("database.connections.{$connection}.port", '?'),
            $connection,
            $this->environment,
        );
    }

    /**
     * Whether APP_ENV names a live deployment.
     *
     * More than an equality check against 'production' because the cost
     * of the two mistakes is not symmetric: refusing on a staging box
     * called "prod" costs one documented override, and not refusing costs
     * the database.
     */
    public function isProductionEnvironment(): bool
    {
        return in_array(strtolower($this->environment), self::PRODUCTION_ENVIRONMENTS, true);
    }
}

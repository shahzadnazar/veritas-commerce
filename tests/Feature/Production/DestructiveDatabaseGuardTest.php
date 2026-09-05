<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Support\Diagnostics\DestructiveDatabaseGuard;
use App\Support\Diagnostics\DestructiveDatabaseRefused;
use Illuminate\Console\Events\CommandStarting;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * M9 §0: the destructive-database trap cannot be walked into twice.
 *
 * The mistake this encodes was real and it was mine. `migrate:fresh
 * --seed --env=testing` was run in the belief that `--env=testing`
 * selected the PHPUnit database. It did not — the repository had no
 * `.env.testing`, so the flag loaded `.env`, and the development database
 * was dropped instead. Three reconciliations were then run against an
 * empty database and proved nothing.
 *
 * Documenting that would have been worthless. The lesson only survives as
 * executable behaviour, which is what this file holds:
 *
 *  - the flag now means what people assume, because `.env.testing` exists
 *    and names the same database phpunit.xml does;
 *  - every destructive command says which database it is about to destroy
 *    before it destroys it;
 *  - a database the environment declared protected refuses outright;
 *  - and the way past that is one loudly-named variable, not a guess.
 *
 * These tests dispatch the framework's own `CommandStarting` event rather
 * than invoking `migrate:fresh`, for the obvious reason: a test that
 * proved the guard by running the command it guards would, on the day the
 * guard broke, drop the test database to tell us so.
 */
final class DestructiveDatabaseGuardTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $this->originalEnvironment = [];

        parent::tearDown();
    }

    private function setEnvironmentVariable(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->originalEnvironment)) {
            $this->originalEnvironment[$key] = getenv($key);
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /** The first capture group, or a failed test saying which file let us down. */
    private function captureOne(string $pattern, string $subject, string $message): string
    {
        if (preg_match($pattern, $subject, $matches) !== 1 || ! isset($matches[1])) {
            $this->fail($message);
        }

        return trim($matches[1]);
    }

    /** Dispatch a command-start the way Artisan does, and return what the operator saw. */
    private function startCommand(string $command): BufferedOutput
    {
        $output = new BufferedOutput;

        event(new CommandStarting($command, new ArrayInput([]), $output));

        return $output;
    }

    #[Test]
    public function the_guarded_commands_are_the_ones_that_destroy_data(): void
    {
        $guard = new DestructiveDatabaseGuard('local');

        $this->assertTrue($guard->isDestructive('migrate:fresh'));
        $this->assertTrue($guard->isDestructive('migrate:refresh'));
        $this->assertTrue($guard->isDestructive('db:wipe'));

        // Bounded and deliberate: an operator rolling back knows which
        // batch they are undoing, and gating it would put an override flag
        // into routine deployment recovery.
        $this->assertFalse($guard->isDestructive('migrate:rollback'));

        $this->assertFalse($guard->isDestructive('migrate'));
        $this->assertFalse($guard->isDestructive('queue:work'));
    }

    #[Test]
    public function a_destructive_command_names_the_database_before_it_runs(): void
    {
        config()->set('veritas.database.protected', []);

        $output = $this->startCommand('migrate:fresh')->fetch();

        // The whole mechanism in one assertion: nobody misreads a database
        // name they were shown. Host included, because the same name on
        // the wrong server is the same accident wearing a safer face.
        $this->assertStringContainsString(
            (string) config('database.connections.'.config('database.default').'.database'),
            $output,
        );
        $this->assertStringContainsString(
            (string) config('database.connections.'.config('database.default').'.host'),
            $output,
        );
        $this->assertStringContainsString('testing', $output);
    }

    #[Test]
    public function an_ordinary_command_is_left_entirely_alone(): void
    {
        config()->set('veritas.database.protected', []);

        $this->assertSame('', $this->startCommand('migrate')->fetch());
        $this->assertSame('', $this->startCommand('queue:work')->fetch());
        $this->assertSame('', $this->startCommand('migrate:rollback')->fetch());
    }

    #[Test]
    public function a_protected_database_refuses_the_command(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        config()->set('veritas.database.protected', [$database]);

        $this->expectException(DestructiveDatabaseRefused::class);

        $this->startCommand('migrate:fresh');
    }

    #[Test]
    public function the_refusal_reaches_the_operator_and_says_how_to_proceed(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        config()->set('veritas.database.protected', [$database]);

        $output = new BufferedOutput;

        try {
            event(new CommandStarting('migrate:fresh', new ArrayInput([]), $output));
            $this->fail('The guard did not refuse a protected database.');
        } catch (DestructiveDatabaseRefused) {
            // Expected. What matters is what was printed on the way out.
        }

        $written = $output->fetch();

        $this->assertStringContainsString($database, $written);
        $this->assertStringContainsString('VERITAS_PROTECTED_DATABASES', $written);
        $this->assertStringContainsString(DestructiveDatabaseGuard::OVERRIDE, $written);
    }

    #[Test]
    public function production_is_protected_without_being_named(): void
    {
        // The list is empty on purpose: a deployment that forgot to fill
        // it in is exactly the deployment that needs this.
        config()->set('veritas.database.protected', []);

        foreach (DestructiveDatabaseGuard::PRODUCTION_ENVIRONMENTS as $environment) {
            $guard = new DestructiveDatabaseGuard($environment);

            $this->assertNotNull(
                $guard->refusalReason(),
                "APP_ENV={$environment} must refuse a destructive command.",
            );
        }

        $this->assertNull((new DestructiveDatabaseGuard('local'))->refusalReason());
        $this->assertNull((new DestructiveDatabaseGuard('testing'))->refusalReason());
    }

    #[Test]
    public function the_override_is_explicit_and_loudly_named(): void
    {
        config()->set('veritas.database.protected', [
            (string) config('database.connections.'.config('database.default').'.database'),
        ]);

        $guard = new DestructiveDatabaseGuard('production');
        $this->assertNotNull($guard->refusalReason());

        $this->setEnvironmentVariable(DestructiveDatabaseGuard::OVERRIDE, '1');

        $this->assertTrue($guard->isOverridden());
        $this->assertNull(
            $guard->refusalReason(),
            'An explicit override must let a deliberate operator through.',
        );
    }

    #[Test]
    public function a_vague_override_value_is_not_an_override(): void
    {
        $guard = new DestructiveDatabaseGuard('production');

        foreach (['', '0', 'false', 'no', 'maybe', 'please'] as $value) {
            $this->setEnvironmentVariable(DestructiveDatabaseGuard::OVERRIDE, $value);

            $this->assertFalse(
                $guard->isOverridden(),
                "\"{$value}\" must not read as consent to destroy a database.",
            );
        }
    }

    #[Test]
    public function no_database_name_is_baked_into_the_application(): void
    {
        // The guard protects what the environment declares, never a name
        // compiled in. One developer's "veritas" is another deployment's
        // scratch database, and a hardcoded list would be wrong for both.
        //
        // Asserted over the guard's string *literals* rather than its file
        // text, because the prose is allowed to say "veritas_test" while
        // explaining the trap. What must never appear is a database name
        // the code can act on.
        $source = (string) file_get_contents(
            base_path('app/Support/Diagnostics/DestructiveDatabaseGuard.php'),
        );

        $literals = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = trim($token[1], "'\"");
            }
        }

        $this->assertNotEmpty($literals, 'The tokenizer found no literals; the scan proved nothing.');

        foreach ($literals as $literal) {
            if (stripos($literal, 'veritas') === false) {
                continue;
            }

            // Two things are allowed to carry the application's name: the
            // config key the protected list is read from, and the
            // environment variables an operator sets. Anything else
            // carrying it is a database name, and a database name in here
            // is the bug this test exists to catch.
            $this->assertTrue(
                $literal === 'veritas.database.protected'
                    || preg_match('/^VERITAS_[A-Z_]+$/', $literal) === 1,
                "The guard must not name a database in code: found \"{$literal}\".",
            );
        }

        config()->set('veritas.database.protected', []);
        $this->assertSame([], (new DestructiveDatabaseGuard('local'))->protectedDatabases());
    }

    #[Test]
    public function the_protected_list_survives_a_cached_config(): void
    {
        // Read through config, never `env()` directly. Once `config:cache`
        // has run, `env()` no longer sees the .env file — a protection
        // list read that way would empty itself on exactly the deployments
        // that cache their config.
        $source = (string) file_get_contents(
            base_path('app/Support/Diagnostics/DestructiveDatabaseGuard.php'),
        );

        $this->assertStringNotContainsString("env('VERITAS_PROTECTED_DATABASES'", $source);
        $this->assertStringContainsString("config('veritas.database.protected'", $source);

        config()->set('veritas.database.protected', ['one', 'two']);
        $this->assertSame(['one', 'two'], (new DestructiveDatabaseGuard('local'))->protectedDatabases());
    }

    #[Test]
    public function the_testing_environment_file_agrees_with_phpunit(): void
    {
        // `--env=testing` is only trustworthy while these two name the same
        // database. If they ever drift, the original M9 mistake is back:
        // an artisan command that resolves somewhere the developer did not
        // expect. This test is the thing that stops the drift.
        $this->assertFileExists(base_path('.env.testing'));

        $dotenv = (string) file_get_contents(base_path('.env.testing'));
        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

        $this->assertSame(
            $this->captureOne(
                '/<env name="DB_DATABASE" value="([^"]+)"/',
                $phpunit,
                'phpunit.xml must name its database.',
            ),
            $this->captureOne(
                '/^DB_DATABASE=(.+)$/m',
                $dotenv,
                '.env.testing must name its database.',
            ),
            '.env.testing and phpunit.xml must resolve to the same database.',
        );
    }

    #[Test]
    public function the_testing_environment_file_is_tracked_by_git(): void
    {
        // Untracked, it protects only the machine it was written on, and
        // the trap returns for whoever clones next.
        $ignored = shell_exec('cd '.escapeshellarg(base_path()).' && git check-ignore .env.testing 2>&1');

        $this->assertSame('', trim((string) $ignored), '.env.testing must not be git-ignored.');
    }

    #[Test]
    public function the_guard_is_actually_wired_on_the_command_line(): void
    {
        // The test that matters, and the one that was missing.
        //
        // Every other test here dispatches `CommandStarting` by hand, so
        // they prove the handler and nothing about whether anything calls
        // it. That gap hid a real defect: Laravel's console kernel only
        // re-routes Symfony's command events when `runningUnitTests()` is
        // false, and `runningUnitTests()` means no more than "APP_ENV is
        // testing". So on the command line with `--env=testing` the event
        // never fired and the guard was inert — the guard against the
        // `--env=testing` accident, disabled by `--env=testing`.
        //
        // Hence a real subprocess, with the exact flag from the incident.
        //
        // It targets a database that does not exist, so a regression
        // cannot destroy anything: without the guard the command fails on
        // a missing database instead, which is why the assertions are on
        // the refusal text rather than merely on a non-zero exit.
        $probe = 'veritas_guard_probe_'.bin2hex(random_bytes(4));

        $command = sprintf(
            'cd %s && APP_ENV=testing DB_DATABASE=%s VERITAS_PROTECTED_DATABASES=%s %s artisan db:wipe --force --env=testing 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg($probe),
            escapeshellarg($probe),
            escapeshellarg(PHP_BINARY),
        );

        $output = (string) shell_exec($command);

        $this->assertStringContainsString(
            $probe,
            $output,
            'A destructive command must name its target database on the command line.',
        );
        $this->assertStringContainsString(
            'Refusing',
            $output,
            'The guard must refuse a protected database on the command line, not only in-process.',
        );
        $this->assertStringContainsString(DestructiveDatabaseGuard::OVERRIDE, $output);
    }

    #[Test]
    public function the_restore_drill_refuses_to_restore_over_its_own_source(): void
    {
        // The shell half of the same guarantee. Asserted structurally
        // rather than by running the drill, because running it needs a
        // dump and a spare database; what must never regress is that the
        // comparison exists at all.
        $script = (string) file_get_contents(base_path('ops/restore-drill.sh'));

        $this->assertStringContainsString('"$TARGET" = "$PGDATABASE"', $script);
        $this->assertStringContainsString('target_is_source', $script);
        $this->assertStringContainsString('VERITAS_PROTECTED_DATABASES', $script);
        $this->assertStringContainsString(DestructiveDatabaseGuard::OVERRIDE, $script);
    }
}

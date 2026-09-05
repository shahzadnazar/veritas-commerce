<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M9 block E — what the repository itself is carrying.
 *
 * A secret scan run once is a scan that was true once. These are the parts
 * of the audit that can be made to run on every build, so the answer stays
 * current: no live-credential shapes in tracked files, committed
 * environment files carrying placeholders only, and no reliance on a
 * hand-placed dependency.
 *
 * The dependency advisories themselves (composer audit, npm audit) belong
 * in CI rather than here — they query a remote database, and a test suite
 * that fails because an advisory was published overnight is a test suite
 * people learn to ignore. CI runs them on every build.
 */
final class RepositoryHygieneTest extends TestCase
{
    /**
     * Shapes that only real credentials have.
     *
     * Deliberately not `sk_test_` or `whsec_`: the production check
     * detects those by prefix and the suite builds synthetic ones, so a
     * scan that flagged them would flag the code that exists to catch
     * them. What is scanned for is the shape a LIVE credential takes.
     *
     * @var array<string, string>
     */
    private const LIVE_CREDENTIAL_SHAPES = [
        'stripe live secret key' => '/sk_live_[0-9a-zA-Z]{16,}/',
        'stripe live publishable key' => '/pk_live_[0-9a-zA-Z]{16,}/',
        'stripe restricted key' => '/rk_live_[0-9a-zA-Z]{16,}/',
        'aws access key id' => '/AKIA[0-9A-Z]{16}/',
        'private key block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'github token' => '/gh[pousr]_[A-Za-z0-9]{30,}/',
        'slack token' => '/xox[baprs]-[0-9A-Za-z-]{10,}/',
    ];

    /** @return array<int, string> */
    private function trackedFiles(): array
    {
        $output = (string) shell_exec('cd '.escapeshellarg(base_path()).' && git ls-files 2>/dev/null');

        $files = array_values(array_filter(explode("\n", $output)));

        $this->assertNotEmpty($files, 'The scan listed no tracked files; it is not looking at the repository.');

        return $files;
    }

    #[Test]
    public function no_tracked_file_carries_a_live_credential(): void
    {
        $binary = ['png', 'jpg', 'jpeg', 'gif', 'ico', 'woff', 'woff2', 'pdf', 'zip', 'phar'];
        $found = [];
        $scanned = 0;

        foreach ($this->trackedFiles() as $file) {
            if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $binary, true)) {
                continue;
            }

            $path = base_path($file);

            if (! is_file($path)) {
                continue;
            }

            $scanned++;
            $contents = (string) file_get_contents($path);

            foreach (self::LIVE_CREDENTIAL_SHAPES as $what => $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    // The location, never the value.
                    $found[] = "{$what} in {$file}";
                }
            }
        }

        $this->assertGreaterThan(100, $scanned, 'The scan covered almost nothing.');
        $this->assertSame([], $found, "A live credential shape is committed:\n".implode("\n", $found));
    }

    #[Test]
    public function committed_environment_files_hold_placeholders_only(): void
    {
        /*
         * .env.example and .env.testing are both committed on purpose —
         * .env.testing because without it `--env=testing` resolved to the
         * development database and M9 lost one. Being committed is exactly
         * why what is in them matters.
         */
        foreach (['.env.example', '.env.testing'] as $file) {
            $this->assertFileExists(base_path($file));

            $values = $this->environmentValues($file);

            $this->assertNotEmpty($values, "{$file} parsed to nothing.");

            foreach (self::LIVE_CREDENTIAL_SHAPES as $what => $pattern) {
                foreach ($values as $key => $value) {
                    // The APP_KEY in .env.testing is a real key shape by
                    // necessity — it has to decrypt the test suite's own
                    // data — and it protects nothing: the test database is
                    // rebuilt from scratch on every run. It is documented
                    // as such in that file's header.
                    if ($file === '.env.testing' && $key === 'APP_KEY') {
                        continue;
                    }

                    $this->assertSame(
                        0,
                        preg_match($pattern, $value),
                        "{$file} sets {$key} to something shaped like a {$what}.",
                    );
                }
            }

            // And the credentials that are present are obviously not real.
            foreach (['STRIPE_KEY', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET'] as $key) {
                if (! array_key_exists($key, $values) || $values[$key] === '') {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/replace_me|placeholder|example|test/i',
                    $values[$key],
                    "{$file} sets {$key} to something that does not announce itself as a placeholder.",
                );
            }
        }
    }

    #[Test]
    public function the_test_environment_credentials_are_unmistakably_local(): void
    {
        $values = $this->environmentValues('.env.testing');

        $this->assertSame('testing', $values['APP_ENV'] ?? null);
        $this->assertSame('fake', $values['PAYMENT_GATEWAY'] ?? null);
        $this->assertSame('array', $values['MAIL_MAILER'] ?? null);
        $this->assertStringContainsString('test', $values['DB_DATABASE'] ?? '');
        $this->assertContains($values['DB_HOST'] ?? '', ['127.0.0.1', 'localhost', 'postgres']);
    }

    #[Test]
    public function no_dependency_is_hand_placed(): void
    {
        /*
         * M5 needed an offline workaround for the Stripe package. This
         * asserts the workaround left nothing behind: no path or artifact
         * repository in composer.json, no vendor directory in the
         * repository, so a clean locked install in CI is the only thing
         * that decides what ships.
         */
        /** @var array<string, mixed> $composer */
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey(
            'repositories',
            $composer,
            'A custom package repository would let a hand-placed copy decide what ships.',
        );

        $tracked = $this->trackedFiles();

        $vendored = array_values(array_filter(
            $tracked,
            static fn (string $file): bool => str_starts_with($file, 'vendor/') || str_starts_with($file, 'node_modules/'),
        ));

        $this->assertSame([], $vendored, 'Installed dependencies are committed to the repository.');

        // And the lock is present, so the install is reproducible.
        $this->assertContains('composer.lock', $tracked);
        $this->assertContains('package-lock.json', $tracked);
    }

    #[Test]
    public function ci_never_echoes_a_secret(): void
    {
        foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $workflow) {
            $source = (string) file_get_contents($workflow);
            $name = basename($workflow);

            $this->assertStringNotContainsString(
                'set -x',
                $source,
                "{$name} traces commands, which prints every expanded variable including secrets.",
            );

            // `echo ${{ secrets.X }}` in any spelling.
            $this->assertSame(
                0,
                preg_match('/echo[^\n]*secrets\./', $source),
                "{$name} echoes a secret into the build log.",
            );

            $this->assertSame(
                0,
                preg_match('/(printenv|env\s*\|\s*(sort|cat|grep))/', $source),
                "{$name} dumps the environment into the build log.",
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function environmentValues(string $file): array
    {
        $values = [];

        foreach (file(base_path($file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\"'");
        }

        return $values;
    }
}

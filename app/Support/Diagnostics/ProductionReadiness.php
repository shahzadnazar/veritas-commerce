<?php

declare(strict_types=1);

namespace App\Support\Diagnostics;

use App\Modules\Payouts\Support\PayoutPolicy;
use App\Modules\Search\Adapters\PostgresSearchIndex;
use App\Support\Operations\Heartbeat;
use App\Support\Performance\PerformanceDataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Everything that must be true before this deployment takes real money.
 *
 * Written as a class rather than inside the command so the same checks
 * serve three callers: `app:production-check` in a deployment pipeline,
 * `app:pre-deploy` as a non-mutating gate, and the test suite. A check
 * somebody has to remember to run twice is a check that runs once.
 *
 * Two rules govern every method here.
 *
 * **Nothing mutates.** These run against production. They read
 * configuration, open a connection, count a row. They do not migrate,
 * seed, write a file or touch a queue.
 *
 * **Nothing discloses.** A secret is reported as "set, 32 characters" and
 * never as its value — a deployment log is not a secure place, and the
 * whole point of the check is to be safe to run where the secrets are.
 */
final class ProductionReadiness
{
    /**
     * Settings whose shipped defaults are placeholders rather than
     * decisions. §67: a production marketplace running with
     * `support@veritas.test` has not been configured, it has been
     * deployed.
     */
    private const PLACEHOLDER_MARKERS = [
        'veritas.test', 'example.com', 'example.org', 'localhost', '127.0.0.1',
        'changeme', 'placeholder', 'your-domain', 'todo',
    ];

    /** @return array<int, Check> */
    public function all(): array
    {
        return [
            ...$this->application(),
            ...$this->session(),
            ...$this->database(),
            ...$this->cacheAndQueue(),
            ...$this->storage(),
            ...$this->payments(),
            ...$this->mail(),
            ...$this->frontend(),
            ...$this->platformSettings(),
        ];
    }

    /** @return array<int, Check> */
    private function application(): array
    {
        $checks = [];
        $environment = (string) config('app.env');
        $isProduction = $environment === 'production';

        $checks[] = $isProduction
            ? Check::pass('application', 'APP_ENV', 'production')
            : Check::warn(
                'application',
                'APP_ENV',
                $environment,
                'Every check below is evaluated as if this were production; set APP_ENV=production before release.',
            );

        /*
         * The single most dangerous setting in the file. Debug in
         * production turns a stack trace, the environment array and every
         * connection string into a public web page.
         */
        $checks[] = config('app.debug') === true
            ? Check::fail('application', 'APP_DEBUG', 'enabled', 'Set APP_DEBUG=false. Debug output discloses configuration, paths and queries.')
            : Check::pass('application', 'APP_DEBUG', 'disabled');

        $key = (string) config('app.key');

        $checks[] = match (true) {
            $key === '' => Check::fail('application', 'APP_KEY', 'not set', 'Run php artisan key:generate. Sessions and encrypted values depend on it.'),
            ! str_starts_with($key, 'base64:') => Check::warn('application', 'APP_KEY', 'set, not base64-encoded'),
            default => Check::pass('application', 'APP_KEY', Check::describeSecret($key)),
        };

        $url = (string) config('app.url');

        $checks[] = match (true) {
            $url === '' => Check::fail('application', 'APP_URL', 'not set', 'Signed URLs, emails and the sitemap all build absolute links from this.'),
            $this->looksLocal($url) => Check::fail('application', 'APP_URL', 'points at a local address', 'Set APP_URL to the public https:// origin.'),
            ! str_starts_with($url, 'https://') => Check::fail('application', 'APP_URL', 'is not https', 'Secure cookies and HSTS depend on the site being served over TLS.'),
            default => Check::pass('application', 'APP_URL', $url),
        };

        $checks[] = (string) config('app.timezone') === 'UTC'
            ? Check::pass('application', 'timezone', 'UTC')
            : Check::warn('application', 'timezone', (string) config('app.timezone'), 'Business timestamps are stored in UTC; a different app timezone invites confusion.');

        return $checks;
    }

    /** @return array<int, Check> */
    private function session(): array
    {
        $checks = [];

        /*
         * A session cookie without Secure travels over plain HTTP the
         * first time a visitor types the domain without a scheme, and
         * that is the request an attacker on the network wants.
         */
        $checks[] = config('session.secure') === true
            ? Check::pass('session', 'secure cookie', 'enabled')
            : Check::fail('session', 'secure cookie', 'not enabled', 'Set SESSION_SECURE_COOKIE=true so the session cookie is never sent over plain HTTP.');

        $checks[] = config('session.http_only') === true
            ? Check::pass('session', 'httpOnly cookie', 'enabled')
            : Check::fail('session', 'httpOnly cookie', 'disabled', 'Set SESSION_HTTP_ONLY=true so script cannot read the session cookie.');

        $sameSite = (string) config('session.same_site');

        $checks[] = in_array($sameSite, ['lax', 'strict'], true)
            ? Check::pass('session', 'sameSite', $sameSite)
            : Check::fail('session', 'sameSite', $sameSite === '' ? 'not set' : $sameSite, 'Use lax or strict. "none" sends the session cookie on cross-site requests.');

        $driver = (string) config('session.driver');

        $checks[] = in_array($driver, ['redis', 'database'], true)
            ? Check::pass('session', 'driver', $driver)
            : Check::fail('session', 'driver', $driver, 'Use redis or database. A file or array driver does not survive more than one application server.');

        return $checks;
    }

    /** @return array<int, Check> */
    private function database(): array
    {
        $checks = [];
        $connection = (string) config('database.default');

        $checks[] = $connection === 'pgsql'
            ? Check::pass('database', 'connection', 'pgsql')
            : Check::warn('database', 'connection', $connection, 'The schema, constraints and reconciliations are written against PostgreSQL.');

        try {
            $started = microtime(true);
            DB::connection()->select('select 1');
            $ms = (int) round((microtime(true) - $started) * 1000);

            $checks[] = Check::pass('database', 'connectivity', "reachable in {$ms} ms");
        } catch (Throwable $exception) {
            $checks[] = Check::fail('database', 'connectivity', 'unreachable', $this->safeReason($exception));

            return $checks;
        }

        /*
         * Files on disk against rows in `migrations`, rather than the
         * migrator's own pending list: this has to work on a connection
         * the migrator has not been configured for, and comparing the two
         * sets is both simpler and harder to get subtly wrong.
         */
        try {
            $applied = DB::table('migrations')->pluck('migration')->all();

            $onDisk = array_map(
                static fn (string $path): string => basename($path, '.php'),
                glob(database_path('migrations/*.php')) ?: [],
            );

            $pending = array_values(array_diff($onDisk, $applied));

            $checks[] = $pending === []
                ? Check::pass('database', 'migrations', count($onDisk).' applied, none pending')
                : Check::fail(
                    'database',
                    'migrations',
                    count($pending).' pending',
                    'Run php artisan migrate before serving traffic. Oldest pending: '.$pending[0],
                );
        } catch (Throwable $exception) {
            $checks[] = Check::fail('database', 'migrations', 'could not be determined', $this->safeReason($exception));
        }

        /*
         * Has the scheduler run recently?
         *
         * A warning rather than a failure, because this check also runs
         * at deploy time on a machine where the scheduler has legitimately
         * never run. What it catches is the other case: a deployment that
         * has been up for hours with nothing driving the clearing sweep
         * or the expiry jobs, which degrades safely and completely
         * silently. `ops:queue-health` is the version of this an operator
         * runs on a schedule.
         */
        $silentFor = Heartbeat::minutesSince(Heartbeat::SCHEDULER);

        $checks[] = match (true) {
            $silentFor === null => Check::warn(
                'database',
                'scheduler heartbeat',
                'never recorded',
                'Nothing has driven a scheduled task yet. Earnings clearing and reservation expiry both depend on it.',
            ),
            $silentFor > 15 => Check::warn(
                'database',
                'scheduler heartbeat',
                "silent for {$silentFor} minutes",
                'Run php artisan ops:queue-health. Earnings will not clear and expired holds will not be released.',
            ),
            default => Check::pass('database', 'scheduler heartbeat', "{$silentFor} minute(s) ago"),
        };

        /*
         * The three timeouts PostgreSQL ships disabled.
         *
         * Checked as configuration rather than as a session setting,
         * because this command runs in the console and the console is
         * deliberately exempt from two of them — reading the live session
         * back here would report zero and mean nothing. What matters is
         * that a web request will get a limit, and that is decided by
         * config.
         */
        $timeouts = [
            'statement' => (int) config('veritas.database.timeouts.statement_ms', 0),
            'lock' => (int) config('veritas.database.timeouts.lock_ms', 0),
            'idle transaction' => (int) config('veritas.database.timeouts.idle_in_transaction_ms', 0),
        ];

        $unbounded = array_keys(array_filter($timeouts, static fn (int $ms): bool => $ms === 0));

        $checks[] = $unbounded === []
            ? Check::pass('database', 'query timeouts', implode(', ', array_map(
                static fn (int $ms, string $name): string => "{$name} {$ms}ms",
                $timeouts,
                array_keys($timeouts),
            )))
            : Check::warn(
                'database',
                'query timeouts',
                implode(' and ', $unbounded).' unbounded',
                'A statement with no time limit holds its connection until it finishes. With a hundred '
                .'connections, a few of those are an outage. Set DB_STATEMENT_TIMEOUT_MS, DB_LOCK_TIMEOUT_MS '
                .'and DB_IDLE_TRANSACTION_TIMEOUT_MS.',
            );

        /*
         * Fuzzy search reads its cutoff from the session, so the session
         * has to have one.
         *
         * The search adapter asks PostgreSQL for typo tolerance with the
         * `pg_trgm` operators rather than the equivalent functions,
         * because only the operators can use the trigram index — the
         * difference was a sequential scan of the whole document table on
         * every keyword search. The cost of that form is that the
         * threshold lives in a session setting, and a connection that
         * somehow missed it would search at PostgreSQL's default of 0.6
         * and quietly return fewer typo matches. Nobody would file a bug
         * for results that merely got narrower, so it is checked here.
         */
        try {
            $expected = PostgresSearchIndex::FUZZY_THRESHOLD;
            $actual = (float) DB::connection()->scalar("select current_setting('pg_trgm.word_similarity_threshold')");

            $checks[] = abs($actual - $expected) < 0.0001
                ? Check::pass('database', 'search threshold', sprintf('%.2F', $actual))
                : Check::fail(
                    'database',
                    'search threshold',
                    sprintf('%.2F, expected %.2F', $actual, $expected),
                    'Fuzzy search will return fewer results than intended. ConfigurePostgresSession sets this on '
                    .'every connection; check that the listener is registered.',
                );
        } catch (Throwable $exception) {
            $checks[] = Check::warn('database', 'search threshold', 'could not be read', $this->safeReason($exception));
        }

        /*
         * The scale dataset must never be here.
         *
         * `veritas:seed-performance` writes six hundred thousand
         * fictional rows — sellers, orders, a ledger — and refuses to run
         * anywhere production-looking. This is the other half of that:
         * the command's guard can only stop what it is asked to do, and
         * a database restored from the wrong dump was never asked. Four
         * small tables are probed rather than all thirteen, so the check
         * stays cheap enough to run on every deployment.
         */
        try {
            $marked = PerformanceDataset::contamination(DB::connection(), PerformanceDataset::SENTINEL_TABLES);

            $checks[] = $marked === []
                ? Check::pass('database', 'generated data', 'none present')
                : Check::fail(
                    'database',
                    'generated data',
                    sprintf('%s marked rows', implode(', ', array_map(
                        static fn (int $count, string $table): string => "{$count} in {$table}",
                        $marked,
                        array_keys($marked),
                    ))),
                    'This database holds rows from veritas:seed-performance. It is a scale-test database, not a production one.',
                );
        } catch (Throwable $exception) {
            $checks[] = Check::warn('database', 'generated data', 'could not be determined', $this->safeReason($exception));
        }

        return $checks;
    }

    /** @return array<int, Check> */
    private function cacheAndQueue(): array
    {
        $checks = [];

        try {
            Redis::connection()->ping();
            $checks[] = Check::pass('redis', 'connectivity', 'reachable');
        } catch (Throwable $exception) {
            $checks[] = Check::fail('redis', 'connectivity', 'unreachable', $this->safeReason($exception));
        }

        $queue = (string) config('queue.default');

        $checks[] = $queue === 'redis'
            ? Check::pass('queue', 'driver', 'redis')
            : Check::fail('queue', 'driver', $queue, 'Use redis. The sync driver runs jobs inside the web request; the null driver discards them.');

        $cache = (string) config('cache.default');

        $checks[] = in_array($cache, ['redis', 'database'], true)
            ? Check::pass('cache', 'driver', $cache)
            : Check::warn('cache', 'driver', $cache, 'An array or file cache is per-process and will not be shared between servers.');

        return $checks;
    }

    /** @return array<int, Check> */
    private function storage(): array
    {
        $checks = [];

        foreach (['public' => 'veritas.storage.public_disk', 'private' => 'veritas.storage.private_disk'] as $label => $key) {
            $disk = (string) config($key);

            if ($disk === '' || config("filesystems.disks.{$disk}") === null) {
                $checks[] = Check::fail('storage', "{$label} disk", $disk === '' ? 'not set' : "unknown disk \"{$disk}\"");

                continue;
            }

            $driver = (string) config("filesystems.disks.{$disk}.driver");

            $checks[] = $driver === 's3'
                ? Check::pass('storage', "{$label} disk", "{$disk} (s3)")
                : Check::warn(
                    'storage',
                    "{$label} disk",
                    "{$disk} ({$driver})",
                    'Local disks do not survive a container restart and are not shared between servers.',
                );

            if ($driver === 's3') {
                $checks[] = Check::pass(
                    'storage',
                    "{$label} credentials",
                    'key '.Check::describeSecret(config("filesystems.disks.{$disk}.key")).
                    ', secret '.Check::describeSecret(config("filesystems.disks.{$disk}.secret")),
                );
            }
        }

        /*
         * A read is the only way to know the binding actually works, and
         * it is safe: listing a prefix that does not exist mutates
         * nothing.
         */
        try {
            Storage::disk((string) config('veritas.storage.private_disk'))->files('__production_check__');
            $checks[] = Check::pass('storage', 'private disk reachable', 'listed without error');
        } catch (Throwable $exception) {
            /*
             * A failure, not a warning.
             *
             * This is the disk that holds seller identity documents. A
             * deployment that cannot list it cannot accept an application,
             * cannot show a reviewer a passport, and — the M9 failure
             * drills found this one — cannot clean up an orphaned upload
             * either. Warning about it would let a release go out that
             * silently could not do KYC.
             */
            $checks[] = Check::fail(
                'storage',
                'private disk reachable',
                'could not list',
                $this->safeReason($exception).' Seller documents cannot be stored or read.',
            );
        }

        return $checks;
    }

    /** @return array<int, Check> */
    private function payments(): array
    {
        $provider = (string) config('veritas.payments.provider');

        if ($provider !== 'stripe') {
            return [Check::fail(
                'payments',
                'provider',
                $provider,
                'The fake provider accepts every payment without charging anybody. It must never serve real customers.',
            )];
        }

        $checks = [Check::pass('payments', 'provider', 'stripe')];

        foreach (['key' => 'publishable key', 'secret' => 'secret key', 'webhook_secret' => 'webhook signing secret'] as $key => $label) {
            $value = config("veritas.payments.stripe.{$key}");

            $checks[] = is_string($value) && $value !== ''
                ? Check::pass('payments', $label, Check::describeSecret($value))
                : Check::fail('payments', $label, 'not set', 'Without it, verified provider events cannot be accepted.');
        }

        // A live key in a check that is otherwise about test readiness is
        // worth saying out loud, in both directions.
        $secret = (string) config('veritas.payments.stripe.secret');

        if (str_starts_with($secret, 'sk_test_')) {
            $checks[] = Check::warn('payments', 'key mode', 'test mode', 'Real customers cannot be charged with a test key.');
        } elseif (str_starts_with($secret, 'sk_live_')) {
            $checks[] = Check::pass('payments', 'key mode', 'live mode');
        }

        return $checks;
    }

    /** @return array<int, Check> */
    private function mail(): array
    {
        $checks = [];
        $mailer = (string) config('mail.default');

        $checks[] = in_array($mailer, ['log', 'array'], true)
            ? Check::fail('mail', 'mailer', $mailer, 'Verification, receipts and payout notifications would never leave the server.')
            : Check::pass('mail', 'mailer', $mailer);

        $from = (string) config('mail.from.address');

        $checks[] = match (true) {
            $from === '' => Check::fail('mail', 'from address', 'not set'),
            $this->looksLikePlaceholder($from) => Check::fail('mail', 'from address', 'still a placeholder', 'Set a deliverable address on a domain you control.'),
            default => Check::pass('mail', 'from address', $from),
        };

        return $checks;
    }

    /** @return array<int, Check> */
    private function frontend(): array
    {
        $checks = [];

        $manifest = public_path('build/manifest.json');

        $checks[] = is_file($manifest)
            ? Check::pass('frontend', 'client build', 'manifest present')
            : Check::fail('frontend', 'client build', 'manifest missing', 'Run npm run build. Without it every asset URL 404s.');

        if (config('inertia.ssr.enabled') !== true) {
            $checks[] = Check::warn(
                'frontend',
                'SSR',
                'disabled',
                'The storefront is the SEO surface; without SSR a crawler sees an empty shell.',
            );

            return $checks;
        }

        $bundle = base_path('bootstrap/ssr/ssr.js');

        $checks[] = is_file($bundle)
            ? Check::pass('frontend', 'SSR bundle', 'present')
            : Check::fail('frontend', 'SSR bundle', 'missing', 'Run npm run build:ssr. SSR is enabled but has nothing to run.');

        $url = (string) config('inertia.ssr.url');

        $checks[] = $url === ''
            ? Check::fail('frontend', 'SSR url', 'not set')
            : Check::pass('frontend', 'SSR url', $url);

        return $checks;
    }

    /**
     * Platform settings that ship with placeholder defaults.
     *
     * §67: these are not secrets and not connectivity — they are the
     * decisions a marketplace has to make before it trades, and shipping
     * the defaults means nobody made them.
     *
     * @return array<int, Check>
     */
    private function platformSettings(): array
    {
        $checks = [];

        foreach ([
            'display name' => 'veritas.identity.display_name',
            'legal name' => 'veritas.identity.legal_name',
            'support email' => 'veritas.identity.support_email',
            'billing email' => 'veritas.identity.billing_email',
            'business address' => 'veritas.identity.business_address',
        ] as $label => $key) {
            $value = (string) config($key);

            $checks[] = match (true) {
                $value === '' => Check::fail('platform', $label, 'not set'),
                $this->looksLikePlaceholder($value) => Check::warn('platform', $label, 'still the shipped placeholder', 'Customers and sellers see this.'),
                default => Check::pass('platform', $label, $value),
            };
        }

        $commission = config('veritas.commission.default_rate_percent');

        $checks[] = is_numeric($commission) && (float) $commission > 0
            ? Check::pass('platform', 'commission rate', $commission.'%')
            : Check::fail('platform', 'commission rate', 'not configured', 'Every order splits money by this rate.');

        $minimum = PayoutPolicy::minimumMinor();

        $checks[] = $minimum > 0
            ? Check::pass('platform', 'payout minimum', (string) $minimum.' minor units')
            : Check::warn('platform', 'payout minimum', 'zero', 'Every settlement costs a person a few minutes and a bank a fee.');

        $clearing = config('veritas.payouts.seller_clearing_period_days');

        $checks[] = is_numeric($clearing) && (int) $clearing >= 0
            ? Check::pass('platform', 'clearing period', $clearing.' days')
            : Check::fail('platform', 'clearing period', 'not configured');

        return $checks;
    }

    private function looksLocal(string $value): bool
    {
        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host)
            && (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true) || str_ends_with($host, '.test'));
    }

    private function looksLikePlaceholder(string $value): bool
    {
        $haystack = mb_strtolower($value);

        foreach (self::PLACEHOLDER_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why something failed, without the connection string in it.
     *
     * An exception message from a database driver routinely contains the
     * host, the user and sometimes the password. The class name and a
     * short, cleaned first line are enough to act on.
     */
    private function safeReason(Throwable $exception): string
    {
        $first = trim(strtok($exception->getMessage(), "\n") ?: '');
        $redacted = preg_replace('/(password|passwd|pwd|secret|token)=\S+/i', '$1=[redacted]', $first) ?? '';

        return class_basename($exception).': '.mb_substr($redacted, 0, 160);
    }
}

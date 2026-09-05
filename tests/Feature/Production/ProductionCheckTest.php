<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Support\Diagnostics\Check;
use App\Support\Diagnostics\ProductionReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M9 property 5: a production deployment cannot silently boot unsafely.
 *
 * "Silently" is the load-bearing word. The failure this prevents is not
 * somebody choosing to run with debug on — it is nobody noticing that
 * they are, because nothing said so and the site looked fine.
 *
 * Every test below configures the unsafe state and asserts the command
 * exits non-zero. The last one asserts the other half of the contract:
 * that it does so without printing the secrets it inspected, because a
 * check nobody dares run where the credentials live is a check that never
 * runs at all.
 */
final class ProductionCheckTest extends TestCase
{
    use RefreshDatabase;

    /** Everything a real deployment would have set correctly. */
    private function safeConfiguration(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://veritas.example',
            'app.timezone' => 'UTC',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.driver' => 'redis',
            'database.default' => 'pgsql',
            'queue.default' => 'redis',
            'cache.default' => 'redis',
            'veritas.payments.provider' => 'stripe',
            'veritas.payments.stripe.key' => 'pk_live_'.str_repeat('a', 24),
            'veritas.payments.stripe.secret' => 'sk_live_'.str_repeat('b', 24),
            'veritas.payments.stripe.webhook_secret' => 'whsec_'.str_repeat('c', 32),
            'mail.default' => 'ses',
            'mail.from.address' => 'orders@veritas.example',
            'veritas.identity.support_email' => 'support@veritas.example',
            'veritas.identity.billing_email' => 'billing@veritas.example',
            'veritas.identity.business_address' => '1 Real Street, Portland, OR 97232',
        ]);
    }

    /** @return array<string, Check> keyed by "group.name" */
    private function checks(): array
    {
        $keyed = [];

        foreach (app(ProductionReadiness::class)->all() as $check) {
            $keyed[$check->group.'.'.$check->name] = $check;
        }

        return $keyed;
    }

    private function statusOf(string $key): string
    {
        $checks = $this->checks();

        $this->assertArrayHasKey($key, $checks, "No check named {$key}.");

        return $checks[$key]->status;
    }

    // ---------------------------------------------------------------
    // The unsafe states that must stop a release.
    // ---------------------------------------------------------------

    #[Test]
    public function debug_mode_in_production_fails_the_check(): void
    {
        $this->safeConfiguration();
        config(['app.debug' => true]);

        $this->assertSame(Check::FAIL, $this->statusOf('application.APP_DEBUG'));
        $this->assertSame(1, Artisan::call('app:production-check'));
    }

    #[Test]
    public function a_missing_or_local_app_url_fails_the_check(): void
    {
        $this->safeConfiguration();

        foreach (['', 'http://localhost:8000', 'https://veritas.test', 'http://veritas.example'] as $url) {
            config(['app.url' => $url]);

            $this->assertSame(
                Check::FAIL,
                $this->statusOf('application.APP_URL'),
                "APP_URL of \"{$url}\" should not be accepted.",
            );
        }
    }

    #[Test]
    public function an_insecure_session_cookie_fails_the_check(): void
    {
        $this->safeConfiguration();
        config(['session.secure' => false]);

        $this->assertSame(Check::FAIL, $this->statusOf('session.secure cookie'));
        $this->assertSame(1, Artisan::call('app:production-check'));
    }

    #[Test]
    public function the_fake_payment_provider_fails_the_check(): void
    {
        $this->safeConfiguration();
        config(['veritas.payments.provider' => 'fake']);

        $this->assertSame(
            Check::FAIL,
            $this->statusOf('payments.provider'),
            'The fake provider accepts every payment without charging anybody.',
        );
        $this->assertSame(1, Artisan::call('app:production-check'));
    }

    #[Test]
    public function a_missing_webhook_secret_fails_the_check(): void
    {
        $this->safeConfiguration();
        config(['veritas.payments.stripe.webhook_secret' => '']);

        $this->assertSame(Check::FAIL, $this->statusOf('payments.webhook signing secret'));
        $this->assertSame(1, Artisan::call('app:production-check'));
    }

    #[Test]
    public function a_mailer_that_does_not_send_fails_the_check(): void
    {
        $this->safeConfiguration();

        foreach (['log', 'array'] as $mailer) {
            config(['mail.default' => $mailer]);

            $this->assertSame(Check::FAIL, $this->statusOf('mail.mailer'));
        }
    }

    #[Test]
    public function a_sync_or_null_queue_fails_the_check(): void
    {
        $this->safeConfiguration();

        foreach (['sync', 'null', 'database'] as $driver) {
            config(['queue.default' => $driver]);

            $this->assertSame(
                Check::FAIL,
                $this->statusOf('queue.driver'),
                "A {$driver} queue is not a production queue.",
            );
        }
    }

    #[Test]
    public function unset_business_settings_fail_the_check(): void
    {
        $this->safeConfiguration();
        config(['veritas.commission.default_rate_percent' => null]);

        $this->assertSame(Check::FAIL, $this->statusOf('platform.commission rate'));
    }

    // ---------------------------------------------------------------
    // The safe state.
    // ---------------------------------------------------------------

    #[Test]
    public function a_correctly_configured_deployment_passes(): void
    {
        $this->safeConfiguration();

        $failures = array_filter(
            $this->checks(),
            static fn (Check $check): bool => $check->isFailure(),
        );

        $this->assertSame(
            [],
            array_map(static fn (Check $check): string => $check->group.'.'.$check->name.': '.$check->detail, $failures),
        );

        $this->assertSame(0, Artisan::call('app:production-check'));
    }

    #[Test]
    public function warnings_do_not_block_a_release(): void
    {
        $this->safeConfiguration();

        // A real preference, not an unsafe state: the operator should read
        // it, and a hotfix should not be held hostage to it.
        config(['veritas.identity.support_email' => 'support@veritas.test']);

        $this->assertSame(Check::WARN, $this->statusOf('platform.support email'));
        $this->assertSame(0, Artisan::call('app:production-check'));
    }

    // ---------------------------------------------------------------
    // The other half of the contract: it discloses nothing.
    // ---------------------------------------------------------------

    #[Test]
    public function the_output_never_contains_a_secret_value(): void
    {
        $this->safeConfiguration();

        $secrets = [
            'sk_live_'.str_repeat('b', 24),
            'whsec_'.str_repeat('c', 32),
            (string) config('app.key'),
        ];

        Artisan::call('app:production-check');
        $human = Artisan::output();

        Artisan::call('app:production-check', ['--json' => true]);
        $json = Artisan::output();

        foreach ([$human, $json] as $output) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $output,
                    'The production check printed a secret it was inspecting.',
                );
            }
        }

        // It still says enough to act on.
        $this->assertStringContainsString('characters', $human);
    }

    /**
     * A driver's exception message routinely carries the host, the user
     * and sometimes the password. The check has to survive being run
     * against a database it cannot reach, and say so without quoting the
     * credential back into a deployment log.
     *
     * Pointed at a spare connection rather than at the live one: purging
     * the connection this test's transaction is running on would take the
     * rollback down with it, and the test would then be measuring its own
     * teardown.
     */
    #[Test]
    public function a_connection_failure_is_reported_without_the_credentials(): void
    {
        $this->safeConfiguration();

        config([
            'database.connections.unreachable' => [
                ...config('database.connections.pgsql'),
                'host' => '127.0.0.1',
                'port' => 1,
                'password' => 'super-secret-password',
            ],
            'database.default' => 'unreachable',
        ]);

        try {
            $connectivity = $this->checks()['database.connectivity'];
        } finally {
            config(['database.default' => 'pgsql']);
        }

        $this->assertSame(Check::FAIL, $connectivity->status);
        $this->assertStringContainsString('unreachable', $connectivity->detail);
        $this->assertStringNotContainsString(
            'super-secret-password',
            $connectivity->detail.' '.(string) $connectivity->remedy,
        );
    }

    // ---------------------------------------------------------------
    // The pre-deploy sibling.
    // ---------------------------------------------------------------

    #[Test]
    public function the_pre_deploy_check_ignores_business_configuration(): void
    {
        $this->safeConfiguration();

        // Unsafe to launch with, irrelevant to whether the deploy works.
        config(['veritas.identity.support_email' => 'support@veritas.test']);

        $this->assertSame(0, Artisan::call('app:pre-deploy'));

        // But a broken artifact does stop it.
        config(['inertia.ssr.enabled' => true]);
        config(['app.debug' => true]);

        $this->assertSame(1, Artisan::call('app:pre-deploy'));
    }

    #[Test]
    public function neither_check_mutates_anything(): void
    {
        $this->safeConfiguration();

        $before = $this->snapshot();

        Artisan::call('app:production-check');
        Artisan::call('app:pre-deploy');

        $this->assertSame($before, $this->snapshot(), 'A readiness check wrote to the database.');
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        $counts = [];

        foreach (['users', 'marketplace_orders', 'payments', 'seller_ledger_entries', 'migrations'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}

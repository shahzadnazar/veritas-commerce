<?php

declare(strict_types=1);

namespace Tests\Feature\Failure;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;
use Throwable;

/**
 * The log is broken. The thing it was about must survive anyway.
 *
 * A logger that throws is a second exception raised at the exact moment
 * the first one is being handled, and it lands in the one place where
 * that is most expensive: the `catch` block of a financial operation.
 * The failure mode is not "no log line" — it is the original error being
 * replaced by a filesystem error, so the payment failure that mattered is
 * reported as a permissions problem and investigated as one.
 *
 * The seam is a real unwritable path rather than a mock, because the
 * failure being tested is Monolog's, and stubbing Monolog would test the
 * stub. It doubles as the disk-write drill: a log channel that cannot
 * open its file behaves exactly as a full disk does.
 */
final class LoggingFailureTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    /**
     * Point logging at a path that cannot be opened.
     *
     * `/proc` is read-only on Linux and refuses a directory, so Monolog
     * fails to create the stream — the same class of failure as a full
     * disk or a revoked permission, without needing either.
     */
    private function breakLogging(): void
    {
        config([
            'logging.default' => 'drill_unwritable',
            'logging.channels.drill_unwritable' => [
                'driver' => 'single',
                'path' => '/proc/veritas-drill/laravel.log',
                'level' => 'debug',
            ],
        ]);

        // The application's own `stack` swallows a broken handler on
        // purpose; this channel does not, because the point of the drill
        // is a logger that really throws.

        $this->app->forgetInstance('log');
        Log::clearResolvedInstances();
    }

    /** The seam works: writing really does throw. */
    #[Test]
    public function the_drill_actually_breaks_logging(): void
    {
        $this->breakLogging();

        $raised = false;

        try {
            Log::error('This must not be writable.');
        } catch (Throwable) {
            $raised = true;
        }

        $this->assertTrue($raised, 'The unwritable channel accepted a write; the drill would prove nothing.');
    }

    /**
     * A payment that the provider and the platform disagree about is
     * still recorded as failed when the log cannot be written.
     *
     * The order of the two operations is what makes this safe: the event
     * row is updated first and logged second, so a broken logger costs
     * the log line and not the evidence.
     */
    #[Test]
    public function a_verification_failure_is_still_recorded_when_logging_is_broken(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        ['reference' => $reference] = $this->prepare($order);

        $provider = $this->provider();
        $provider->settle($reference, PaymentAttemptStatus::Succeeded);

        // The provider now claims a different amount than the order says.
        $provider->tamperAmount($reference, 1);

        $this->breakLogging();

        $signed = $provider->signedEvent('payment_intent.succeeded', $provider->paymentObject($reference));

        try {
            $this->call(
                'POST',
                '/webhooks/payments',
                server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
                content: $signed['payload'],
            );
        } catch (Throwable) {
            /*
             * The logging failure escapes, and that is the finding rather
             * than the assertion: Laravel's handler calls the logger while
             * reporting, so a handler that throws replaces the exception it
             * was describing. The application's `stack` channel now sets
             * `ignore_exceptions`, which is what stops that in production;
             * this channel deliberately does not, so the drill still
             * exercises a logger that really fails.
             *
             * What has to survive either way is below.
             */
        }

        /** @var ProviderWebhookEvent $event */
        $event = ProviderWebhookEvent::query()->firstOrFail();

        $this->assertSame('failed', $event->status->value, 'A broken log erased the record of a failed payment.');
        $this->assertNotNull($event->error, 'The failure reason was lost with the log line.');

        // And nothing was paid on the strength of a disagreement.
        $this->assertSame('pending_payment', $order->refresh()->status->value);
        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * The application's configured stack does not do that.
     *
     * `ignore_exceptions` on the stack channel is the production answer:
     * a handler that cannot write drops its lines, the others in the
     * stack still write, and the exception being reported survives.
     */
    #[Test]
    public function the_configured_log_stack_swallows_a_broken_handler(): void
    {
        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['drill_unwritable'],
            'logging.channels.drill_unwritable' => [
                'driver' => 'single',
                'path' => '/proc/veritas-drill/laravel.log',
                'level' => 'debug',
            ],
        ]);

        $this->app->forgetInstance('log');
        Log::clearResolvedInstances();

        $this->assertTrue(config('logging.channels.stack.ignore_exceptions'));

        // No exception: the stack absorbs the handler that cannot write.
        Log::error('This line is lost, and that is the point.');

        $this->addToAssertionCount(1);
    }

    /**
     * And a successful payment is unaffected by a broken log.
     *
     * Logging is not on the path that moves money, and this is the
     * assertion that says so rather than assuming it.
     */
    #[Test]
    public function a_payment_still_completes_when_logging_is_broken(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);

        $this->breakLogging();

        $this->payFor($order);

        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('seller_ledger_entries')->where('type', 'sale_earning')->count());
    }
}

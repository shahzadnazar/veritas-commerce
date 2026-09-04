<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ProviderEventStatus;
use App\Modules\Payments\Jobs\ProcessProviderEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use App\Support\Queues;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * §62–§65 against a real Redis and a real worker.
 *
 * The rest of the payment suite runs on the sync driver, which proves what
 * a job does but not that it can be serialised, enqueued, drained,
 * retried, and — when it genuinely cannot succeed — filed somewhere a
 * person will see it. For payments that last part is the whole point: a
 * provider event dropped in silence is money that moved with no record of
 * where it went.
 */
final class PaymentQueueRuntimeTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();

        // Its own Redis database, flushed each time, so a developer's
        // running Horizon cannot eat the test's jobs and vice versa.
        Redis::connection('default')->client()->flushdb();
        Cache::flush();
    }

    #[Test]
    public function a_webhook_enqueues_its_work_and_a_worker_finishes_it(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        // Redis, from this point on: the request must hand the work over
        // rather than do it.
        config(['queue.default' => 'redis']);

        $provider = $this->provider();
        $signed = $provider->signedEvent('payment_intent.succeeded', $provider->paymentObject($reference));

        $this->call(
            'POST',
            '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
            content: $signed['payload'],
        )->assertOk();

        /*
         * §62's ordering, visible: the event is verified and persisted
         * inside the request, and the work is on the wire. A 200 returned
         * before the signature was checked would be the platform telling
         * the provider "got it" about something it had not looked at.
         */
        $this->assertSame(1, ProviderWebhookEvent::query()->count());
        $this->assertSame(ProviderEventStatus::Received, ProviderWebhookEvent::query()->firstOrFail()->status);
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);

        $this->assertSame(1, Queue::connection('redis')->size(Queues::PAYMENTS));
        $this->assertSame(0, Queue::connection('redis')->size(Queues::CRITICAL));
        $this->assertSame(0, Queue::connection('redis')->size(Queues::EMAILS));

        $this->workOnce(Queues::PAYMENTS);

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(ProviderEventStatus::Processed, ProviderWebhookEvent::query()->firstOrFail()->status);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(0, Queue::connection('redis')->size(Queues::PAYMENTS));
    }

    #[Test]
    public function running_the_same_queued_job_again_changes_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        config(['queue.default' => 'redis']);

        // A worker that crashed after finishing the work but before
        // acknowledging leaves exactly this: the same job, queued again.
        ProcessProviderEvent::dispatch($event->id);
        ProcessProviderEvent::dispatch($event->id);

        $this->workOnce(Queues::PAYMENTS);
        $this->workOnce(Queues::PAYMENTS);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(4_000, (int) Payment::query()->firstOrFail()->amount_minor);
        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
    }

    #[Test]
    public function an_event_the_platform_cannot_finish_stays_failed_and_retryable(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        // Redis first, so the request hands the work over rather than
        // doing it, and the provider goes away before the worker asks —
        // a transient fault rather than a disagreement about money, which
        // is exactly what retries exist for.
        config(['queue.default' => 'redis']);

        $this->provider()->goOffline();

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        $this->assertSame(ProviderEventStatus::Received, $event->status, 'Verified and stored before any work.');

        $this->workOnce(Queues::PAYMENTS);

        /*
         * §63. Not lost, not silently dropped, and not left looking
         * healthy: the row says failed with the reason, which is the
         * column the admin event screen reads, and the job is still on
         * the queue rather than gone.
         */
        $failedEvent = ProviderWebhookEvent::query()->firstOrFail();

        $this->assertSame(ProviderEventStatus::Failed, $failedEvent->status);
        $this->assertNotNull($failedEvent->failed_at);
        $this->assertStringContainsString('ProviderUnavailable', (string) $failedEvent->error);

        $this->assertSame(1, (int) $failedEvent->attempts);

        $this->assertSame(1, Queue::connection('redis')->size(Queues::PAYMENTS), 'Still queued, not discarded.');

        // Nothing partial was written on the way down.
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);

        // The provider comes back and the same stored event goes through,
        // because a failed row is claimable again.
        $this->provider()->comeBackOnline();
        $this->reprocess($failedEvent);

        $this->assertSame(ProviderEventStatus::Processed, $failedEvent->refresh()->status);
        $this->assertSame(2, (int) $failedEvent->attempts, 'Every pass is counted.');
        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(1, Payment::query()->count());
    }

    #[Test]
    public function payment_events_are_retried_far_longer_than_anything_else(): void
    {
        $job = new ProcessProviderEvent(1);

        /*
         * §64. A provider event that cannot be processed is not lost work
         * to be dropped after two attempts — it is money that has moved,
         * and it stays retryable until a person has seen it. The backoff
         * widens so a sustained provider outage is not hammered.
         */
        $this->assertSame(Queues::PAYMENTS, $job->queue);
        $this->assertSame(8, $job->tries);
        $this->assertSame([5, 15, 60, 300, 900], $job->backoff);

        // And it is more patient than the next most urgent lane.
        $this->assertGreaterThan(
            (int) config('horizon.defaults.critical.tries'),
            (int) config('horizon.defaults.payments.tries'),
        );
    }

    /** Run exactly one job, the way a worker process would. */
    private function workOnce(string $queue): void
    {
        $this->app[Kernel::class]->call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--once' => true,
        ]);
    }
}

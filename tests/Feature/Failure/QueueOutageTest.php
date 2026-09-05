<?php

declare(strict_types=1);

namespace Tests\Feature\Failure;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\Support\Failure\BreaksInfrastructure;
use Tests\TestCase;

/**
 * A payment arrives during a Redis outage. Where does it go?
 *
 * This is the drill that found the worst defect of the M9 failure block,
 * so the sequence it walks is worth stating exactly.
 *
 * The webhook endpoint commits the signed event row and *then* queues the
 * work. That order is right: the durable record exists before anything
 * can go wrong with the queue. But it leaves a window. With Redis away
 * for the second the push runs, the row is committed, `dispatch()`
 * throws, and the endpoint answers 500 — which is correct, because it
 * invites the provider to redeliver.
 *
 * The provider then redelivered, and the endpoint said "already
 * received", 200, and queued nothing. The provider, satisfied, stopped
 * retrying. No worker ever held the job. A customer who had genuinely
 * paid stayed in `pending_payment` for ever, and no reconciliation could
 * find it because every table involved was internally consistent — the
 * order was simply never paid.
 *
 * Two things close it, and both are exercised here: a redelivery of an
 * event that is still merely `received` is queued again, and
 * `payments:replay-stranded` sweeps up the rows that no redelivery ever
 * comes back for.
 */
final class QueueOutageTest extends TestCase
{
    use BreaksInfrastructure;
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    /** @return array{order: MarketplaceOrder, deliver: Closure} */
    private function paidAtTheProvider(): array
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        ['reference' => $reference] = $this->prepare($order);

        $provider = $this->provider();
        $provider->settle($reference, PaymentAttemptStatus::Succeeded);

        $signed = $provider->signedEvent('payment_intent.succeeded', $provider->paymentObject($reference));

        // One signed delivery, redeliverable as many times as the drill
        // needs — which is what a provider retry actually is.
        $deliver = fn (): int => $this->call(
            'POST',
            '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
            content: $signed['payload'],
        )->getStatusCode();

        return ['order' => $order, 'deliver' => $deliver];
    }

    /**
     * The endpoint fails loudly rather than accepting work it cannot queue.
     *
     * A 200 here would be a lie the provider believes.
     */
    #[Test]
    public function a_webhook_that_cannot_be_queued_is_not_acknowledged(): void
    {
        ['deliver' => $deliver] = $this->paidAtTheProvider();

        $status = $this->withRedisDown(static fn (): int => $deliver());

        $this->assertSame(500, $status, 'The provider was told its delivery succeeded when nothing was queued.');
    }

    /** The signed evidence survives the outage even though the work did not. */
    #[Test]
    public function the_signed_event_is_recorded_even_when_the_queue_is_gone(): void
    {
        ['deliver' => $deliver] = $this->paidAtTheProvider();

        $this->withRedisDown(static fn (): int => $deliver());

        $this->assertDatabaseCount('provider_webhook_events', 1);
        $this->assertDatabaseHas('provider_webhook_events', ['status' => 'received', 'attempts' => 0]);
    }

    /**
     * Nothing financial was invented from a delivery nobody processed.
     */
    #[Test]
    public function no_money_moves_while_the_queue_is_gone(): void
    {
        ['order' => $order, 'deliver' => $deliver] = $this->paidAtTheProvider();

        $this->withRedisDown(static fn (): int => $deliver());

        $this->assertSame('pending_payment', $order->refresh()->status->value);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
    }

    /**
     * The regression. A redelivery after recovery finalises the payment.
     *
     * Before the fix this returned 200 and queued nothing, and the
     * payment was lost permanently.
     */
    #[Test]
    public function a_redelivery_after_recovery_finalises_the_payment(): void
    {
        ['order' => $order, 'deliver' => $deliver] = $this->paidAtTheProvider();

        $this->assertSame(500, $this->withRedisDown(static fn (): int => $deliver()));
        $this->assertSame(200, $deliver());

        $order->refresh();

        $this->assertSame('paid', $order->status->value, 'The payment was lost by a momentary queue outage.');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('provider_webhook_events', ['status' => 'processed']);
    }

    /**
     * And further redeliveries do not pay twice.
     *
     * The requeue is only safe because `ProcessProviderEvent` claims its
     * event with a conditional UPDATE; this is the assertion that the
     * claim is doing that work.
     */
    #[Test]
    public function repeated_redeliveries_produce_exactly_one_financial_effect(): void
    {
        ['order' => $order, 'deliver' => $deliver] = $this->paidAtTheProvider();

        $this->withRedisDown(static fn (): int => $deliver());

        foreach (range(1, 4) as $_) {
            $this->assertSame(200, $deliver());
        }

        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame(1, DB::table('seller_ledger_entries')->where('type', 'sale_earning')->count());
        $this->assertSame(1, DB::table('platform_revenue_entries')->where('type', 'commission')->count());
    }

    /**
     * The provider gives up. The sweep still finds the payment.
     *
     * This is the half the endpoint cannot fix: the delivery that failed
     * can be the last one the provider ever sends. `received` plus enough
     * elapsed time means nothing is coming, and the row is the only
     * evidence that a customer paid.
     */
    #[Test]
    public function the_sweep_recovers_an_event_no_redelivery_ever_comes_back_for(): void
    {
        ['order' => $order, 'deliver' => $deliver] = $this->paidAtTheProvider();

        $this->withRedisDown(static fn (): int => $deliver());

        // Nothing has changed yet, and nothing will without the sweep.
        $this->assertSame('pending_payment', $order->refresh()->status->value);

        $this->travel(20)->minutes();

        $this->runArtisan('payments:replay-stranded')->assertSuccessful()
            ->run();

        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertDatabaseCount('payments', 1);
    }

    /**
     * The sweep leaves alone what is merely in flight.
     *
     * An event queued thirty seconds ago is not stranded, it is waiting.
     * Requeueing it would be a second retry loop racing the first.
     */
    #[Test]
    public function the_sweep_ignores_an_event_that_is_still_in_flight(): void
    {
        ['deliver' => $deliver] = $this->paidAtTheProvider();

        $this->withRedisDown(static fn (): int => $deliver());

        $this->runArtisan('payments:replay-stranded')
            ->expectsOutputToContain('No stranded provider events.')
            ->assertSuccessful()
            ->run();
    }

    /**
     * And it leaves alone what a person has to look at.
     *
     * A `failed` event has already been through the queue's retries; a
     * sweep that requeued those would hide an event that needs a decision
     * behind an endless retry loop.
     */
    #[Test]
    public function the_sweep_ignores_an_event_that_already_failed(): void
    {
        ['deliver' => $deliver] = $this->paidAtTheProvider();

        $this->withRedisDown(static fn (): int => $deliver());

        ProviderWebhookEvent::query()->update(['status' => 'failed', 'failed_at' => now()]);

        $this->travel(20)->minutes();

        $this->runArtisan('payments:replay-stranded')
            ->expectsOutputToContain('No stranded provider events.')
            ->assertSuccessful()
            ->run();

        $this->assertDatabaseHas('provider_webhook_events', ['status' => 'failed']);
    }

    /**
     * The sweep is safe to run twice, which is what a scheduler that
     * overlapped itself would do.
     */
    #[Test]
    public function running_the_sweep_twice_pays_once(): void
    {
        ['order' => $order, 'deliver' => $deliver] = $this->paidAtTheProvider();

        $this->withRedisDown(static fn (): int => $deliver());
        $this->travel(20)->minutes();

        $this->runArtisan('payments:replay-stranded')->assertSuccessful()
            ->run();
        $this->runArtisan('payments:replay-stranded')->assertSuccessful()
            ->run();

        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('seller_ledger_entries')->where('type', 'sale_earning')->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Failure;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Models\PayoutRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\Feature\Payouts\BuildsSellerFinance;
use Tests\Support\Failure\BreaksInfrastructure;
use Tests\TestCase;
use Throwable;

/**
 * Redis is gone. Nothing it holds is allowed to be authoritative.
 *
 * The cache holds derived answers and the queue holds intentions; neither
 * holds truth. So the test of a Redis outage is not "does the site still
 * work" — parts of it should not — but "did anything Redis was holding
 * turn out to have been the only copy of something that mattered".
 *
 * The queue half of that question found a genuine defect, which lives in
 * `QueueOutageTest`. This covers the cache half, and the post-commit
 * side-effect question the M9 brief asks: when a transaction commits and
 * the notification about it cannot be queued, what is left?
 */
final class RedisOutageTest extends TestCase
{
    use BreaksInfrastructure;
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsSellerFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    /**
     * Stock is a database row, so a cache outage cannot move it.
     *
     * Worth asserting rather than assuming: `reserved` is a stored column
     * kept for read performance, and a system that had ever been tempted
     * to keep it in the cache instead would oversell during exactly this
     * incident.
     */
    #[Test]
    public function a_cache_outage_cannot_corrupt_inventory(): void
    {
        ['offer' => $offer, 'product' => $product] = $this->sellableOffer();

        $before = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        $this->withRedisDown(function () use ($product): void {
            try {
                $this->get('/products/'.$product->slug);
            } catch (Throwable) {
                // The page may fail; the drill is about the stock row.
            }
        });

        $after = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        $this->assertEquals($before?->on_hand, $after?->on_hand);
        $this->assertEquals($before?->reserved, $after?->reserved);
        $this->assertEquals($before?->available, $after?->available);
    }

    /**
     * And no order, payment or ledger row appears or changes.
     *
     * A whole-table fingerprint rather than a count: a cache outage that
     * somehow rewrote a row would keep the count identical.
     */
    #[Test]
    public function a_cache_outage_cannot_touch_orders_payments_or_the_ledger(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $before = $this->financialFingerprint();

        $this->withRedisDown(function () use ($order): void {
            foreach (['/products', '/search?q=kettle', '/orders/'.$order->reference] as $path) {
                try {
                    $this->get($path);
                } catch (Throwable) {
                    // Degrading is allowed. Corrupting is not.
                }
            }
        });

        $this->assertSame($before, $this->financialFingerprint());
    }

    /** @return array<string, string> */
    private function financialFingerprint(): array
    {
        $tables = [
            'marketplace_orders', 'seller_orders', 'order_items', 'payments',
            'payment_transactions', 'seller_ledger_entries', 'platform_revenue_entries',
            'payout_requests', 'payout_allocations', 'inventory_balances',
        ];

        $fingerprint = [];

        foreach ($tables as $table) {
            $fingerprint[$table] = (string) DB::table($table)
                ->selectRaw('coalesce(md5(string_agg(t.*::text, \'|\' order by t.*::text)), \'empty\') as f')
                ->from($table.' as t')
                ->value('f');
        }

        return $fingerprint;
    }

    /**
     * A settlement whose notification cannot be queued is still settled.
     *
     * The money moves inside the transaction and the notification is
     * dispatched after it commits, which is the right order: an email
     * must never describe a transaction that rolled back. The consequence
     * is that a queue outage in the window between them fails the
     * request *after* the debit is real.
     *
     * What matters is that the debit is real and correct. What is lost is
     * the email — classified below.
     */
    #[Test]
    public function a_payout_settles_even_when_its_notification_cannot_be_queued(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->approve($this->requestPayout($seller, 10_000));

        $this->withRedisDown(function () use ($request): void {
            try {
                app(RecordPayoutSettlement::class)($request, $this->financeActor(), 'wire', 'FT-REDIS-1');
            } catch (Throwable) {
                // The post-commit dispatch fails; the debit does not.
            }
        });

        /** @var PayoutRequest $fresh */
        $fresh = PayoutRequest::query()->findOrFail($request->id);

        $this->assertSame(PayoutStatus::Paid, $fresh->status);
        $this->assertSame(
            1,
            DB::table('seller_ledger_entries')->where('type', 'payout')->count(),
            'The settlement debit did not survive its own transaction.',
        );
    }

    /**
     * And an operator who retries after that failure does not pay twice.
     *
     * This is the drill that makes the lost notification survivable. The
     * request 500s after the money has moved, so the obvious human
     * response is to press the button again — and settlement has to be
     * idempotent against exactly that.
     */
    #[Test]
    public function retrying_a_settlement_after_a_queue_failure_debits_once(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->approve($this->requestPayout($seller, 10_000));

        $this->withRedisDown(function () use ($request): void {
            try {
                app(RecordPayoutSettlement::class)($request, $this->financeActor(), 'wire', 'FT-REDIS-2');
            } catch (Throwable) {
                // Expected.
            }
        });

        // Redis is back. The operator presses settle again.
        $settledAgain = app(RecordPayoutSettlement::class)(
            PayoutRequest::query()->findOrFail($request->id),
            $this->financeActor(),
            'wire',
            'FT-REDIS-2',
        );

        $this->assertFalse($settledAgain, 'A second settlement reported that it had done the work again.');
        $this->assertSame(1, DB::table('seller_ledger_entries')->where('type', 'payout')->count());
        $this->assertSame(
            1,
            DB::table('payout_requests')->where('status', PayoutStatus::Paid->value)->count(),
        );
    }

    /**
     * A payment whose receipt cannot be queued is still a payment.
     *
     * The narrowest window in the system: the worker starts, the
     * finalisation transaction commits, and Redis goes away before the
     * `afterCommit` dispatch of the receipt. The money is real and
     * correct; the customer's receipt is not sent.
     *
     * Recorded here rather than fixed. The authoritative state is right,
     * the event is marked failed and retried, and the retry finds the
     * work already done — so what is lost is one email, and closing that
     * would mean a durable record for every notification in the system.
     * The M9 brief is explicit that an outbox is not to be introduced
     * without a demonstrated loss of business intent, and a receipt is
     * not business intent; the settlement it describes already happened
     * and is visible on the order.
     */
    #[Test]
    public function a_payment_finalises_even_when_its_receipt_cannot_be_queued(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $provider = $this->provider();
        $signed = $provider->signedEvent('payment_intent.succeeded', $provider->paymentObject($reference));

        $this->withRedisDown(function () use ($signed): void {
            // Recorded but not processed: the endpoint could not queue it.
            $this->call(
                'POST',
                '/webhooks/payments',
                server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
                content: $signed['payload'],
            );

            /** @var ProviderWebhookEvent $event */
            $event = ProviderWebhookEvent::query()->firstOrFail();

            try {
                // A worker picks it up while Redis is still away, so the
                // only Redis call left in the path is the post-commit
                // dispatch of the receipt.
                $this->reprocess($event);
            } catch (Throwable) {
                // Expected: the dispatch fails after the commit.
            }
        });

        $order->refresh();

        $this->assertSame('paid', $order->status->value, 'The payment was lost with the receipt.');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('seller_ledger_entries')->where('type', 'sale_earning')->count());
    }

    /**
     * And a later retry of that event does not pay a second time.
     */
    #[Test]
    public function retrying_after_a_lost_receipt_does_not_pay_twice(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $provider = $this->provider();
        $signed = $provider->signedEvent('payment_intent.succeeded', $provider->paymentObject($reference));

        $this->withRedisDown(function () use ($signed): void {
            $this->call(
                'POST',
                '/webhooks/payments',
                server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
                content: $signed['payload'],
            );

            try {
                $this->reprocess(ProviderWebhookEvent::query()->firstOrFail());
            } catch (Throwable) {
                // Expected.
            }
        });

        $this->reprocess(ProviderWebhookEvent::query()->firstOrFail());

        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame(1, DB::table('seller_ledger_entries')->where('type', 'sale_earning')->count());
        $this->assertSame(1, DB::table('platform_revenue_entries')->count());
    }

    /**
     * The seller's balance is right afterwards, whatever the email did.
     *
     * The reconciliation everyone will actually run.
     */
    #[Test]
    public function the_ledger_reconciles_after_a_notification_failure(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->approve($this->requestPayout($seller, 10_000));

        $this->withRedisDown(function () use ($request): void {
            try {
                app(RecordPayoutSettlement::class)($request, $this->financeActor(), 'wire', 'FT-REDIS-3');
            } catch (Throwable) {
                // Expected.
            }
        });

        $this->runArtisan('finance:reconcile-sellers')->assertSuccessful()->run();

        $position = $this->positionOf($seller);

        $this->assertSame(40_000, $position->availableMinor);
        $this->assertSame(0, $position->reservedMinor);
        $this->assertSame(10_000, $position->paidOutMinor);
    }
}

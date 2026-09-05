<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Payments\Actions\FinalizePayment;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ProviderEventStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PlatformRevenueEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * M9 property 2, attack 14 — two processors, one payment.
 *
 * Truncation rather than RefreshDatabase, and a second connection rather
 * than a second call, for the reason the other concurrency suites give:
 * work inside a transaction that never commits is invisible to anybody
 * else, so two sessions that cannot see each other's rows prove nothing
 * about a race. Calling the method twice in a row proves even less — it
 * exercises the application's memory of what it just did, which is the one
 * thing a second worker on a second machine does not share.
 *
 * What must hold is that the guarantee lives in PostgreSQL: a conditional
 * UPDATE nothing else can match, and unique indexes that reject the second
 * write even if a worker somehow got past everything above them. Each is
 * exercised here from a connection that has no idea what the first worker
 * did except what it committed.
 */
final class FinancialAuthorityRaceTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    #[Test]
    public function two_workers_racing_one_success_produce_exactly_one_finalization(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 30_000, stock: 4);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        // Worker one wins, and commits.
        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        $this->assertSame(ProviderEventStatus::Processed, $event->refresh()->status);
        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);

        // Read as raw rows, not models: the second worker is copying what
        // the winner committed, and a cast enum is this process's idea of
        // the value rather than the bytes another connection would see.
        $payment = (array) DB::table('payments')->firstOrFail();
        $earning = (array) DB::table('seller_ledger_entries')->firstOrFail();
        $commission = (array) DB::table('platform_revenue_entries')->firstOrFail();

        $second = DB::connection('concurrent');

        try {
            // 1. The event is no longer claimable. The WHERE is the lock,
            //    and it holds across connections as an in-process "have I
            //    seen this?" never could.
            $claimed = $second->table('provider_webhook_events')
                ->where('id', $event->id)
                ->whereIn('status', [ProviderEventStatus::Received->value, ProviderEventStatus::Failed->value])
                ->update(['attempts' => $second->raw('attempts + 1')]);

            $this->assertSame(0, $claimed, 'A processed event must not be claimable by a second worker.');

            // 2. And if one somehow were, the capture row cannot be written
            //    twice: the provider's charge id is unique, in the schema.
            $this->assertDatabaseRejects(
                fn () => $second->table('payments')->insert([
                    'public_id' => (string) Str::ulid(),
                    'marketplace_order_id' => $order->id,
                    'payment_attempt_id' => $payment['payment_attempt_id'],
                    'provider' => $payment['provider'],
                    'provider_charge_id' => $payment['provider_charge_id'],
                    'currency' => $payment['currency'],
                    'amount_minor' => $payment['amount_minor'],
                    'refunded_amount_minor' => 0,
                    'status' => $payment['status'],
                    'captured_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'a second capture for one provider charge',
            );

            // 3. Nor the seller's earning, by its business key.
            $this->assertDatabaseRejects(
                fn () => $second->table('seller_ledger_entries')->insert([
                    'public_id' => (string) Str::ulid(),
                    'seller_account_id' => $earning['seller_account_id'],
                    'type' => $earning['type'],
                    'status' => $earning['status'],
                    'currency' => $earning['currency'],
                    'amount_minor' => $earning['amount_minor'],
                    'balance_after_minor' => $earning['balance_after_minor'],
                    'seller_order_id' => $earning['seller_order_id'],
                    'order_item_id' => $earning['order_item_id'],
                    'source_key' => $earning['source_key'],
                    'created_at' => now(),
                ]),
                'a second earning for one order item',
            );

            // 4. Nor the platform's commission.
            $this->assertDatabaseRejects(
                fn () => $second->table('platform_revenue_entries')->insert([
                    'public_id' => (string) Str::ulid(),
                    'marketplace_order_id' => $commission['marketplace_order_id'],
                    'seller_order_id' => $commission['seller_order_id'],
                    'order_item_id' => $commission['order_item_id'],
                    'seller_account_id' => $commission['seller_account_id'],
                    'type' => $commission['type'],
                    'currency' => $commission['currency'],
                    'amount_minor' => $commission['amount_minor'],
                    'rate_percent_snapshot' => $commission['rate_percent_snapshot'],
                    'source_key' => $commission['source_key'],
                    'created_at' => now(),
                ]),
                'a second commission for one order item',
            );
        } finally {
            $second->disconnect();
        }

        // And the losing worker running the whole action again — seeing
        // everything the winner committed — finalizes nothing.
        $this->assertFalse(
            app(FinalizePayment::class)($reference, $event->id),
            'A second finalization must report that it did not do the work.',
        );

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(1, PlatformRevenueEntry::query()->count());
        $this->assertSame(1, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);

        // One sale, one unit gone, nothing double-committed.
        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();
        $this->assertNotNull($balance);
        $this->assertSame(3, (int) $balance->on_hand);
        $this->assertSame(0, (int) $balance->reserved);
    }

    /** The database refuses this write, and says so as a constraint violation. */
    private function assertDatabaseRejects(callable $write, string $what): void
    {
        try {
            $write();
            $this->fail("The database allowed {$what}. The guarantee has to be an index, not an if.");
        } catch (QueryException $e) {
            $this->assertSame(
                '23505',
                (string) $e->getCode(),
                "Writing {$what} failed, but not because of a uniqueness constraint.",
            );
        }
    }
}

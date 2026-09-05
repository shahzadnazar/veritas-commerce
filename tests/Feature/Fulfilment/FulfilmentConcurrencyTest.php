<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Ledger\Queries\SellerBalance;
use App\Modules\Orders\Actions\CompleteDeliveredSellerOrders;
use App\Modules\Orders\Actions\MarkShipmentDelivered;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;
use Throwable;

/**
 * §60–§62 — what two things happening at once must not be able to do.
 *
 * Truncation and committed transactions, as the checkout, inventory and
 * payment concurrency suites use: work inside a transaction that never
 * commits is invisible to a second connection, and two sessions that
 * cannot see each other prove nothing about a race.
 *
 * What each of these proves is that the guard is the database's — a unique
 * index, a CHECK constraint, a conditional UPDATE, a row lock — rather than
 * an application check that would lose in production.
 */
final class FulfilmentConcurrencyTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    #[Test]
    public function two_parcels_cannot_be_given_the_same_number(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 3]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = $this->itemsOf($sellerOrder)[0];

        $first = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 1]]);
        $second = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 1]]);

        $this->assertSame($sellerOrder->reference.'-S01', $first->reference);
        $this->assertSame($sellerOrder->reference.'-S02', $second->reference);

        // And a third that somehow claimed a taken number is refused by
        // the index, not by whoever remembered to check.
        $other = DB::connection('concurrent');

        try {
            $this->expectException(QueryException::class);

            $other->table('shipments')->insert([
                'public_id' => (string) Str::ulid(),
                'reference' => $sellerOrder->reference.'-S99',
                'seller_order_id' => $sellerOrder->id,
                'sequence' => 1,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $this->cleanUp($other);
        }
    }

    #[Test]
    public function the_last_unit_cannot_be_allocated_to_two_parcels(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = $this->itemsOf($sellerOrder)[0];

        $accepted = 0;

        // Three attempts at two units. The arithmetic is read under the
        // item's row lock, so the third finds nothing left.
        foreach (range(1, 3) as $_) {
            try {
                $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 1]]);
                $accepted++;
            } catch (FulfilmentRefused $refused) {
                $this->assertSame('exceeds_remaining', $refused->reason);
            }
        }

        $this->assertSame(2, $accepted);
        $this->assertSame(2, (int) $item->refresh()->allocated_quantity);

        // Visible from another connection, because it committed.
        $other = DB::connection('concurrent');

        try {
            $this->assertSame(
                2,
                (int) $other->table('shipment_items')->where('order_item_id', $item->id)->sum('quantity'),
            );
        } finally {
            $this->cleanUp($other);
        }
    }

    #[Test]
    public function two_workers_clearing_together_release_the_money_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $sellerAccountId = (int) $sellerOrder->seller_account_id;
        $entriesBefore = SellerLedgerEntry::query()->withoutGlobalScopes()->count();

        $this->travel(8)->days();

        /*
         * The second worker, on its own connection, seeing the first one's
         * committed rows. Its conditional UPDATE narrows to
         * `status = clearing` and matches nothing, which is the guarantee:
         * an entry cannot be released twice however many sweeps run.
         */
        $first = app(CompleteDeliveredSellerOrders::class)();

        $other = DB::connection('concurrent');

        try {
            $claimed = $other->table('seller_ledger_entries')
                ->where('seller_order_id', $sellerOrder->id)
                ->where('status', LedgerEntryStatus::Clearing->value)
                ->update(['status' => LedgerEntryStatus::Available->value]);

            $this->assertSame(0, $claimed, 'Nothing is left clearing for a second worker to take.');
        } finally {
            $this->cleanUp($other);
        }

        $second = app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(1, $first['released']);
        $this->assertSame(0, $second['released']);
        $this->assertSame(0, $second['completed']);

        // One release, no new rows, and the balance is the snapshot.
        $this->assertSame($entriesBefore, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(8_800, app(SellerBalance::class)($sellerAccountId)['available']->minor);
        $this->assertSame(
            1,
            OrderStatusHistory::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('to_status', SellerOrderStatus::Completed->value)
                ->count(),
        );
    }

    #[Test]
    public function two_deliveries_of_one_parcel_start_one_clock(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);

        $this->assertTrue(app(MarkShipmentDelivered::class)($shipment));

        $clearAt = $sellerOrder->refresh()->earnings_clear_at;
        $entryCount = SellerLedgerEntry::query()->withoutGlobalScopes()->count();

        // A retried job, a second click, and an admin doing it again.
        $this->assertFalse(app(MarkShipmentDelivered::class)($shipment->refresh()));
        $this->assertFalse(app(MarkShipmentDelivered::class)(Shipment::query()->findOrFail($shipment->id)));

        $this->assertEquals($clearAt, $sellerOrder->refresh()->earnings_clear_at);
        $this->assertSame($entryCount, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            1,
            OrderStatusHistory::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('to_status', SellerOrderStatus::Delivered->value)
                ->count(),
        );
    }

    #[Test]
    public function a_reversed_earning_is_never_released_by_the_sweep(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        // An operator marks the earning reversed directly — the state a
        // dispute or a correction would leave it in.
        SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('seller_order_id', $sellerOrder->id)
            ->update(['status' => LedgerEntryStatus::Reversed->value]);

        $this->travel(30)->days();

        $result = app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(0, $result['released'], 'A reversed entry is not clearing, so nothing releases it.');
        $this->assertSame(
            0,
            app(SellerBalance::class)((int) $sellerOrder->seller_account_id)['available']->minor,
        );
    }

    private function cleanUp(mixed $connection): void
    {
        try {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // Already resolved.
        }

        $connection->disconnect();
    }
}

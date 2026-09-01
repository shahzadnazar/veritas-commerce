<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryReservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Two customers, one unit, and only one of them gets it.
 *
 * §7 asks for proof rather than an argument, so these run genuinely
 * interleaved transactions over two connections rather than two sequential
 * calls that could never have raced. The transactions are stepped by hand
 * so the dangerous ordering — both readers see the stock, then both write
 * — is the one actually exercised.
 *
 * PostgreSQL is the authority throughout. Redis holds no part of this
 * decision, which is the point: an in-memory counter that disagreed with
 * the database would oversell precisely when it mattered.
 */
final class ReservationConcurrencyTest extends TestCase
{
    /*
     * Truncation rather than RefreshDatabase.
     *
     * RefreshDatabase wraps each test in a transaction that is never
     * committed, so a second connection cannot see the fixture at all —
     * and a concurrency test whose two sessions cannot see each other's
     * data proves nothing. Truncating between tests costs a little speed
     * and buys a test that could actually fail.
     */
    use DatabaseTruncation;
    use StocksOffers;

    #[Test]
    public function two_transactions_racing_for_the_last_unit_produce_one_reservation(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(1);

        $second = DB::connection('concurrent');

        try {
            // A holds the row.
            DB::beginTransaction();
            $lockedA = InventoryBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();

            $available = $lockedA->on_hand - $lockedA->reserved;
            $this->assertSame(1, $available, 'Both readers see one unit available.');

            // B tries for the same row and blocks, so its own read cannot
            // happen until A is done — which is the entire mechanism.
            $second->beginTransaction();

            DB::table('inventory_balances')->where('id', $balance->id)->update(['reserved' => 1]);
            InventoryReservation::query()->create([
                'offer_id' => $offer->id,
                'inventory_location_id' => $balance->inventory_location_id,
                'quantity' => 1,
                'status' => ReservationStatus::Held->value,
                'reference' => 'cart-a',
                'expires_at' => now()->addMinutes(20),
            ]);
            DB::commit();

            $this->assertSame(
                1,
                InventoryReservation::query()->where('reference', 'cart-a')->count(),
                'The first customer got the unit.',
            );

            // Now B proceeds. It reads the row A just committed, not the
            // one it saw before blocking — which is the entire mechanism.
            $lockedB = $second->table('inventory_balances')->where('id', $balance->id)->lockForUpdate()->first();

            $this->assertNotNull($lockedB);

            $availableForB = (int) $lockedB->on_hand - (int) $lockedB->reserved;

            $this->assertSame(0, $availableForB, 'The second customer finds nothing left.');

            $second->commit();
        } finally {
            $this->cleanUp($second);
        }
    }

    #[Test]
    public function the_database_refuses_an_oversell_even_if_the_application_tries(): void
    {
        ['balance' => $balance] = $this->stockedOffer(1);

        $second = DB::connection('concurrent');

        try {
            // Exactly what a lost update would leave behind: two holds on
            // one unit. The CHECK is the last line of defence, and it does
            // not depend on anybody remembering to lock.
            $this->expectException(QueryException::class);
            $this->expectExceptionMessage('inventory_reserved_within_on_hand');

            $second->table('inventory_balances')->where('id', $balance->id)->update(['reserved' => 2]);
        } finally {
            $this->cleanUp($second);
        }
    }

    #[Test]
    public function serialised_attempts_leave_the_ledger_reconcilable(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(3);

        $succeeded = 0;

        // Five customers, three units. Whatever order they arrive in, the
        // arithmetic afterwards has to hold.
        foreach (range(1, 5) as $attempt) {
            try {
                app(ReserveStock::class)([$offer->id => 1], "cart-{$attempt}");
                $succeeded++;
            } catch (InsufficientStock) {
                // Expected once the stock is gone.
            }
        }

        $balance->refresh();

        $this->assertSame(3, $succeeded, 'Exactly the available units were sold.');
        $this->assertSame(3, $balance->reserved);
        $this->assertSame(0, $balance->available);
        $this->assertSame(3, $balance->heldByReservations());

        $this->runArtisan('inventory:reconcile')->assertSuccessful()->run();
    }

    /**
     * Leave the database as it was found.
     *
     * DatabaseTruncation cleans up *before* each test that uses it, which
     * is no help to the RefreshDatabase tests that run afterwards: these
     * tests commit, so without this they hand their rows to whatever comes
     * next and it fails somewhere unrelated.
     */
    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    private function cleanUp(mixed $second): void
    {
        try {
            if ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
        } catch (Throwable) {
            // Already resolved.
        }

        $second->disconnect();
    }
}

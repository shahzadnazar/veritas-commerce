<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Actions\ConsumeReservation;
use App\Modules\Inventory\Actions\RecordMovement;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Data\StockLevel;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Enums\StockState;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryReservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ledger's own rules, at the level the database enforces them.
 *
 * Several of these deliberately bypass the domain actions and write raw
 * SQL. A rule that only holds when the application is polite is not a
 * rule — the constraints are what make a stored `reserved` safe to read
 * from a discovery query.
 */
final class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;
    use StocksOffers;

    #[Test]
    public function available_is_computed_by_the_database_not_by_the_caller(): void
    {
        $balance = $this->stockedOffer(10)['balance'];

        DB::table('inventory_balances')->where('id', $balance->id)->update(['reserved' => 4]);

        // Nothing wrote `available`; PostgreSQL derived it.
        $this->assertSame(6, (int) DB::table('inventory_balances')->where('id', $balance->id)->value('available'));
        $this->assertSame(6, $balance->refresh()->available);
        $this->assertSame(6, $balance->level()->available);
    }

    #[Test]
    public function the_database_refuses_negative_stock_even_without_the_domain_check(): void
    {
        $balance = $this->stockedOffer(2)['balance'];

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('inventory_on_hand_not_negative');

        DB::table('inventory_balances')->where('id', $balance->id)->update(['on_hand' => -1]);
    }

    #[Test]
    public function the_database_refuses_reserving_more_than_is_held(): void
    {
        $balance = $this->stockedOffer(3)['balance'];

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('inventory_reserved_within_on_hand');

        DB::table('inventory_balances')->where('id', $balance->id)->update(['reserved' => 4]);
    }

    #[Test]
    public function the_database_refuses_negative_reserved(): void
    {
        $balance = $this->stockedOffer(3)['balance'];

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('inventory_reserved_not_negative');

        DB::table('inventory_balances')->where('id', $balance->id)->update(['reserved' => -1]);
    }

    #[Test]
    public function a_movement_that_changes_nothing_is_refused(): void
    {
        $balance = $this->stockedOffer(3)['balance'];

        $this->expectException(InvalidStockOperation::class);
        $this->expectExceptionMessage('changes nothing');

        app(RecordMovement::class)($balance, InventoryMovementReason::CountCorrection);
    }

    #[Test]
    public function the_domain_explains_a_refusal_in_words_before_the_constraint_does(): void
    {
        $balance = $this->stockedOffer(2)['balance'];

        try {
            app(RecordMovement::class)($balance, InventoryMovementReason::Damaged, onHandChange: -5);
            $this->fail('Taking stock negative must throw.');
        } catch (InvalidStockOperation $exception) {
            // A sentence a seller can read, not a constraint violation.
            $this->assertStringContainsString('-3', $exception->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        }

        $this->assertSame(2, $balance->refresh()->on_hand, 'The refused movement changed nothing.');
    }

    #[Test]
    public function a_reservation_cannot_promise_units_that_are_not_there(): void
    {
        ['offer' => $offer] = $this->stockedOffer(1);

        $this->expectException(InsufficientStock::class);

        app(ReserveStock::class)([$offer->id => 2], 'cart-too-big');
    }

    #[Test]
    public function releasing_the_same_reference_twice_restores_stock_once(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5);

        app(ReserveStock::class)([$offer->id => 3], 'cart-1');
        $this->assertSame(2, $balance->refresh()->available);

        $first = app(ReleaseReservation::class)('cart-1');
        $second = app(ReleaseReservation::class)('cart-1');

        // The second sweep finds nothing held and does nothing at all —
        // the property every queued expiry job depends on.
        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(5, $balance->refresh()->available);
        $this->assertSame(0, $balance->reserved);
    }

    #[Test]
    public function committing_a_reservation_drops_both_quantities_together(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5);

        app(ReserveStock::class)([$offer->id => 2], 'order-1');
        app(ConsumeReservation::class)('order-1');

        $balance->refresh();

        $this->assertSame(3, $balance->on_hand);
        $this->assertSame(0, $balance->reserved);
        $this->assertSame(3, $balance->available);

        $this->assertSame(
            ReservationStatus::Consumed,
            InventoryReservation::query()->where('reference', 'order-1')->firstOrFail()->status,
        );
    }

    #[Test]
    public function committing_twice_sells_the_units_once(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5);

        app(ReserveStock::class)([$offer->id => 2], 'order-1');

        $this->assertSame(1, app(ConsumeReservation::class)('order-1'));
        $this->assertSame(0, app(ConsumeReservation::class)('order-1'));
        $this->assertSame(3, $balance->refresh()->on_hand);
    }

    #[Test]
    public function every_reservation_carries_the_movements_that_opened_and_closed_it(): void
    {
        ['offer' => $offer] = $this->stockedOffer(4);

        app(ReserveStock::class)([$offer->id => 1], 'cart-1');
        $reservation = InventoryReservation::query()->where('reference', 'cart-1')->firstOrFail();

        $this->assertNotNull($reservation->opened_by_movement_id);
        $this->assertNull($reservation->closed_by_movement_id);

        app(ReleaseReservation::class)('cart-1');
        $reservation->refresh();

        // Either direction reconciles: from the hold to its ledger entries,
        // or from the ledger back to the hold that caused them.
        $this->assertNotNull($reservation->closed_by_movement_id);
        $this->assertNotSame($reservation->opened_by_movement_id, $reservation->closed_by_movement_id);
    }

    #[Test]
    public function a_live_reservation_has_no_resolution_date_and_a_resolved_one_does(): void
    {
        ['offer' => $offer] = $this->stockedOffer(4);

        app(ReserveStock::class)([$offer->id => 1], 'cart-1');
        $reservation = InventoryReservation::query()->where('reference', 'cart-1')->firstOrFail();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('reservations_resolution_is_dated');

        // Resolved without a resolution time: the database refuses the pair.
        DB::table('inventory_reservations')->where('id', $reservation->id)
            ->update(['status' => ReservationStatus::Released->value]);
    }

    #[Test]
    public function reconciliation_passes_after_a_full_reserve_release_and_sale_cycle(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(0);

        app(RecordMovement::class)($balance, InventoryMovementReason::OpeningStock, onHandChange: 20);
        app(ReserveStock::class)([$offer->id => 5], 'cart-a');
        app(ReserveStock::class)([$offer->id => 3], 'cart-b');
        app(ReleaseReservation::class)('cart-a');
        app(ConsumeReservation::class)('cart-b');
        app(RecordMovement::class)($balance->refresh(), InventoryMovementReason::Damaged, onHandChange: -2, actorType: 'seller', actorId: 1);

        $balance->refresh();

        $this->assertSame(15, $balance->on_hand);
        $this->assertSame(0, $balance->reserved);
        $this->assertSame(15, $balance->available);

        $this->runArtisan('inventory:reconcile')->assertSuccessful()->run();
    }

    #[Test]
    public function reconciliation_notices_a_reserved_column_that_has_drifted(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(10);

        app(ReserveStock::class)([$offer->id => 4], 'cart-1');

        // Exactly the corruption a stored column risks, injected by hand.
        DB::table('inventory_balances')->where('id', $balance->id)->update(['reserved' => 2]);

        $this->runArtisan('inventory:reconcile')->assertFailed()->run();
        $this->runArtisan('inventory:reconcile', ['--fix' => true])->assertFailed()->run();

        // --fix repairs from the reservation rows, which are the truth,
        // and the ledger agrees again afterwards.
        $this->assertSame(4, $balance->refresh()->reserved);
        $this->runArtisan('inventory:reconcile')->assertSuccessful()->run();
    }

    #[Test]
    public function stock_state_comes_from_one_place_for_every_surface(): void
    {
        $balance = $this->stockedOffer(10)['balance'];

        $this->assertSame(StockState::InStock, $balance->state());

        DB::table('inventory_balances')->where('id', $balance->id)->update(['reserved' => 7]);
        $this->assertSame(StockState::LowStock, $balance->refresh()->state());

        DB::table('inventory_balances')->where('id', $balance->id)->update(['reserved' => 10]);
        $this->assertSame(StockState::OutOfStock, $balance->refresh()->state());
    }

    #[Test]
    public function a_threshold_of_zero_means_the_seller_wants_no_low_stock_warning(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(1);

        $offer->forceFill(['low_stock_threshold' => 0])->save();

        // One unit left, and still In stock: zero is a choice, not an
        // absence of one.
        $this->assertSame(StockState::InStock, $balance->refresh()->state());
    }

    #[Test]
    public function the_threshold_falls_back_from_offer_to_store_to_platform(): void
    {
        ['offer' => $offer, 'store' => $store, 'balance' => $balance] = $this->stockedOffer(8);

        $this->assertSame(
            (int) config('veritas.inventory.low_stock_threshold'),
            $balance->lowStockThreshold(),
        );

        $store->forceFill(['default_low_stock_threshold' => 10])->save();
        $this->assertSame(10, $balance->refresh()->lowStockThreshold());

        $offer->forceFill(['low_stock_threshold' => 3])->save();
        $this->assertSame(3, $balance->refresh()->lowStockThreshold());
    }

    #[Test]
    public function an_offer_with_no_balance_row_reads_as_nothing_in_stock(): void
    {
        $balance = InventoryBalance::query()->firstWhere('id', -1);

        $this->assertNull($balance);
        $this->assertSame(0, StockLevel::none()->available);
        $this->assertSame(StockState::OutOfStock, StockLevel::none()->state);
    }
}

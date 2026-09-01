<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Inventory\Actions\ConsumeReservation;
use App\Modules\Inventory\Actions\RecordMovement;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Concerns\CurrentSeller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Invariants 6 and 7 — reservations prevent overselling, and releasing a
 * reservation restores availability exactly.
 *
 * The distinction that makes this work: a reservation holds stock without
 * touching the physical count, so a failed payment cannot destroy
 * availability, and `available` is always derived rather than stored.
 */
final class InventoryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{offer: Offer, balance: InventoryBalance} */
    private function stockedOffer(int $onHand): array
    {
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();

        $offer = CurrentSeller::actingAs($seller->id, fn (): Offer => Offer::factory()->create(['store_id' => $store->id]));

        $location = InventoryLocation::create([
            'seller_account_id' => $seller->id,
            'name' => 'Default',
            'is_default' => true,
        ]);

        $balance = InventoryBalance::create([
            'offer_id' => $offer->id,
            'inventory_location_id' => $location->id,
            'on_hand' => $onHand,
        ]);

        return ['offer' => $offer, 'balance' => $balance];
    }

    #[Test]
    public function a_reservation_reduces_available_without_touching_on_hand(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5);

        app(ReserveStock::class)([$offer->id => 2], 'cart-1');

        $balance->refresh();

        $this->assertSame(5, $balance->on_hand, 'The physical count has not moved.');
        $this->assertSame(2, $balance->reserved);
        $this->assertSame(2, $balance->heldByReservations(), 'The column and the reservation rows agree.');
        $this->assertSame(3, $balance->available);
    }

    #[Test]
    public function the_last_unit_cannot_be_reserved_twice(): void
    {
        ['offer' => $offer] = $this->stockedOffer(1);

        app(ReserveStock::class)([$offer->id => 1], 'cart-a');

        $this->expectException(InsufficientStock::class);
        $this->expectExceptionMessage('has 0 available but 1 were requested');

        app(ReserveStock::class)([$offer->id => 1], 'cart-b');
    }

    #[Test]
    public function releasing_a_reservation_restores_availability_exactly(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(3);

        app(ReserveStock::class)([$offer->id => 3], 'cart-1');
        $this->assertSame(0, $balance->refresh()->available);

        $released = app(ReleaseReservation::class)('cart-1');

        $this->assertSame(1, $released);
        $this->assertSame(3, $balance->refresh()->available, 'All three units are sellable again.');
        $this->assertSame(3, $balance->refresh()->on_hand);
        $this->assertSame(0, $balance->refresh()->reserved);

        /*
         * The units never physically moved, and the ledger says so: the
         * hold and its release are both recorded — "why did available drop
         * when nothing sold" has to be answerable — but neither touched
         * on_hand, and together they net to zero.
         */
        $movements = InventoryMovement::query()->where('offer_id', $offer->id)->get();

        $this->assertCount(2, $movements);
        $this->assertSame(0, (int) $movements->sum('on_hand_change'), 'A reservation is not a physical movement.');
        $this->assertSame(0, (int) $movements->sum('reserved_change'), 'The hold and its release cancel out.');
        $this->assertEqualsCanonicalizing(
            [InventoryMovementReason::OrderReservation, InventoryMovementReason::ReservationRelease],
            $movements->pluck('reason')->all(),
        );
    }

    #[Test]
    public function a_failed_payment_frees_the_stock_for_the_next_customer(): void
    {
        ['offer' => $offer] = $this->stockedOffer(1);

        app(ReserveStock::class)([$offer->id => 1], 'cart-failed');
        app(ReleaseReservation::class)('cart-failed');

        // The next customer succeeds where the first one's card declined.
        $reservations = app(ReserveStock::class)([$offer->id => 1], 'cart-next');

        $this->assertCount(1, $reservations);
    }

    #[Test]
    public function consuming_a_reservation_decrements_stock_and_writes_a_movement(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5);

        app(ReserveStock::class)([$offer->id => 2], 'order-1');
        app(ConsumeReservation::class)('order-1');

        $balance->refresh();

        $this->assertSame(3, $balance->on_hand);
        $this->assertSame(0, $balance->reserved, 'The hold is consumed, not still counted.');
        $this->assertSame(0, $balance->heldByReservations());
        $this->assertSame(3, $balance->available);

        $movement = InventoryMovement::query()
            ->where('reason', InventoryMovementReason::SaleCompleted->value)
            ->firstOrFail();

        // One entry, both columns: the units leave the shelf and stop
        // being spoken for at the same instant.
        $this->assertSame(-2, $movement->on_hand_change);
        $this->assertSame(-2, $movement->reserved_change);
        $this->assertSame(3, $movement->resulting_on_hand);
        $this->assertSame(0, $movement->resulting_reserved);
        $this->assertSame(InventoryMovementReason::SaleCompleted, $movement->reason);
        $this->assertSame('system', $movement->actor_type);
    }

    #[Test]
    public function replaying_every_movement_reproduces_the_current_on_hand(): void
    {
        ['balance' => $balance] = $this->stockedOffer(0);

        app(RecordMovement::class)($balance, InventoryMovementReason::OpeningStock, onHandChange: 10);
        app(RecordMovement::class)($balance->refresh(), InventoryMovementReason::RestockReceived, onHandChange: 5, actorType: 'seller', actorId: 1);
        app(RecordMovement::class)($balance->refresh(), InventoryMovementReason::Damaged, onHandChange: -3, actorType: 'seller', actorId: 1);
        app(RecordMovement::class)($balance->refresh(), InventoryMovementReason::OrderReservation, reservedChange: 4);
        app(RecordMovement::class)($balance->refresh(), InventoryMovementReason::SaleCompleted, onHandChange: -2, reservedChange: -2);

        $movements = InventoryMovement::query()->where('offer_id', $balance->offer_id);

        $replayedOnHand = (int) (clone $movements)->sum('on_hand_change');
        $replayedReserved = (int) (clone $movements)->sum('reserved_change');

        $balance->refresh();

        $this->assertSame(10, $balance->on_hand);
        $this->assertSame(2, $balance->reserved);
        $this->assertSame(8, $balance->available, 'available is generated, not stored by hand.');

        // The defining property of the ledger, now over both quantities.
        $this->assertSame($replayedOnHand, $balance->on_hand, 'The movement log must reconstruct on_hand.');
        $this->assertSame($replayedReserved, $balance->reserved, 'The movement log must reconstruct reserved.');
    }

    #[Test]
    public function stock_can_never_go_negative(): void
    {
        ['balance' => $balance] = $this->stockedOffer(2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stock cannot go negative');

        app(RecordMovement::class)($balance, InventoryMovementReason::Damaged, onHandChange: -3, actorType: 'seller', actorId: 1);
    }

    #[Test]
    public function movements_are_append_only(): void
    {
        ['balance' => $balance] = $this->stockedOffer(1);

        $movement = app(RecordMovement::class)($balance, InventoryMovementReason::RestockReceived, onHandChange: 1, actorType: 'seller', actorId: 1);

        try {
            $movement->update(['on_hand_change' => 99]);
            $this->fail('Editing a movement must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $movement->delete();
    }

    #[Test]
    public function a_multi_line_reservation_is_all_or_nothing(): void
    {
        ['offer' => $plenty] = $this->stockedOffer(10);
        ['offer' => $scarce] = $this->stockedOffer(1);

        try {
            app(ReserveStock::class)([$plenty->id => 1, $scarce->id => 5], 'cart-mixed');
            $this->fail('A cart that cannot be fully reserved must fail.');
        } catch (InsufficientStock $exception) {
            $this->assertSame($scarce->id, $exception->offerId);
        }

        // The transaction rolled back, so the line that would have succeeded
        // is not holding stock either.
        $this->assertSame(
            0,
            InventoryReservation::query()
                ->where('status', ReservationStatus::Held->value)
                ->count(),
        );
    }
}

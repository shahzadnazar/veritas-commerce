<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\StockState;
use App\Modules\Inventory\Events\InventoryAdjusted;
use App\Modules\Inventory\Events\InventoryDepleted;
use App\Modules\Inventory\Events\InventoryLow;
use App\Modules\Inventory\Events\InventoryRestored;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Correcting stock by hand: what is allowed, what is recorded, and what
 * the rest of the system is told about it.
 */
final class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;
    use StocksOffers;

    #[Test]
    public function opening_stock_arrives_through_the_ledger_rather_than_around_it(): void
    {
        ['offer' => $offer] = $this->stockedOffer(0);

        $movement = app(AdjustInventory::class)->openingStock($offer, 25, 'seller', 1);

        $this->assertSame(InventoryMovementReason::OpeningStock, $movement->reason);
        $this->assertSame(25, $movement->on_hand_change);
        $this->assertSame(25, $movement->resulting_on_hand);

        // The property that would have been broken on day one by writing
        // the number straight onto the balance.
        $this->assertSame(
            25,
            (int) InventoryMovement::query()->where('offer_id', $offer->id)->sum('on_hand_change'),
        );
    }

    #[Test]
    public function opening_stock_cannot_be_set_twice(): void
    {
        ['offer' => $offer] = $this->stockedOffer(0);

        app(AdjustInventory::class)->openingStock($offer, 10, 'seller', 1);

        $this->expectException(InvalidStockOperation::class);
        $this->expectExceptionMessage('already has a stock history');

        app(AdjustInventory::class)->openingStock($offer, 10, 'seller', 1);
    }

    #[Test]
    public function a_seller_increases_stock_and_the_movement_says_why(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(4);

        $movement = app(AdjustInventory::class)(
            $offer, 20, InventoryMovementReason::RestockReceived, 'seller', 7,
        );

        $this->assertSame(24, $balance->refresh()->on_hand);
        $this->assertSame(20, $movement->on_hand_change);
        $this->assertSame(InventoryMovementReason::RestockReceived, $movement->reason);
        $this->assertSame('seller', $movement->actor_type);
        $this->assertSame(7, $movement->actor_id);
    }

    #[Test]
    public function a_seller_decreases_stock_with_a_signed_quantity(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(10);

        // Direction is the sign; the reason carries the meaning. A
        // MANUAL_DECREASE row would say less than "damaged" does.
        app(AdjustInventory::class)($offer, -3, InventoryMovementReason::Damaged, 'seller', 7);

        $this->assertSame(7, $balance->refresh()->on_hand);
    }

    #[Test]
    public function an_adjustment_of_zero_is_refused(): void
    {
        ['offer' => $offer] = $this->stockedOffer(5);

        $this->expectException(InvalidStockOperation::class);
        $this->expectExceptionMessage('changes nothing');

        app(AdjustInventory::class)($offer, 0, InventoryMovementReason::CountCorrection, 'seller', 1);
    }

    #[Test]
    public function other_requires_words_because_the_code_explains_nothing(): void
    {
        ['offer' => $offer] = $this->stockedOffer(5);

        $this->expectException(InvalidStockOperation::class);
        $this->expectExceptionMessage('Say what happened');

        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Other, 'seller', 1);
    }

    #[Test]
    public function other_is_accepted_once_it_is_explained(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(5);

        $movement = app(AdjustInventory::class)(
            $offer, -1, InventoryMovementReason::Other, 'seller', 1, 'Given to a customer as a replacement.',
        );

        $this->assertSame('Given to a customer as a replacement.', $movement->note);
        $this->assertSame(4, $balance->refresh()->on_hand);
    }

    #[Test]
    public function a_reservation_reason_cannot_be_used_as_a_manual_adjustment(): void
    {
        ['offer' => $offer] = $this->stockedOffer(5);

        $this->expectException(InvalidStockOperation::class);
        $this->expectExceptionMessage('not adjusted by hand');

        app(AdjustInventory::class)($offer, 1, InventoryMovementReason::OrderReservation, 'seller', 1);
    }

    #[Test]
    public function an_adjustment_cannot_take_stock_below_what_is_already_reserved(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(10);

        app(ReserveStock::class)([$offer->id => 8], 'cart-1');

        // §3: a manual adjustment must not invalidate active reservations.
        $this->expectException(InvalidStockOperation::class);
        $this->expectExceptionMessage('cannot promise units that are not held');

        app(AdjustInventory::class)($offer, -5, InventoryMovementReason::Damaged, 'seller', 1);

        $this->assertSame(10, $balance->refresh()->on_hand);
    }

    #[Test]
    public function every_adjustment_is_audited_against_the_offer(): void
    {
        ['offer' => $offer] = $this->stockedOffer(5);

        app(AdjustInventory::class)(
            $offer, -2, InventoryMovementReason::Lost, 'seller', 9, 'Not found at the last count.',
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.adjusted',
            'actor_type' => 'seller',
            'actor_id' => 9,
            'subject_id' => $offer->id,
            'reason' => 'Not found at the last count.',
        ]);
    }

    #[Test]
    public function crossing_the_threshold_downward_raises_the_low_stock_event(): void
    {
        // Threshold is 5; six units is comfortable, five is not.
        ['offer' => $offer] = $this->stockedOffer(6);

        Event::fake([InventoryAdjusted::class, InventoryLow::class, InventoryDepleted::class, InventoryRestored::class]);

        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Damaged, 'seller', 1);

        Event::assertDispatched(InventoryLow::class, fn (InventoryLow $event): bool => $event->offerId === $offer->id
            && $event->available === 5);
        Event::assertNotDispatched(InventoryDepleted::class);
    }

    #[Test]
    public function staying_low_does_not_raise_the_event_again(): void
    {
        ['offer' => $offer] = $this->stockedOffer(6);

        // Faked after the fixture: opening stock is itself a movement, and
        // counting it here would be measuring the fixture.
        Event::fake([InventoryLow::class, InventoryAdjusted::class]);

        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Damaged, 'seller', 1);
        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Damaged, 'seller', 1);
        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Damaged, 'seller', 1);

        // 6→5 crossed the line. 5→4 and 4→3 did not: the seller already
        // knows, and telling them three times is how a notification stops
        // being read.
        Event::assertDispatchedTimes(InventoryLow::class, 1);
        Event::assertDispatchedTimes(InventoryAdjusted::class, 3);
    }

    #[Test]
    public function reaching_zero_raises_depleted_rather_than_low(): void
    {
        // One unit is already below the threshold, so the fixture itself
        // crosses into low stock; the fake goes up after it.
        ['offer' => $offer] = $this->stockedOffer(1);

        Event::fake([InventoryLow::class, InventoryDepleted::class]);

        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Damaged, 'seller', 1);

        Event::assertDispatched(InventoryDepleted::class);
        Event::assertNotDispatched(InventoryLow::class);
    }

    #[Test]
    public function restocking_from_empty_raises_restored(): void
    {
        ['offer' => $offer] = $this->stockedOffer(1);
        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Damaged, 'seller', 1);

        Event::fake([InventoryRestored::class, InventoryLow::class]);

        app(AdjustInventory::class)($offer, 40, InventoryMovementReason::RestockReceived, 'seller', 1);

        Event::assertDispatched(InventoryRestored::class, fn (InventoryRestored $event): bool => $event->available === 40);
    }

    #[Test]
    public function the_adjusted_event_carries_both_sides_of_the_transition(): void
    {
        ['offer' => $offer] = $this->stockedOffer(6);

        Event::fake([InventoryAdjusted::class]);

        app(AdjustInventory::class)($offer, -6, InventoryMovementReason::Damaged, 'seller', 1);

        Event::assertDispatched(
            InventoryAdjusted::class,
            fn (InventoryAdjusted $event): bool => $event->from === StockState::InStock
                && $event->to === StockState::OutOfStock
                && $event->stateChanged()
                && $event->available === 0,
        );
    }
}

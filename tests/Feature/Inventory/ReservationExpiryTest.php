<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Enums\StockState;
use App\Modules\Inventory\Jobs\ExpireReservations;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Notifications\StockLevelChanged;
use App\Modules\Sellers\Enums\SellerRole;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Abandoned holds giving their stock back, and the seller being told
 * exactly once when a listing runs low.
 */
final class ReservationExpiryTest extends TestCase
{
    use RefreshDatabase;
    use StocksOffers;

    #[Test]
    public function an_expired_hold_returns_its_units_to_availability(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(10);

        app(ReserveStock::class)([$offer->id => 4], 'cart-abandoned', ttlMinutes: 20);
        $this->assertSame(6, $balance->refresh()->available);

        Carbon::setTestNow(now()->addMinutes(21));
        app(ExpireReservations::class)->handle(app(ReleaseReservation::class));

        $this->assertSame(10, $balance->refresh()->available);
        $this->assertSame(
            ReservationStatus::Expired,
            InventoryReservation::query()->where('reference', 'cart-abandoned')->firstOrFail()->status,
        );
    }

    #[Test]
    public function a_hold_that_has_not_expired_is_left_alone(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(10);

        app(ReserveStock::class)([$offer->id => 4], 'cart-live', ttlMinutes: 20);

        Carbon::setTestNow(now()->addMinutes(5));
        app(ExpireReservations::class)->handle(app(ReleaseReservation::class));

        $this->assertSame(6, $balance->refresh()->available, 'A live checkout keeps its stock.');
    }

    #[Test]
    public function running_the_sweep_twice_restores_the_units_once(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(10);

        app(ReserveStock::class)([$offer->id => 4], 'cart-abandoned', ttlMinutes: 20);
        Carbon::setTestNow(now()->addMinutes(21));

        $release = app(ReleaseReservation::class);

        app(ExpireReservations::class)->handle($release);
        app(ExpireReservations::class)->handle($release);
        app(ExpireReservations::class)->handle($release);

        // The property the whole queued sweep rests on: three runs, one
        // restoration. A retried job must not invent stock.
        $this->assertSame(10, $balance->refresh()->on_hand);
        $this->assertSame(0, $balance->reserved);
        $this->assertSame(10, $balance->available);

        $this->assertSame(
            1,
            InventoryMovement::query()
                ->where('offer_id', $offer->id)
                ->where('reason', InventoryMovementReason::ReservationExpired->value)
                ->count(),
            'One expiry, one ledger entry.',
        );
    }

    #[Test]
    public function the_sweep_runs_on_the_critical_queue(): void
    {
        Queue::fake();

        ExpireReservations::dispatch();

        // Stock nobody can buy is an availability incident, not a chore to
        // do behind a thousand image derivatives.
        Queue::assertPushedOn(Queues::CRITICAL, ExpireReservations::class);
        $this->assertInstanceOf(ShouldQueue::class, new ExpireReservations);
    }

    #[Test]
    public function expiry_reconciles(): void
    {
        ['offer' => $offer, 'balance' => $balance] = $this->stockedOffer(10);

        app(ReserveStock::class)([$offer->id => 4], 'cart-a', ttlMinutes: 20);
        app(ReserveStock::class)([$offer->id => 3], 'cart-b', ttlMinutes: 60);

        Carbon::setTestNow(now()->addMinutes(21));
        app(ExpireReservations::class)->handle(app(ReleaseReservation::class));

        $this->assertSame(3, $balance->refresh()->reserved);
        $this->assertSame(10, $balance->on_hand);

        // The ledger, the columns and the surviving hold all agree after a
        // sweep — the property that makes a stored `reserved` safe to read.
        $this->runArtisan('inventory:reconcile')->assertSuccessful()->run();
    }

    #[Test]
    public function a_seller_is_told_once_that_a_listing_is_running_low(): void
    {
        ['offer' => $offer] = $this->stockedOffer(6);

        // Faked after the fixture: establishing opening stock is itself a
        // stock change, and counting it here would measure the fixture.
        Notification::fake();

        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Damaged, 'seller', 1);
        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Damaged, 'seller', 1);
        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Damaged, 'seller', 1);

        Notification::assertSentTimes(StockLevelChanged::class, 1);
    }

    #[Test]
    public function a_hold_that_empties_a_listing_notifies_and_its_release_does_not_notify_again(): void
    {
        ['offer' => $offer] = $this->stockedOffer(2);

        Notification::fake();

        // Reserved to zero available: the seller should hear about it,
        // because from a customer's side the listing is unbuyable.
        app(ReserveStock::class)([$offer->id => 2], 'cart-1');
        Notification::assertSentTimes(StockLevelChanged::class, 1);

        // Released and re-held: the state returns to what it already was,
        // so no second warning. This is the cart-abandonment loop §11
        // warns about.
        app(ReleaseReservation::class)('cart-1');
        app(ReserveStock::class)([$offer->id => 2], 'cart-2');

        Notification::assertSentTimes(StockLevelChanged::class, 2);
    }

    #[Test]
    public function the_notification_is_queued_and_never_sent_inline(): void
    {
        $notification = new StockLevelChanged('Aeris Kettle', 'SKU-1', StockState::OutOfStock, 0);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(Queues::EMAILS, $notification->queue);
    }

    #[Test]
    public function only_members_who_could_act_on_it_are_told(): void
    {
        Notification::fake();

        // A finance manager cannot restock, so the mail is not theirs.
        ['seller' => $seller, 'user' => $financeUser] = $this->makeSeller(SellerRole::FinanceManager);
        ['offer' => $offer] = $this->stockedOffer(1, $seller);

        app(AdjustInventory::class)($offer, -1, InventoryMovementReason::Damaged, 'seller', 1);

        Notification::assertNotSentTo($financeUser, StockLevelChanged::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}

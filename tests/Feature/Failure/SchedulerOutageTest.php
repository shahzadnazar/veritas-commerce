<?php

declare(strict_types=1);

namespace Tests\Feature\Failure;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Jobs\ExpireReservations;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Support\Operations\Heartbeat;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\Feature\Payouts\BuildsSellerFinance;
use Tests\TestCase;

/**
 * The scheduler stopped for a while. Then it started again.
 *
 * Everything here degrades safely, which was never in doubt — the sweeps
 * are conditional updates and nothing expires by wall-clock alone. What
 * was in doubt is whether a long silence is *survivable* on resumption:
 * whether a backlog of expired holds is released once rather than twice,
 * whether earnings that have been due for a day transition exactly once,
 * and whether two overlapping sweeps racing each other after a restart
 * can double anything.
 *
 * And underneath all of it, the question the drills actually changed the
 * system over: whether anybody would know. Safe degradation that nobody
 * can see is a seller asking why their money has not moved, days later.
 */
final class SchedulerOutageTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsSellerFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    // ---------------------------------------------------------------
    // Reservation expiry.
    // ---------------------------------------------------------------

    /**
     * A day of missed sweeps is one catch-up sweep.
     *
     * The holds stay held while the scheduler is away, which is correct:
     * the stock is genuinely spoken for until something decides
     * otherwise, and nothing decides by clock alone.
     */
    #[Test]
    public function a_backlog_of_expired_holds_is_released_when_the_sweep_resumes(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 50);

        foreach (range(1, 5) as $_) {
            $this->placeOrder([[$offer, 2]]);
        }

        $held = DB::table('inventory_reservations')->where('status', ReservationStatus::Held->value)->count();
        $this->assertSame(5, $held, 'The fixture did not create the holds this drill needs.');

        // The scheduler is away for a day.
        $this->travel(25)->hours();

        // Still held: nothing expires because time passed.
        $this->assertSame(
            5,
            DB::table('inventory_reservations')->where('status', ReservationStatus::Held->value)->count(),
            'A hold was released without the sweep running.',
        );

        app(ExpireReservations::class)->handle(app(ReleaseReservation::class));

        $this->assertSame(
            0,
            DB::table('inventory_reservations')->where('status', ReservationStatus::Held->value)->count(),
        );
    }

    /**
     * And a second sweep, or two racing after a restart, releases nothing
     * twice.
     *
     * The stock row is the assertion: a double release would show up as
     * `reserved` going negative or `on_hand` climbing past what was
     * bought.
     */
    #[Test]
    public function running_the_expiry_sweep_twice_releases_each_hold_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 50);

        foreach (range(1, 3) as $_) {
            $this->placeOrder([[$offer, 2]]);
        }

        $this->travel(25)->hours();

        $release = app(ReleaseReservation::class);

        app(ExpireReservations::class)->handle($release);
        $afterFirst = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        app(ExpireReservations::class)->handle($release);
        $afterSecond = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        $this->assertEquals($afterFirst?->on_hand, $afterSecond?->on_hand, 'A second sweep changed the stock.');
        $this->assertEquals($afterFirst?->reserved, $afterSecond?->reserved);
        $this->assertSame(0, (int) $afterSecond?->reserved);

        $this->runArtisan('inventory:reconcile')->assertSuccessful()->run();
    }

    // ---------------------------------------------------------------
    // Earnings clearing.
    // ---------------------------------------------------------------

    /**
     * Money does not become spendable because a clock ticked.
     *
     * This is the financially conservative direction, and the important
     * one: the sweep owns the transition, so a scheduler that is away
     * leaves earnings in `clearing` rather than quietly making them
     * withdrawable on the strength of `available_at` having passed. A
     * seller waits; nobody is paid money the platform has not confirmed.
     */
    #[Test]
    public function earnings_do_not_clear_themselves_while_the_sweep_is_away(): void
    {
        $seller = $this->deliveredOrderSeller();

        $this->travel(30)->days();

        $this->assertSame(
            0,
            DB::table('seller_ledger_entries')->where('status', LedgerEntryStatus::Available->value)->count(),
            'An earning became available without the clearing sweep running.',
        );
        $this->assertGreaterThan(0, DB::table('seller_ledger_entries')
            ->where('status', LedgerEntryStatus::Clearing->value)->count());

        $position = $this->positionOf($seller);
        $this->assertSame(0, $position->withdrawableMinor(), 'A seller could withdraw money the sweep had not cleared.');
    }

    /** And when the sweep resumes, everything due transitions exactly once. */
    #[Test]
    public function a_delayed_clearing_sweep_transitions_each_earning_once(): void
    {
        $seller = $this->deliveredOrderSeller();

        $this->travel(30)->days();

        $this->runArtisan('earnings:clear')->assertSuccessful()->run();

        $available = DB::table('seller_ledger_entries')->where('status', LedgerEntryStatus::Available->value)->count();
        $this->assertGreaterThan(0, $available);

        // A second run, or two workers racing after a restart.
        $this->runArtisan('earnings:clear')->assertSuccessful()->run();
        $this->runArtisan('earnings:clear')->assertSuccessful()->run();

        $this->assertSame(
            $available,
            DB::table('seller_ledger_entries')->where('status', LedgerEntryStatus::Available->value)->count(),
            'A repeated clearing sweep moved an earning twice.',
        );

        $this->runArtisan('finance:reconcile-sellers')->assertSuccessful()->run();
    }

    /**
     * The catch-up does not invent a second earning either.
     *
     * Clearing changes the status of rows that already exist; a sweep
     * that inserted instead of updated would double every seller's
     * balance the first time it ran late.
     */
    #[Test]
    public function a_delayed_sweep_does_not_create_new_ledger_rows(): void
    {
        $this->deliveredOrderSeller();

        $before = DB::table('seller_ledger_entries')->count();

        $this->travel(30)->days();

        $this->runArtisan('earnings:clear')->assertSuccessful()->run();
        $this->runArtisan('earnings:clear')->assertSuccessful()->run();

        $this->assertSame($before, DB::table('seller_ledger_entries')->count());
    }

    // ---------------------------------------------------------------
    // Knowing about it.
    // ---------------------------------------------------------------

    /** A finished scheduled task leaves a heartbeat behind. */
    #[Test]
    public function the_scheduler_records_that_it_ran(): void
    {
        $this->assertNull(Heartbeat::lastSeen(Heartbeat::SCHEDULER));

        // A real scheduled task, built by hand: the listener reads the
        // task's own summary, so a stub would not exercise it, and the
        // container's mutex binding is only registered once the console
        // kernel has bootstrapped — which a feature test has not.
        $task = new Event(
            new CacheEventMutex(app(CacheFactory::class)),
            'php artisan earnings:clear',
            'UTC',
        );

        event(new ScheduledTaskFinished($task, 0.1));

        $this->assertNotNull(Heartbeat::lastSeen(Heartbeat::SCHEDULER));
        $this->assertSame(0, Heartbeat::minutesSince(Heartbeat::SCHEDULER));
    }

    /** A silent scheduler is reported rather than merely survived. */
    #[Test]
    public function a_silent_scheduler_fails_the_operational_check(): void
    {
        Heartbeat::record(Heartbeat::SCHEDULER, 'earnings:clear');

        $this->travel(2)->hours();

        $this->runArtisan('ops:queue-health')
            ->expectsOutputToContain('has not completed a task')
            ->assertFailed()
            ->run();
    }

    /** And a scheduler that is running is not reported as a problem. */
    #[Test]
    public function a_healthy_scheduler_is_not_reported(): void
    {
        Heartbeat::record(Heartbeat::SCHEDULER, 'earnings:clear');

        $output = $this->runArtisanOutput('ops:queue-health');

        $this->assertStringNotContainsString('has not completed a task', $output);
        $this->assertStringNotContainsString('never recorded', $output);
    }

    private function runArtisanOutput(string $command): string
    {
        $this->artisan($command);

        return Artisan::output();
    }

    /**
     * A seller whose order was delivered, so the clearing sweep has
     * something real to move.
     */
    private function deliveredOrderSeller(): SellerAccount
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 2]]);
        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->confirm($sellerOrder);
        $shipment = $this->shipEverything($sellerOrder);
        $this->deliver($shipment);

        return $seller;
    }
}

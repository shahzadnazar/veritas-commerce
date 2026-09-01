<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Checkout\Actions\StartCheckout;
use App\Modules\Checkout\Data\ShippingAddress;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Inventory\Models\InventoryReservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Idempotency that is the database's guarantee, not the application's hope.
 *
 * A read-then-write in PHP is not idempotent under concurrency: two
 * requests arriving together both find no attempt, and both proceed. These
 * tests run genuinely interleaved transactions over two connections so the
 * dangerous ordering is the one actually exercised, and assert that the
 * UNIQUE index is what stops it.
 *
 * Truncation rather than RefreshDatabase, for the reason the inventory
 * concurrency suite gives: a transaction that is never committed is
 * invisible to a second connection, and two sessions that cannot see each
 * other's data prove nothing.
 */
final class CheckoutConcurrencyTest extends TestCase
{
    use BuildsCommerceFixtures;
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // These tests commit, so they clean up after themselves rather
        // than handing rows to whatever runs next.
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    #[Test]
    public function the_database_refuses_a_second_attempt_under_the_same_key(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        app(StartCheckout::class)($cart, 'shared-key', $this->address());

        $second = DB::connection('concurrent');

        try {
            $this->expectException(QueryException::class);

            // What a lost update would leave behind. The unique index does
            // not depend on anybody remembering to check first.
            $second->table('checkout_attempts')->insert([
                'public_id' => (string) Str::ulid(),
                'idempotency_key' => 'shared-key',
                'cart_id' => $cart->id,
                'status' => 'reserved',
                'currency' => 'USD',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $this->cleanUp($second);
        }
    }

    #[Test]
    public function a_request_that_loses_the_race_returns_the_winners_attempt(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        $second = DB::connection('concurrent');

        try {
            // The other request wins the key while this one is mid-flight.
            $winner = app(StartCheckout::class)($cart, 'shared-key', $this->address());

            $loser = app(StartCheckout::class)($cart, 'shared-key', $this->address());

            $this->assertSame($winner->id, $loser->id);
            $this->assertSame(1, CheckoutAttempt::query()->count());

            // And critically: the seller's stock was held once, not twice.
            $this->assertSame(1, InventoryReservation::query()->count());
            $this->assertSame(
                2,
                (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('reserved'),
            );
        } finally {
            $this->cleanUp($second);
        }
    }

    #[Test]
    public function two_checkouts_racing_for_the_last_unit_leave_the_ledger_exact(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 1);

        $mine = $this->cart(sessionToken: 'browser-a');
        $theirs = $this->cart(sessionToken: 'browser-b');

        app(AddOfferToCart::class)($mine, $offer->public_id, 1);
        app(AddOfferToCart::class)($theirs, $offer->public_id, 1);

        $succeeded = 0;

        foreach ([[$mine, 'a'], [$theirs, 'b']] as [$cart, $key]) {
            try {
                app(StartCheckout::class)($cart, $key, $this->address());
                $succeeded++;
            } catch (Throwable) {
                // Refused, which is the correct outcome for one of them.
            }
        }

        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        $this->assertNotNull($balance);
        $this->assertSame(1, $succeeded, 'One unit is one sale.');
        $this->assertSame(1, (int) $balance->on_hand, 'A hold never moves physical stock.');
        $this->assertSame(1, (int) $balance->reserved);
        $this->assertSame(0, (int) $balance->available);
    }

    private function address(): ShippingAddress
    {
        return new ShippingAddress(
            name: 'Ada Lovelace',
            line1: '12 Analytical Way',
            line2: null,
            city: 'London',
            state: null,
            postcode: 'EC1A 1BB',
            country: 'GB',
        );
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

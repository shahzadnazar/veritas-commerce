<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Checkout\Actions\StartCheckout;
use App\Modules\Checkout\Data\ShippingAddress;
use App\Modules\Checkout\Enums\CheckoutStatus;
use App\Modules\Checkout\Exceptions\CheckoutRefused;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * A retried checkout must not become two checkouts.
 *
 * §15 and §16. A double-clicked pay button, a refresh after a gateway
 * timeout, a mobile client retrying a dropped request — all present the
 * same idempotency key, and all have to get the same answer, holding the
 * seller's stock exactly once.
 */
final class CheckoutIdempotencyTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    #[Test]
    public function starting_a_checkout_records_an_attempt_and_holds_the_stock(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 3);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());

        $this->assertSame(CheckoutStatus::Reserved, $attempt->status);
        $this->assertSame(12_000, $attempt->grand_total_minor);

        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();
        $this->assertNotNull($balance);
        $this->assertSame(3, (int) $balance->reserved);
        $this->assertSame(7, (int) $balance->available, 'On hand is untouched; only availability falls.');
    }

    #[Test]
    public function the_same_key_returns_the_same_attempt_and_reserves_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        $first = app(StartCheckout::class)($cart, 'key-1', $this->address());
        $second = app(StartCheckout::class)($cart, 'key-1', $this->address());

        // A double-clicked pay button is one checkout.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CheckoutAttempt::query()->count());
        $this->assertSame(1, InventoryReservation::query()->count());
        $this->assertSame(2, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('reserved'));
    }

    #[Test]
    public function a_different_key_on_the_same_cart_is_a_second_hold(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        app(StartCheckout::class)($cart, 'key-1', $this->address());
        app(StartCheckout::class)($cart, 'key-2', $this->address());

        // Not idempotency's job: two distinct keys are two distinct
        // intentions, and both are honoured while stock allows.
        $this->assertSame(2, CheckoutAttempt::query()->count());
        $this->assertSame(4, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('reserved'));
    }

    #[Test]
    public function a_key_presented_for_another_customers_cart_is_refused(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);

        $mine = $this->cart(userId: User::factory()->create()->id);
        $theirs = $this->cart(userId: User::factory()->create()->id, sessionToken: 'other');

        app(AddOfferToCart::class)($mine, $offer->public_id, 1);
        app(AddOfferToCart::class)($theirs, $offer->public_id, 1);

        app(StartCheckout::class)($mine, 'shared-key', $this->address(), $mine->user_id);

        // Handing back the first attempt would hand one customer another's
        // order; starting a second under the same key would defeat the
        // guarantee entirely.
        $this->expectException(CheckoutRefused::class);
        app(StartCheckout::class)($theirs, 'shared-key', $this->address(), $theirs->user_id);
    }

    #[Test]
    public function a_refused_checkout_is_recorded_and_stays_refused_for_that_key(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $offer->forceFill(['status' => OfferStatus::Archived->value])->save();

        try {
            app(StartCheckout::class)($cart, 'key-1', $this->address());
            $this->fail('An unbuyable cart must not start a checkout.');
        } catch (CheckoutRefused $e) {
            $this->assertSame('cart_not_buyable', $e->reason);
        }

        $attempt = CheckoutAttempt::query()->where('idempotency_key', 'key-1')->firstOrFail();
        $this->assertSame(CheckoutStatus::Failed, $attempt->status);
        $this->assertNotNull($attempt->failure_reason);
        $this->assertSame(0, InventoryReservation::query()->count());

        // The same key gives the same answer rather than trying again.
        $this->expectException(CheckoutRefused::class);
        app(StartCheckout::class)($cart, 'key-1', $this->address());
    }

    #[Test]
    public function a_refusal_holds_no_stock_at_all(): void
    {
        ['offer' => $good] = $this->sellableOffer('Kettle', stock: 10);
        ['offer' => $bad, 'seller' => $seller] = $this->sellableOffer('Lamp', stock: 10);

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $good->public_id, 2);
        app(AddOfferToCart::class)($cart, $bad->public_id, 2);

        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        try {
            app(StartCheckout::class)($cart, 'key-1', $this->address());
        } catch (CheckoutRefused) {
            // Expected.
        }

        // Partially holding a refused basket would take a seller's stock
        // off the shelf for a checkout that can never complete.
        $this->assertSame(0, (int) DB::table('inventory_balances')->where('offer_id', $good->id)->value('reserved'));
        $this->assertSame(0, InventoryReservation::query()->count());
    }

    #[Test]
    public function an_empty_cart_cannot_start_a_checkout(): void
    {
        $cart = $this->cart();

        try {
            app(StartCheckout::class)($cart, 'key-1', $this->address());
            $this->fail('An empty basket must not reach an order.');
        } catch (CheckoutRefused $e) {
            $this->assertSame('cart_empty', $e->reason);
        }

        $this->assertSame(0, InventoryReservation::query()->count());
    }

    #[Test]
    public function stock_taken_between_the_quote_and_the_hold_refuses_rather_than_oversells(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 3);

        $mine = $this->cart();
        $theirs = $this->cart(sessionToken: 'other-browser');

        app(AddOfferToCart::class)($mine, $offer->public_id, 3);
        app(AddOfferToCart::class)($theirs, $offer->public_id, 3);

        app(StartCheckout::class)($theirs, 'theirs', $this->address());

        // The lock is the authority, not the read that preceded it.
        try {
            app(StartCheckout::class)($mine, 'mine', $this->address());
            $this->fail('The second checkout must not oversell.');
        } catch (CheckoutRefused $e) {
            $this->assertContains($e->reason, ['stock_unavailable', 'cart_not_buyable']);
        }

        $this->assertSame(3, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('reserved'));
        $this->assertSame(0, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('available'));
    }

    #[Test]
    public function every_hold_a_checkout_takes_carries_its_own_reference(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $a->public_id, 1);
        app(AddOfferToCart::class)($cart, $b->public_id, 2);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());

        $held = InventoryReservation::query()->where('reference', $attempt->reservationReference())->get();

        // One query releases or commits the whole checkout, and an
        // orphaned hold can always be traced back to what took it.
        $this->assertCount(2, $held);
        $this->assertSame([ReservationStatus::Held, ReservationStatus::Held], $held->pluck('status')->all());
        $this->assertStringContainsString($attempt->public_id, $attempt->reservationReference());
    }

    #[Test]
    public function two_lines_of_the_same_offer_are_held_once_for_their_total(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $cart = $this->cart();

        // The customisation seam splits one offer across two lines.
        app(AddOfferToCart::class)($cart, $offer->public_id, 2, ['engraving' => 'A']);
        app(AddOfferToCart::class)($cart, $offer->public_id, 3, ['engraving' => 'B']);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());

        $reservations = InventoryReservation::query()->where('reference', $attempt->reservationReference())->get();

        $this->assertCount(1, $reservations, 'One offer is one hold, however many lines point at it.');
        $this->assertSame(5, (int) $reservations->first()?->quantity);
    }

    #[Test]
    public function the_address_is_snapshotted_onto_the_attempt(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());

        // Frozen, so editing the address book afterwards cannot rewrite
        // where a placed order was sent.
        $this->assertSame('Ada Lovelace', $attempt->shipping_address['name'] ?? null);
        $this->assertSame('GB', $attempt->shipping_address['country'] ?? null);
    }

    #[Test]
    public function an_address_without_a_state_is_accepted(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', new ShippingAddress(
            name: 'Lim Wei',
            line1: '10 Marina Boulevard',
            line2: null,
            city: 'Singapore',
            state: null,
            postcode: '018983',
            country: 'SG',
        ));

        // §33: requiring a state is a US-shaped assumption dressed up as
        // validation, and it would lock out entire countries. The key is
        // present and null, rather than quietly dropped.
        $this->assertIsArray($attempt->shipping_address);
        $this->assertArrayHasKey('state', $attempt->shipping_address);
        $this->assertNull($attempt->shipping_address['state']);
        $this->assertSame(CheckoutStatus::Reserved, $attempt->status);
    }

    #[Test]
    public function the_attempt_expires_within_the_configured_payment_window(): void
    {
        config()->set('veritas.checkout.payment_window_minutes', 45);

        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());

        $this->assertNotNull($attempt->expires_at);
        $this->assertEqualsWithDelta(45, now()->diffInMinutes($attempt->expires_at), 1);

        // The hold expires with the attempt, not on its own schedule.
        $reservation = InventoryReservation::query()->firstOrFail();
        $this->assertEqualsWithDelta(45, now()->diffInMinutes($reservation->expires_at), 1);
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
}

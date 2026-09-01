<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Actions\UpdateCartLine;
use App\Modules\Cart\Events\CartLineAdded;
use App\Modules\Cart\Events\CartLineRemoved;
use App\Modules\Cart\Exceptions\CartOperationRefused;
use App\Modules\Cart\Support\LineIdentity;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Events\Jobs\RecordInteractionEvent;
use App\Modules\Events\Models\InteractionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cart behaviour reaching the analytics stream.
 *
 * The cart actions know nothing about analytics; they announce, and the
 * Events module decides what is worth keeping. These tests assert both
 * halves — that the announcement happens, and that it lands with the offer
 * and the value on it, because "a product was added" without saying whose
 * offer at what price throws away the only thing a marketplace's ranking
 * later needs.
 */
final class CartAnalyticsTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    #[Test]
    public function adding_a_line_announces_it_with_the_offer_and_the_value(): void
    {
        ['offer' => $offer, 'product' => $product, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_500);
        $cart = $this->cart();

        Event::fake([CartLineAdded::class]);

        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        Event::assertDispatched(CartLineAdded::class, function (CartLineAdded $event) use ($offer, $product, $seller): bool {
            return $event->offerId === $offer->id
                && $event->productId === $product->id
                && $event->sellerAccountId === $seller->id
                && $event->quantity === 2
                && $event->unitPriceMinor === 4_500
                && $event->valueMinor() === 9_000;
        });
    }

    #[Test]
    public function a_re_add_reports_both_what_was_added_and_the_new_line_total(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        Event::fake([CartLineAdded::class]);
        app(AddOfferToCart::class)($cart, $offer->public_id, 3);

        // Three were added to a line that now holds four. A model that
        // only saw the line total would over-count the intent.
        Event::assertDispatched(CartLineAdded::class, static fn (CartLineAdded $e): bool => $e->quantity === 3 && $e->lineQuantity === 4);
    }

    #[Test]
    public function removing_a_line_announces_it(): void
    {
        ['offer' => $offer, 'product' => $product] = $this->sellableOffer(priceMinor: 2_000);
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 3);
        $identity = LineIdentity::for($offer->id);

        Event::fake([CartLineRemoved::class]);
        app(UpdateCartLine::class)->remove($cart, $identity);

        Event::assertDispatched(CartLineRemoved::class, static fn (CartLineRemoved $e): bool => $e->offerId === $offer->id
            && $e->productId === $product->id
            && $e->quantity === 3
            && $e->valueMinor() === 6_000);
    }

    #[Test]
    public function stepping_a_quantity_down_to_zero_is_a_removal(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        Event::fake([CartLineRemoved::class]);
        app(UpdateCartLine::class)->setQuantity($cart, LineIdentity::for($offer->id), 0);

        // A quantity control stepped to zero is how most customers remove
        // something; it must not read as a different behaviour.
        Event::assertDispatched(CartLineRemoved::class);
    }

    #[Test]
    public function emptying_a_cart_announces_every_line_not_one_bulk_event(): void
    {
        ['offer' => $kettle] = $this->sellableOffer('Kettle');
        ['offer' => $lamp] = $this->sellableOffer('Lamp');
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $kettle->public_id, 1);
        app(AddOfferToCart::class)($cart, $lamp->public_id, 1);

        Event::fake([CartLineRemoved::class]);
        $this->assertSame(2, app(UpdateCartLine::class)->clear($cart));

        // What was abandoned is the entire signal; "a cart was cleared"
        // records the fact and loses the content.
        Event::assertDispatchedTimes(CartLineRemoved::class, 2);
    }

    #[Test]
    public function a_quantity_change_that_is_not_a_removal_announces_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        Event::fake([CartLineAdded::class, CartLineRemoved::class]);
        app(UpdateCartLine::class)->setQuantity($cart, LineIdentity::for($offer->id), 3);

        Event::assertNotDispatched(CartLineRemoved::class);
        Event::assertNotDispatched(CartLineAdded::class);
    }

    #[Test]
    public function the_announcement_is_recorded_as_a_behavioural_event(): void
    {
        ['offer' => $offer, 'product' => $product, 'seller' => $seller] = $this->sellableOffer(priceMinor: 3_300);
        $cart = $this->cart();

        Bus::fake([RecordInteractionEvent::class]);

        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        // Queued, always: a customer's add-to-cart must not wait on an
        // analytics insert.
        Bus::assertDispatched(RecordInteractionEvent::class, static function (RecordInteractionEvent $job) use ($offer, $product, $seller): bool {
            return $job->type === InteractionEventType::CartItemAdded
                && $job->offerId === $offer->id
                && $job->productId === $product->id
                && $job->sellerAccountId === $seller->id
                && $job->valueMinor === 6_600;
        });
    }

    #[Test]
    public function the_event_lands_in_the_table_with_its_offer_and_value(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 1_250);
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 4);

        /** @var InteractionEvent|null $event */
        $event = InteractionEvent::query()
            ->where('event_type', InteractionEventType::CartItemAdded->value)
            ->first();

        $this->assertNotNull($event, 'The queued analytics job must have written a row.');
        $this->assertSame($offer->id, $event->offer_id);
        $this->assertSame(5_000, $event->value_minor);
    }

    #[Test]
    public function removals_carry_a_negative_affinity_weight(): void
    {
        // Kept with the enum so the offline job and any future model read
        // one definition rather than each inventing a scale.
        $this->assertLessThan(0, InteractionEventType::CartItemRemoved->affinityWeight());
        $this->assertGreaterThan(
            InteractionEventType::ProductViewed->affinityWeight(),
            InteractionEventType::CartItemAdded->affinityWeight(),
        );
    }

    #[Test]
    public function a_refused_add_records_no_behaviour_at_all(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 1);
        $cart = $this->cart();

        Event::fake([CartLineAdded::class]);

        try {
            app(AddOfferToCart::class)($cart, $offer->public_id, 5);
        } catch (CartOperationRefused) {
            // Expected.
        }

        // Dispatched after commit, so an event never describes something
        // that was rolled back.
        Event::assertNotDispatched(CartLineAdded::class);
        $this->assertDatabaseCount('cart_items', 0);
    }
}

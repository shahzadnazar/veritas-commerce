<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Modules\Cart\Support\LineIdentity;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Events\Models\InteractionEvent;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * The behavioural stream, driven through the real HTTP paths.
 *
 * §25's requirement is not that events exist — the domain tests already
 * prove that — but that walking the actual pages produces them exactly
 * once each. A controller that recorded an add-to-cart while a listener
 * was already recording the same domain event would double every intent
 * signal a future ranking model reads, and nothing in a unit test would
 * notice.
 */
final class CheckoutAnalyticsTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    #[Test]
    public function adding_through_the_cart_route_records_one_event(): void
    {
        ['offer' => $offer, 'product' => $product, 'seller' => $seller] = $this->sellableOffer(
            priceMinor: 2_500,
        );

        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 2]);

        $events = $this->eventsOf(InteractionEventType::CartItemAdded);

        // One, not two: the action announces and the listener records.
        // The controller records nothing itself, which is what keeps the
        // count honest.
        $this->assertCount(1, $events);
        $this->assertSame($offer->id, $events[0]->offer_id);
        $this->assertSame($product->id, $events[0]->product_id);
        $this->assertSame($seller->id, $events[0]->seller_account_id);
        $this->assertSame(5_000, $events[0]->value_minor);
    }

    #[Test]
    public function changing_a_quantity_is_its_own_event_not_a_second_add(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 1]);

        $this->patch('/cart/'.LineIdentity::for($offer->id), ['quantity' => 4]);

        // A shopper stepping from one to four has not made a fresh
        // decision to buy, and counting it as an add would inflate every
        // intent signal a re-add produces.
        $this->assertCount(1, $this->eventsOf(InteractionEventType::CartItemAdded));
        $this->assertCount(1, $this->eventsOf(InteractionEventType::CartQuantityChanged));
    }

    #[Test]
    public function removing_through_the_route_records_one_removal(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 3]);

        $this->delete('/cart/'.LineIdentity::for($offer->id));

        $events = $this->eventsOf(InteractionEventType::CartItemRemoved);

        $this->assertCount(1, $events);
        $this->assertSame($offer->id, $events[0]->offer_id);
    }

    #[Test]
    public function stepping_a_quantity_to_zero_records_a_removal_not_a_change(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 2]);

        $this->patch('/cart/'.LineIdentity::for($offer->id), ['quantity' => 0]);

        $this->assertCount(1, $this->eventsOf(InteractionEventType::CartItemRemoved));
        $this->assertCount(0, $this->eventsOf(InteractionEventType::CartQuantityChanged));
    }

    #[Test]
    public function opening_the_checkout_records_a_checkout_started(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);

        $this->get('/checkout');

        $this->assertCount(1, $this->eventsOf(InteractionEventType::CheckoutStarted));
    }

    #[Test]
    public function a_refused_checkout_records_a_validation_failure_and_no_order_event(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);
        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $this->from('/checkout')->post('/checkout', $this->form());

        $failures = $this->eventsOf(InteractionEventType::CheckoutValidationFailed);

        $this->assertCount(1, $failures);
        $this->assertSame('cart_not_buyable', $failures[0]->metadata['reason'] ?? null);
        $this->assertCount(0, $this->eventsOf(InteractionEventType::CheckoutOrderCreated));
    }

    #[Test]
    public function a_completed_checkout_records_the_order_with_its_value(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000);
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 2]);

        $this->post('/checkout', $this->form());

        $created = $this->eventsOf(InteractionEventType::CheckoutOrderCreated);

        $this->assertCount(1, $created);
        $this->assertSame(8_000, $created[0]->value_minor);
        // Not a purchase: nothing has been paid. That event belongs to
        // the milestone that takes the money.
        $this->assertCount(0, $this->eventsOf(InteractionEventType::PurchaseCompleted));
    }

    #[Test]
    public function a_replayed_checkout_does_not_record_a_second_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 1]);

        $form = $this->form();
        $this->post('/checkout', $form);
        $this->post('/checkout', $form);

        // The idempotency key collapses the checkout; the analytics must
        // not disagree with the order book about how many there were.
        $this->assertCount(1, $this->eventsOf(InteractionEventType::CheckoutOrderCreated));
    }

    #[Test]
    public function every_cart_event_carries_the_offer_the_shopper_actually_chose(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);
        $this->delete('/cart/'.LineIdentity::for($offer->id));

        // Which seller at which price is the whole question a
        // marketplace's ranking has to answer later; a product-only event
        // throws it away.
        foreach (InteractionEvent::query()->whereNotNull('offer_id')->get() as $event) {
            $this->assertSame($offer->id, $event->offer_id);
        }

        $this->assertSame(
            2,
            InteractionEvent::query()->whereNotNull('offer_id')->count(),
        );
    }

    /** @return array<int, InteractionEvent> */
    private function eventsOf(InteractionEventType $type): array
    {
        return InteractionEvent::query()
            ->where('event_type', $type->value)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'idempotency_key' => 'abcdefgh12345678',
            'email' => 'ada@example.test',
            'name' => 'Ada Lovelace',
            'line1' => '12 Analytical Way',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'country' => 'GB',
        ];
    }
}

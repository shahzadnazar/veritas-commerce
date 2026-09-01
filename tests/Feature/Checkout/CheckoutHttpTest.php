<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\CustomerAddress;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * The checkout, over HTTP.
 *
 * The form posts an address, an email and an idempotency key. It posts no
 * money at all — these tests assert that nothing priced can be injected
 * through it, and that the totals a customer sees are rebuilt from the
 * live offers on both sides of the button.
 */
final class CheckoutHttpTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    #[Test]
    public function the_checkout_page_shows_the_server_computed_quote(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 3_000);
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 2]);

        $this->get('/checkout')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Index')
                ->where('quote.itemsTotalMinor', 6_000)
                ->where('quote.grandTotalMinor', 6_000)
                ->where('quote.buyable', true)
                ->where('shippingPolicy.taxNote', 'Tax is not calculated at this stage.'));
    }

    #[Test]
    public function an_empty_basket_sends_the_customer_back_to_the_cart(): void
    {
        $this->get('/checkout')->assertRedirect('/cart');
    }

    #[Test]
    public function a_checkout_produces_a_payment_pending_order_and_holds_the_stock(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 2_500, stock: 10);
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 2]);

        $response = $this->post('/checkout', $this->form());

        $order = MarketplaceOrder::query()->firstOrFail();

        $response->assertRedirect('/checkout/'.$order->reference.'/payment');
        $this->assertSame('pending_payment', $order->status->value);
        $this->assertSame(5_000, $order->grand_total_minor);
        $this->assertSame(2, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('reserved'));
        $this->assertSame(10, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('on_hand'));
    }

    #[Test]
    public function the_total_cannot_be_influenced_by_anything_in_the_request(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 9_900);
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 1]);

        // Everything a tamperer might try. None of it is read, because
        // none of it has a field on the server side to land in.
        $this->post('/checkout', [
            ...$this->form(),
            'grand_total_minor' => 1,
            'items_total_minor' => 1,
            'unit_price_minor' => 1,
            'commission_rate_snapshot' => '0.00',
            'quantity' => 999,
        ]);

        $order = MarketplaceOrder::query()->firstOrFail();

        $this->assertSame(9_900, $order->grand_total_minor);
        $this->assertSame(9_900, (int) DB::table('order_items')->value('line_total_minor'));
        $this->assertSame('12.00', (string) DB::table('order_items')->value('commission_rate_snapshot'));
    }

    #[Test]
    public function submitting_the_same_idempotency_key_twice_makes_one_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 2]);

        $form = $this->form();
        $this->post('/checkout', $form);
        $this->post('/checkout', $form);

        // A double-clicked button, a refresh, a retried request: one
        // checkout, one order, one hold.
        $this->assertSame(1, MarketplaceOrder::query()->count());
        $this->assertSame(1, CheckoutAttempt::query()->count());
        $this->assertSame(1, InventoryReservation::query()->count());
        $this->assertSame(2, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('reserved'));
    }

    #[Test]
    public function a_blocked_basket_is_refused_with_a_sentence_not_a_code(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);

        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $this->from('/checkout')->post('/checkout', $this->form())
            ->assertSessionHasErrors('checkout');

        $message = (string) session('errors')?->first('checkout');

        $this->assertStringNotContainsString('SELLER_UNAVAILABLE', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringContainsString('not trading', $message);
        $this->assertSame(0, MarketplaceOrder::query()->count());
    }

    #[Test]
    public function an_archived_offer_is_refused_before_any_order_exists(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);

        $offer->forceFill(['status' => OfferStatus::Archived->value])->save();

        $this->from('/checkout')->post('/checkout', $this->form())->assertSessionHasErrors('checkout');

        $this->assertSame(0, MarketplaceOrder::query()->count());
        $this->assertSame(0, InventoryReservation::query()->count());
    }

    #[Test]
    public function a_stock_shortfall_is_refused_with_actionable_wording(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 2);
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 2]);

        // Someone else buys it in the meantime.
        app(AdjustInventory::class)(
            $offer,
            -2,
            InventoryMovementReason::Lost,
            'seller',
            1,
        );

        $this->from('/checkout')->post('/checkout', $this->form())->assertSessionHasErrors('checkout');

        $message = (string) session('errors')?->first('checkout');

        // Names the state and what to do about it, in words — never
        // "OUT_OF_STOCK", and never a stack trace.
        $this->assertStringContainsString('sold out', $message);
        $this->assertStringNotContainsString('OUT_OF_STOCK', $message);
        $this->assertSame(0, MarketplaceOrder::query()->count());
    }

    #[Test]
    public function an_address_with_no_state_is_accepted(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);

        $this->post('/checkout', [...$this->form(), 'state' => '', 'country' => 'SG']);

        // §33: requiring a state locks out entire countries.
        $order = MarketplaceOrder::query()->firstOrFail();
        $this->assertNull($order->ship_state);
        $this->assertSame('SG', $order->ship_country);
    }

    #[Test]
    public function a_missing_address_is_a_field_error_not_a_crash(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);

        $this->from('/checkout')
            ->post('/checkout', ['idempotency_key' => 'abcdefgh12345678'])
            ->assertSessionHasErrors(['email', 'name', 'line1', 'city', 'postcode', 'country']);

        $this->assertSame(0, MarketplaceOrder::query()->count());
    }

    #[Test]
    public function a_signed_in_customer_can_use_a_saved_address(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();
        $address = CustomerAddress::factory()->default()->create([
            'user_id' => $user->id,
            'name' => 'Ada Lovelace',
            'line1' => '12 Analytical Way',
            'city' => 'London',
            'state' => null,
            'postcode' => 'EC1A 1BB',
            'country' => 'GB',
        ]);

        $this->actingAs($user)->post('/cart', ['offer' => $offer->public_id]);

        $this->actingAs($user)->post('/checkout', [
            'idempotency_key' => 'abcdefgh12345678',
            'saved_address' => $address->public_id,
        ]);

        $order = MarketplaceOrder::query()->firstOrFail();

        $this->assertSame('12 Analytical Way', $order->ship_line1);
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame($user->email, $order->email);
    }

    #[Test]
    public function another_customers_saved_address_cannot_be_used(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $theirAddress = CustomerAddress::factory()->create(['user_id' => $theirs->id]);

        $this->actingAs($mine)->post('/cart', ['offer' => $offer->public_id]);

        $this->actingAs($mine)->from('/checkout')->post('/checkout', [
            'idempotency_key' => 'abcdefgh12345678',
            'saved_address' => $theirAddress->public_id,
        ])->assertSessionHasErrors('saved_address');

        // Resolved through the signed-in customer's own rows, so a public
        // id belonging to somebody else finds nothing.
        $this->assertSame(0, MarketplaceOrder::query()->count());
    }

    #[Test]
    public function the_checkout_page_offers_the_customers_saved_addresses(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();
        CustomerAddress::factory()->default()->create(['user_id' => $user->id, 'label' => 'Home']);
        CustomerAddress::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($user)->post('/cart', ['offer' => $offer->public_id]);

        $this->actingAs($user)->get('/checkout')->assertInertia(fn ($page) => $page
            ->has('addresses', 1)
            ->where('addresses.0.label', 'Home')
            ->where('contact.email', $user->email));
    }

    #[Test]
    public function an_address_can_be_saved_for_next_time(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/cart', ['offer' => $offer->public_id]);
        $this->actingAs($user)->post('/checkout', [...$this->form(), 'save_address' => true]);

        $this->assertSame(1, CustomerAddress::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function the_checkout_page_states_the_shipping_and_tax_policy_in_force(): void
    {
        config()->set('veritas.checkout.shipping_per_seller_order_minor', 0);

        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);

        // Labelled honestly: zero shipping is a policy, and no tax engine
        // runs in M4 — neither may be dressed up as a calculation.
        $this->get('/checkout')->assertInertia(fn ($page) => $page
            ->where('shippingPolicy.label', 'Delivery included')
            ->where('quote.taxTotalMinor', 0)
            ->where('shippingPolicy.taxNote', 'Tax is not calculated at this stage.'));
    }

    #[Test]
    public function delivery_is_quoted_once_per_seller(): void
    {
        config()->set('veritas.checkout.shipping_per_seller_order_minor', 499);

        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');

        $this->post('/cart', ['offer' => $a->public_id]);
        $this->post('/cart', ['offer' => $b->public_id]);

        $this->get('/checkout')->assertInertia(fn ($page) => $page
            ->where('quote.shippingTotalMinor', 998));
    }

    #[Test]
    public function the_checkout_page_reads_every_issue_out_in_words(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000);
        $this->post('/cart', ['offer' => $offer->public_id]);

        $offer->forceFill(['price_minor' => 5_500])->save();

        $this->get('/checkout')->assertInertia(fn ($page) => $page
            ->where('issueMessages.0.code', 'PRICE_CHANGED')
            ->where('issueMessages.0.blocking', false)
            ->where('issueMessages.0.detail', 'Price changed from $50.00 to $55.00 since you added it.'));
    }

    #[Test]
    public function the_checkout_page_costs_a_fixed_number_of_queries(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');
        ['offer' => $c] = $this->sellableOffer('Rug');

        $this->post('/cart', ['offer' => $a->public_id]);
        $one = $this->countQueries(fn () => $this->get('/checkout'));

        $this->post('/cart', ['offer' => $b->public_id]);
        $this->post('/cart', ['offer' => $c->public_id]);
        $three = $this->countQueries(fn () => $this->get('/checkout'));

        $this->assertSame($one, $three, "One line took {$one} queries, three took {$three}.");
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
            'state' => '',
            'postcode' => 'EC1A 1BB',
            'country' => 'GB',
        ];
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Actions\MergeCarts;
use App\Modules\Cart\Enums\CartIssueCode;
use App\Modules\Cart\Enums\CartStatus;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Queries\BuildCartView;
use App\Modules\Cart\Support\LineIdentity;
use App\Modules\Cart\Support\MergeNotice;
use App\Modules\Identity\Models\User;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What happens to a basket at the moment its owner stops being anonymous.
 *
 * §12. The interesting cases are all the ones where the answer is not
 * "add the two together": a seller suspended while the customer was away,
 * a combined quantity larger than the shelf, the same offer in both carts.
 * None of them may be resolved by quietly promising stock that is not
 * there.
 */
final class CartMergeTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    #[Test]
    public function an_anonymous_cart_is_adopted_when_the_customer_had_none(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($anonymous, $offer->public_id, 2);

        app(MergeCarts::class)->adopt($anonymous, $user->id);

        $anonymous->refresh();

        // Adopted rather than copied: same cart, same lines, new owner.
        $this->assertSame($user->id, $anonymous->user_id);
        $this->assertNull(
            $anonymous->session_token,
            'The browser must not keep a handle on an account cart after sign-out.',
        );
        $this->assertSame(CartStatus::Active, $anonymous->status);
        $this->assertSame(2, (int) CartItem::query()->where('cart_id', $anonymous->id)->value('quantity'));
    }

    #[Test]
    public function lines_only_the_anonymous_cart_had_are_carried_over(): void
    {
        ['offer' => $kettle] = $this->sellableOffer('Kettle');
        ['offer' => $lamp] = $this->sellableOffer('Lamp');

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $kettle->public_id, 1);
        app(AddOfferToCart::class)($anonymous, $lamp->public_id, 3);

        $result = app(MergeCarts::class)($anonymous, $mine);

        $this->assertSame(1, $result->moved);
        $this->assertSame(0, $result->combined);
        $this->assertSame([], $result->issues);

        $quantities = CartItem::query()->where('cart_id', $mine->id)
            ->pluck('quantity', 'offer_id')->map(static fn (mixed $q): int => (int) $q)->all();

        $this->assertSame([$kettle->id => 1, $lamp->id => 3], $quantities);
        $this->assertDatabaseCount('cart_items', 2);
    }

    #[Test]
    public function the_same_offer_in_both_carts_becomes_one_line_with_the_combined_quantity(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 20);

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $offer->public_id, 2);
        app(AddOfferToCart::class)($anonymous, $offer->public_id, 3);

        $result = app(MergeCarts::class)($anonymous, $mine);

        $this->assertSame(1, $result->combined);
        $this->assertSame(0, $result->moved);

        // One line, because uniqueness is on (cart_id, line_identity) and
        // the two lines were by definition the same line.
        $lines = CartItem::query()->where('cart_id', $mine->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame(5, (int) $lines->first()?->quantity);
    }

    #[Test]
    public function a_combined_quantity_larger_than_stock_is_capped_and_reported(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 4);

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $offer->public_id, 3);
        app(AddOfferToCart::class)($anonymous, $offer->public_id, 3);

        $result = app(MergeCarts::class)($anonymous, $mine);

        /*
         * §12's hard rule. Six were wanted, four exist, four is what the
         * customer gets — and they are told, because a cap discovered at
         * checkout is worse than a cap explained now.
         */
        $this->assertSame(4, (int) CartItem::query()->where('cart_id', $mine->id)->value('quantity'));

        $this->assertCount(1, $result->issues);
        $this->assertSame(CartIssueCode::QuantityReduced, $result->issues[0]->code);
        $this->assertSame(4, $result->issues[0]->available);
    }

    #[Test]
    public function a_merge_never_puts_more_in_the_cart_than_the_seller_has(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $offer->public_id, 5);
        app(AddOfferToCart::class)($anonymous, $offer->public_id, 5);

        app(MergeCarts::class)($anonymous, $mine);

        $available = (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->sum('available');
        $inCart = (int) CartItem::query()->where('cart_id', $mine->id)->sum('quantity');

        $this->assertLessThanOrEqual($available, $inCart);
        $this->assertSame(5, $inCart);
    }

    #[Test]
    public function an_offer_that_became_unavailable_is_not_carried_over(): void
    {
        ['offer' => $good] = $this->sellableOffer('Kettle');
        ['offer' => $gone, 'seller' => $seller] = $this->sellableOffer('Lamp');

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $good->public_id, 1);
        app(AddOfferToCart::class)($anonymous, $gone->public_id, 1);

        // The seller is suspended while the shopper is away.
        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $result = app(MergeCarts::class)($anonymous, $mine);

        /*
         * Deliberately dropped rather than imported. A blocking line in
         * the account cart would stop the customer checking out with the
         * kettle they can still buy.
         */
        $this->assertSame(1, $result->dropped);
        $this->assertSame(0, $result->moved);
        $this->assertSame(CartIssueCode::OfferUnavailable, $result->issues[0]->code);

        $this->assertDatabaseMissing('cart_items', ['offer_id' => $gone->id]);
        $this->assertSame(1, CartItem::query()->where('cart_id', $mine->id)->count());
    }

    #[Test]
    public function a_line_whose_stock_ran_out_entirely_is_reported_not_imported(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 0);
        ['offer' => $stocked] = $this->sellableOffer('Lamp');

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $stocked->public_id, 1);

        // Added while stock existed, then sold out — written directly
        // because the action would rightly refuse it today.
        CartItem::query()->create([
            'cart_id' => $anonymous->id,
            'offer_id' => $offer->id,
            'line_identity' => LineIdentity::for($offer->id),
            'quantity' => 1,
            'unit_price_at_add_minor' => $offer->price_minor,
        ]);

        $result = app(MergeCarts::class)($anonymous, $mine);

        $this->assertSame(1, $result->dropped);
        $this->assertSame(CartIssueCode::OutOfStock, $result->issues[0]->code);
        $this->assertDatabaseMissing('cart_items', ['offer_id' => $offer->id]);
    }

    #[Test]
    public function the_anonymous_cart_is_retired_rather_than_deleted(): void
    {
        ['offer' => $offer] = $this->sellableOffer();

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $offer->public_id, 1);
        app(AddOfferToCart::class)($anonymous, $offer->public_id, 1);

        app(MergeCarts::class)($anonymous, $mine);

        $anonymous->refresh();

        // Kept, and pointed at the person it turned out to belong to:
        // that link is what an abandonment analysis is made of.
        $this->assertSame(CartStatus::Merged, $anonymous->status);
        $this->assertSame($user->id, $anonymous->user_id);
        $this->assertSame(0, CartItem::query()->where('cart_id', $anonymous->id)->count());
    }

    #[Test]
    public function a_retired_cart_does_not_block_the_browsers_next_one(): void
    {
        ['offer' => $offer] = $this->sellableOffer();

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $offer->public_id, 1);
        app(AddOfferToCart::class)($anonymous, $offer->public_id, 1);
        app(MergeCarts::class)($anonymous, $mine);

        // The one-active-cart index is partial on status, so the same
        // browser token is free to start again.
        $fresh = $this->cart(sessionToken: 'browser-a');

        $this->assertNotSame($anonymous->id, $fresh->id);
        $this->assertSame(CartStatus::Active, $fresh->status);
    }

    #[Test]
    public function merging_reserves_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 6);

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $offer->public_id, 2);
        app(AddOfferToCart::class)($anonymous, $offer->public_id, 2);
        app(MergeCarts::class)($anonymous, $mine);

        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        $this->assertNotNull($balance);
        $this->assertSame(0, (int) $balance->reserved);
        $this->assertSame(6, (int) $balance->available);
    }

    #[Test]
    public function merging_an_empty_anonymous_cart_changes_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer();

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $offer->public_id, 1);

        $result = app(MergeCarts::class)($anonymous, $mine);

        $this->assertFalse($result->changedAnything());
        $this->assertSame(CartStatus::Merged, $anonymous->refresh()->status);
        $this->assertSame(1, CartItem::query()->where('cart_id', $mine->id)->count());
    }

    #[Test]
    public function a_merge_costs_a_fixed_number_of_queries_however_many_lines_it_has(): void
    {
        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        $offers = [];

        for ($i = 0; $i < 6; $i++) {
            ['offer' => $offer] = $this->sellableOffer('Item '.$i);
            $offers[] = $offer;
            app(AddOfferToCart::class)($anonymous, $offer->public_id, 1);
        }

        app(AddOfferToCart::class)($mine, $offers[0]->public_id, 1);

        // Availability and eligibility are looked up once for the whole
        // merge, not once per line.
        DB::enableQueryLog();
        app(MergeCarts::class)($anonymous, $mine);
        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        $lookups = array_filter(
            $queries,
            static fn (array $q): bool => str_contains((string) $q['raw_query'], 'inventory_balances')
                || str_contains((string) $q['raw_query'], 'from "offers"'),
        );

        $this->assertLessThanOrEqual(2, count($lookups), 'A merge must not check eligibility line by line.');
    }

    #[Test]
    public function the_customer_is_told_afterwards_what_the_merge_could_not_honour(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 3);

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $offer->public_id, 2);
        app(AddOfferToCart::class)($anonymous, $offer->public_id, 2);

        $request = $this->requestWithSession();
        MergeNotice::remember($request, app(MergeCarts::class)($anonymous, $mine));

        $first = MergeNotice::drain($request);
        $second = MergeNotice::drain($request);

        // Shown once, whenever the customer next looks at the cart —
        // sign-in rarely lands them on it.
        $this->assertCount(1, $first);
        $this->assertSame(CartIssueCode::QuantityReduced, $first[0]->code);
        $this->assertSame([], $second);
    }

    #[Test]
    public function a_merged_cart_still_revalidates_on_read(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $offer->public_id, 1);
        app(AddOfferToCart::class)($anonymous, $offer->public_id, 1);
        app(MergeCarts::class)($anonymous, $mine);

        // Nothing about a merge freezes a price.
        $offer->forceFill(['price_minor' => 12_500])->save();

        $view = app(BuildCartView::class)($mine->refresh());
        $line = $view->groups[0]->lines[0];

        $this->assertSame(12_500, $line->unitPrice->minor);
        $this->assertSame(CartIssueCode::PriceChanged, $line->issues[0]->code);
    }

    #[Test]
    public function merging_a_cart_into_itself_is_a_no_op(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);

        app(AddOfferToCart::class)($mine, $offer->public_id, 2);

        $result = app(MergeCarts::class)($mine, $mine);

        $this->assertFalse($result->changedAnything());
        $this->assertSame(CartStatus::Active, $mine->refresh()->status);
        $this->assertSame(2, (int) CartItem::query()->where('cart_id', $mine->id)->value('quantity'));
    }

    #[Test]
    public function an_archived_offer_is_dropped_by_the_same_rule_as_a_suspended_seller(): void
    {
        ['offer' => $good] = $this->sellableOffer('Kettle');
        ['offer' => $gone] = $this->sellableOffer('Lamp');

        $user = User::factory()->create();
        $mine = $this->cart(userId: $user->id);
        $anonymous = $this->cart(sessionToken: 'browser-a');

        app(AddOfferToCart::class)($mine, $good->public_id, 1);
        app(AddOfferToCart::class)($anonymous, $gone->public_id, 1);

        $gone->forceFill(['status' => OfferStatus::Archived->value])->save();

        $result = app(MergeCarts::class)($anonymous, $mine);

        // One eligibility rule, so there is no second check to forget.
        $this->assertSame(1, $result->dropped);
        $this->assertSame(CartIssueCode::OfferUnavailable, $result->issues[0]->code);
    }

    private function requestWithSession(): Request
    {
        $request = Request::create('/cart');
        $request->setLaravelSession(app('session.store'));

        return $request;
    }
}

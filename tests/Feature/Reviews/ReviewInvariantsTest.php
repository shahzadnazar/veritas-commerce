<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Offers\Models\Offer;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Reviews\Actions\ModerateReview;
use App\Modules\Reviews\Actions\RecomputeRatingSummary;
use App\Modules\Reviews\Actions\SubmitReview;
use App\Modules\Reviews\Data\ReviewActor;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Exceptions\ReviewRefused;
use App\Modules\Reviews\Models\ProductRatingSummary;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Models\ProductReviewEvent;
use App\Modules\Reviews\Queries\ReviewEligibility;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * Three of the six properties the M8 brief requires proved before any
 * review UI exists:
 *
 *   1. A customer without valid purchase and delivery evidence cannot
 *      manufacture a verified review.
 *   2. One canonical product has ONE rating aggregate, however many
 *      sellers offer it.
 *   3. A hidden or rejected review stops affecting the public rating
 *      immediately.
 *
 * Every purchase below is built through the real chain — place, pay, ship,
 * deliver — because the verified badge rests on exactly that chain.
 */
final class ReviewInvariantsTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsReviewableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    private function admin(): ReviewActor
    {
        return ReviewActor::admin(null, 'Moderator');
    }

    private function summaryFor(Product $product): ProductRatingSummary
    {
        return ProductRatingSummary::query()->where('product_id', $product->id)->firstOrFail();
    }

    // ---------------------------------------------------------------
    // 1. Verified status cannot be manufactured
    // ---------------------------------------------------------------

    #[Test]
    public function a_delivered_buyer_writes_a_verified_review(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $review = $this->review($user, $product, rating: 4);

        $this->assertTrue($review->verified_purchase);
        $this->assertSame(ReviewStatus::Published, $review->status);
        $this->assertSame(4, $review->rating);

        // The evidence is kept, so a moderator can check the claim rather
        // than trust the flag.
        $this->assertNotNull($review->order_item_id);
        $this->assertNotNull($review->seller_order_id);
    }

    #[Test]
    public function somebody_who_never_bought_it_cannot_review_it(): void
    {
        ['product' => $product] = $this->deliveredPurchase();

        $stranger = User::factory()->create();

        try {
            $this->review($stranger, $product);
            $this->fail('A stranger must not be able to review a product.');
        } catch (ReviewRefused $refused) {
            $this->assertSame('not_purchased', $refused->reason);
        }

        $this->assertSame(0, ProductReview::query()->count());
    }

    #[Test]
    public function an_unpaid_order_is_not_evidence(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $user = User::factory()->create();

        // Placed and never paid for.
        $this->placeOrder([[$offer, 1]], (int) $user->id, $user->email);

        /** @var Product $product */
        $product = Product::query()->whereKey($offer->product_id)->firstOrFail();

        try {
            $this->review($user, $product);
            $this->fail('An unpaid order must not produce a verified review.');
        } catch (ReviewRefused $refused) {
            $this->assertSame('not_paid', $refused->reason);
        }
    }

    #[Test]
    public function a_paid_but_undelivered_order_is_not_evidence(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $user = User::factory()->create();

        $order = $this->placeOrder([[$offer, 1]], (int) $user->id, $user->email);
        $this->payFor($order);

        /** @var Product $product */
        $product = Product::query()->whereKey($offer->product_id)->firstOrFail();

        try {
            $this->review($user, $product);
            $this->fail('A parcel that has not arrived is not a reviewable purchase.');
        } catch (ReviewRefused $refused) {
            $this->assertSame('not_delivered', $refused->reason);
        }

        // And it becomes reviewable the moment it is delivered — same
        // customer, same product, nothing else changed.
        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $this->assertTrue($this->review($user, $product)->verified_purchase);
    }

    #[Test]
    public function another_customers_order_is_not_evidence(): void
    {
        ['product' => $product] = $this->deliveredPurchase();

        // A second customer with their own delivered order for something
        // else entirely. Owning *an* order is not owning *this* one.
        ['user' => $other] = $this->deliveredPurchase(priceMinor: 4_000);

        try {
            $this->review($other, $product);
            $this->fail('Another customer\'s purchase is not evidence for this one.');
        } catch (ReviewRefused $refused) {
            $this->assertSame('not_purchased', $refused->reason);
        }
    }

    #[Test]
    public function the_verified_flag_has_nowhere_for_a_client_to_put_it(): void
    {
        /*
         * §4 as a fact about the code rather than a rule somebody follows.
         *
         * SubmitReview takes a customer, a product, a rating and words.
         * There is no `verified_purchase` parameter, no status parameter
         * and no order-item parameter — so a request body claiming any of
         * them reaches nothing, which is a stronger guarantee than
         * validating the claim.
         */
        $parameters = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(SubmitReview::class, '__invoke'))->getParameters(),
        );

        $this->assertSame(
            ['userId', 'productId', 'rating', 'body', 'title', 'actor'],
            $parameters,
        );

        foreach (['verified_purchase', 'verifiedPurchase', 'status', 'orderItemId'] as $forbidden) {
            $this->assertNotContains($forbidden, $parameters);
        }
    }

    #[Test]
    public function a_verified_flag_cannot_be_edited_onto_a_review_afterwards(): void
    {
        $review = ProductReview::factory()->create(['verified_purchase' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $review->update(['verified_purchase' => true]);
    }

    #[Test]
    public function the_database_refuses_a_rating_outside_one_to_five(): void
    {
        $product = Product::factory()->create();
        $user = User::factory()->create();

        foreach ([0, 6, 200] as $rating) {
            // A savepoint per attempt: the first violation aborts the
            // surrounding transaction, and every later statement in it
            // would fail for that reason rather than for the constraint.
            DB::beginTransaction();

            try {
                DB::table('product_reviews')->insert([
                    'public_id' => (string) Str::ulid(),
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'rating' => $rating,
                    'body' => 'Bypassing the domain entirely.',
                    'status' => ReviewStatus::Published->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->fail("A rating of {$rating} must be refused by the database.");
            } catch (QueryException $violation) {
                $this->assertStringContainsString(
                    'product_reviews_rating_is_one_to_five',
                    $violation->getMessage(),
                );
            } finally {
                DB::rollBack();
            }
        }
    }

    #[Test]
    public function the_domain_refuses_a_rating_outside_one_to_five(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        foreach ([0, 6, -3] as $rating) {
            try {
                $this->review($user, $product, rating: $rating);
                $this->fail("A rating of {$rating} must be refused.");
            } catch (ReviewRefused $refused) {
                $this->assertSame('rating_out_of_range', $refused->reason);
            }
        }
    }

    #[Test]
    public function one_customer_gets_one_live_review_per_product(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase(quantity: 3);

        $this->review($user, $product, rating: 5);

        try {
            $this->review($user, $product, rating: 1);
            $this->fail('A second live review must be refused.');
        } catch (ReviewRefused $refused) {
            $this->assertSame('already_reviewed', $refused->reason);
        }

        // And the database says so too, with the domain bypassed.
        $this->expectException(QueryException::class);

        DB::table('product_reviews')->insert([
            'public_id' => (string) Str::ulid(),
            'product_id' => $product->id,
            'user_id' => $user->id,
            'rating' => 1,
            'body' => 'Trying to review the same thing twice.',
            'status' => ReviewStatus::Published->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function withdrawing_frees_the_slot_and_rejection_does_not(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase(quantity: 3);

        $first = $this->review($user, $product, rating: 2);

        // Withdrawn: the customer changed their mind, and may write again.
        app(ModerateReview::class)->withdraw($first, ReviewActor::customer((int) $user->id));

        $second = $this->review($user, $product, rating: 5);
        $this->assertSame(5, $second->rating);

        // Rejected: the platform refused it, and writing another must not
        // be the way around that.
        app(ModerateReview::class)->reject($second, $this->admin(), 'Not about this product.');

        try {
            $this->review($user, $product, rating: 5);
            $this->fail('A rejected review must not be replaceable by another.');
        } catch (ReviewRefused $refused) {
            $this->assertSame('rejected', $refused->reason);
        }
    }

    // ---------------------------------------------------------------
    // 2. One canonical product, one rating aggregate
    // ---------------------------------------------------------------

    #[Test]
    public function two_sellers_of_one_product_share_one_rating(): void
    {
        // Seller A lists the kettle; a customer buys from A and reviews it.
        ['user' => $first, 'product' => $product, 'seller' => $sellerA] = $this->deliveredPurchase();

        // Seller B lists the SAME canonical product.
        $sellerB = SellerAccount::factory()->create(['status' => 'approved']);
        $storeB = Store::factory()->create(['seller_account_id' => $sellerB->id, 'is_open' => true]);

        $offerB = Offer::factory()->create([
            'seller_account_id' => $sellerB->id,
            'store_id' => $storeB->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'price_minor' => 9_000,
            'status' => 'published',
        ]);

        $this->stock($offerB, 5);

        // A second customer buys the same product from B and reviews it.
        $second = User::factory()->create();
        $orderB = $this->placeOrder([[$offerB, 1]], (int) $second->id, $second->email);
        $this->payFor($orderB);
        $this->deliver($this->shipEverything($this->sellerOrderFor($orderB->id)));

        $this->review($first, $product, rating: 5);
        $this->review($second, $product, rating: 3);

        // §3: ONE aggregate for the product, counting both, regardless of
        // which shop each customer bought from.
        $this->assertSame(
            1,
            ProductRatingSummary::query()->where('product_id', $product->id)->count(),
            'A canonical product has exactly one rating summary.',
        );

        $summary = $this->summaryFor($product);

        $this->assertSame(2, $summary->published_review_count);
        $this->assertSame(8, $summary->rating_sum);
        $this->assertSame('4.00', $summary->rating_average);
        $this->assertSame(1, $summary->count_5);
        $this->assertSame(1, $summary->count_3);

        // And no summary row exists keyed by anything else — not by
        // seller, not by offer.
        $this->assertSame(1, ProductRatingSummary::query()->count());
        $this->assertNotSame((int) $sellerA->id, (int) $sellerB->id);
    }

    #[Test]
    public function a_seller_withdrawing_their_offer_leaves_the_rating_alone(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $this->review($user, $product, rating: 5);

        $before = $this->summaryFor($product);
        $this->assertSame(1, $before->published_review_count);

        // §21: the shop stops selling it. The review is about the thing,
        // not about the shop.
        Offer::query()->where('product_id', $product->id)->update(['status' => 'archived']);

        $after = $this->summaryFor($product)->refresh();

        $this->assertSame(1, $after->published_review_count);
        $this->assertSame('5.00', $after->rating_average);
    }

    #[Test]
    public function the_distribution_counts_each_star_separately(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Published->value]);

        foreach ([5, 5, 4, 1] as $rating) {
            ProductReview::factory()->rated($rating)->create(['product_id' => $product->id]);
        }

        app(RecomputeRatingSummary::class)((int) $product->id);

        $summary = $this->summaryFor($product);

        $this->assertSame(4, $summary->published_review_count);
        $this->assertSame(15, $summary->rating_sum);
        $this->assertSame('3.75', $summary->rating_average);
        $this->assertSame([0, 0, 0, 1, 2], [
            $summary->count_2, $summary->count_3, 0, $summary->count_4, $summary->count_5,
        ]);
        $this->assertSame(1, $summary->count_1);
    }

    // ---------------------------------------------------------------
    // 3. Hidden and rejected reviews stop counting immediately
    // ---------------------------------------------------------------

    #[Test]
    public function hiding_a_review_removes_it_from_the_rating_at_once(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();
        ['user' => $other] = $this->secondBuyerOf($product);

        $keep = $this->review($user, $product, rating: 5);
        $this->review($other, $product, rating: 1);

        $this->assertSame('3.00', $this->summaryFor($product)->rating_average);

        // The moderator hides the one-star review.
        $one = ProductReview::query()->where('rating', 1)->firstOrFail();

        $this->assertTrue(
            app(ModerateReview::class)->hide($one, $this->admin(), 'Not about this product.'),
        );

        $after = $this->summaryFor($product)->refresh();

        $this->assertSame(1, $after->published_review_count);
        $this->assertSame('5.00', $after->rating_average);
        $this->assertSame(0, $after->count_1);
        $this->assertSame((int) $keep->id, (int) ProductReview::query()
            ->where('product_id', $product->id)
            ->where('status', ReviewStatus::Published->value)
            ->value('id'));
    }

    #[Test]
    public function rejecting_and_restoring_move_the_rating_both_ways(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();
        ['user' => $other] = $this->secondBuyerOf($product);

        $this->review($user, $product, rating: 5);
        $this->review($other, $product, rating: 1);

        $one = ProductReview::query()->where('rating', 1)->firstOrFail();

        app(ModerateReview::class)->reject($one, $this->admin(), 'Abusive.');
        $this->assertSame('5.00', $this->summaryFor($product)->refresh()->rating_average);

        app(ModerateReview::class)->restore($one->refresh(), $this->admin());

        $restored = $this->summaryFor($product)->refresh();
        $this->assertSame(2, $restored->published_review_count);
        $this->assertSame('3.00', $restored->rating_average);
        // Restoring clears the reason: the review is public again, and a
        // note saying "Abusive" attached to it would be one nobody meant.
        $this->assertNull($one->refresh()->moderation_reason);
    }

    #[Test]
    public function withdrawing_stops_the_review_counting_immediately(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $review = $this->review($user, $product, rating: 4);
        $this->assertSame('4.00', $this->summaryFor($product)->rating_average);

        app(ModerateReview::class)->withdraw($review, ReviewActor::customer((int) $user->id));

        $after = $this->summaryFor($product)->refresh();

        $this->assertSame(0, $after->published_review_count);
        $this->assertNull($after->rating_average, 'No reviews means no average, not 0.00.');
        $this->assertFalse($after->hasPublicRating());

        // §10: the row survives, and so does its history.
        $this->assertSame(ReviewStatus::Withdrawn, $review->refresh()->status);
        $this->assertSame(2, $review->events()->count());
    }

    #[Test]
    public function hiding_and_rejecting_both_demand_a_reason(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($user, $product);

        foreach (['hide', 'reject'] as $action) {
            try {
                app(ModerateReview::class)->{$action}($review->refresh(), $this->admin(), '   ');
                $this->fail("Moderation must not {$action} a review without a reason.");
            } catch (ReviewRefused $refused) {
                $this->assertSame('reason_required', $refused->reason);
            }
        }

        $this->assertSame(ReviewStatus::Published, $review->refresh()->status);
    }

    #[Test]
    public function moderation_never_edits_what_the_customer_wrote(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $review = $this->review($user, $product, body: 'The lid does not fit properly.');

        app(ModerateReview::class)->hide($review, $this->admin(), 'Checking with the seller.');

        $hidden = $review->refresh();

        $this->assertSame('The lid does not fit properly.', $hidden->body);
        $this->assertSame(ReviewStatus::Hidden, $hidden->status);
        $this->assertSame('Checking with the seller.', $hidden->moderation_reason);
    }

    #[Test]
    public function every_decision_is_recorded_with_who_made_it(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $review = $this->review($user, $product);

        $ada = $this->makeAdmin();
        $bo = $this->makeAdmin();

        app(ModerateReview::class)->hide($review, ReviewActor::admin((int) $ada->id, 'Ada'), 'Checking.');
        app(ModerateReview::class)->restore($review->refresh(), ReviewActor::admin((int) $bo->id, 'Bo'));

        $history = $review->events()->orderBy('id')->get();

        $this->assertSame(
            [
                [null, 'published', 'customer', null],
                ['published', 'hidden', 'admin', 'Checking.'],
                ['hidden', 'published', 'admin', null],
            ],
            $history->map(static fn (ProductReviewEvent $event): array => [
                $event->from_status, $event->to_status, $event->actor_type, $event->reason,
            ])->all(),
        );

        $this->assertSame(['Ada', 'Bo'], $history->skip(1)->pluck('actor_label')->all());
    }

    #[Test]
    public function moderation_history_can_never_be_rewritten(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($user, $product);

        $event = $review->events()->firstOrFail();

        try {
            $event->update(['reason' => 'Something else entirely.']);
            $this->fail('Moderation history must be append-only.');
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('append-only', $refused->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $review->delete();
    }

    #[Test]
    public function eligibility_reports_the_reason_a_screen_should_show(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $before = app(ReviewEligibility::class)((int) $user->id, (int) $product->id);

        $this->assertTrue($before->mayReview);
        $this->assertTrue($before->verifiedPurchase);
        $this->assertNull($before->reason);

        $this->review($user, $product);

        $after = app(ReviewEligibility::class)((int) $user->id, (int) $product->id);

        $this->assertFalse($after->mayReview);
        $this->assertSame('already_reviewed', $after->reason?->value);
        $this->assertTrue($after->toArray()['hasExistingReview']);

        // §5: a customer's own order line is not something the screen
        // needs, and it does not travel.
        $this->assertArrayNotHasKey('orderItemId', $after->toArray());
        $this->assertArrayNotHasKey('sellerOrderId', $after->toArray());
    }

    /**
     * A second delivered buyer of the same canonical product.
     *
     * @return array{user: User}
     */
    private function secondBuyerOf(Product $product): array
    {
        /** @var Offer $offer */
        $offer = Offer::query()->where('product_id', $product->id)->firstOrFail();

        // The listing already has stock from the first purchase; opening
        // it a second time is what the inventory domain refuses.
        app(AdjustInventory::class)(
            $offer,
            10,
            InventoryMovementReason::RestockReceived,
            'seller',
            1,
        );

        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], (int) $user->id, $user->email);

        $this->payFor($order);

        $sellerOrder = SellerOrder::query()->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->firstOrFail();

        $this->deliver($this->shipEverything($sellerOrder));

        return ['user' => $user];
    }
}

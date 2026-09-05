<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Actions\ModerateReview;
use App\Modules\Reviews\Data\ReviewActor;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Queries\GetProductReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * The customer-facing review surface: what a browser can send, what it
 * gets back, and what it can never make happen.
 *
 * §4 is the one that matters most here. The request has no field for a
 * verified flag, a status or an order, so the badge on a review is always
 * the server's conclusion about the customer's order history — never
 * something the browser asserted. The tests below try to assert it anyway.
 */
final class ReviewSurfaceTest extends TestCase
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

    // ---------------------------------------------------------------
    // §4: the client cannot claim a verified purchase.
    // ---------------------------------------------------------------

    #[Test]
    public function a_client_claiming_verified_purchase_is_ignored(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $this->actingAs($user)
            ->from('/products/'.$product->slug)
            ->post('/reviews', [
                'product' => $product->public_id,
                'rating' => 5,
                'body' => 'Arrived quickly and works exactly as described.',
                // Every field a hostile client might try.
                'verified_purchase' => true,
                'status' => ReviewStatus::Published->value,
                'order_item_id' => 999_999,
                'seller_order_id' => 999_999,
                'user_id' => 999_999,
                'published_at' => '2020-01-01 00:00:00',
            ])
            ->assertRedirect('/products/'.$product->slug);

        /** @var ProductReview $review */
        $review = ProductReview::query()->firstOrFail();

        $this->assertSame((int) $user->id, $review->user_id, 'The author is the session, never the body.');
        $this->assertTrue($review->verified_purchase, 'The server established this from the order.');
        $this->assertNotSame(999_999, $review->order_item_id);
        $this->assertNotSame(999_999, $review->seller_order_id);
        $this->assertTrue(
            $review->published_at?->isAfter('2024-01-01'),
            'A client-supplied publication date must not be honoured.',
        );
    }

    #[Test]
    public function a_customer_who_never_bought_it_cannot_review_it(): void
    {
        $stranger = User::factory()->create();
        ['product' => $product] = $this->deliveredPurchase();

        $this->actingAs($stranger)
            ->from('/products/'.$product->slug)
            ->post('/reviews', [
                'product' => $product->public_id,
                'rating' => 5,
                'body' => 'I have never seen this product in my life.',
                'verified_purchase' => true,
            ])
            ->assertSessionHasErrors('review');

        $this->assertSame(0, ProductReview::query()->count());
    }

    #[Test]
    public function a_signed_out_visitor_cannot_post_a_review(): void
    {
        ['product' => $product] = $this->deliveredPurchase();

        $this->post('/reviews', [
            'product' => $product->public_id,
            'rating' => 5,
            'body' => 'Anonymous praise, of the kind nobody should be able to leave.',
        ])->assertRedirect('/login');

        $this->assertSame(0, ProductReview::query()->count());
    }

    #[Test]
    public function a_rating_outside_one_to_five_is_refused(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        foreach ([0, 6, -1, 100] as $rating) {
            $this->actingAs($user)
                ->from('/products/'.$product->slug)
                ->post('/reviews', [
                    'product' => $product->public_id,
                    'rating' => $rating,
                    'body' => 'A body long enough to pass the minimum length rule.',
                ])
                ->assertSessionHasErrors('rating');
        }

        $this->assertSame(0, ProductReview::query()->count());
    }

    // ---------------------------------------------------------------
    // §13: review text is untrusted.
    // ---------------------------------------------------------------

    #[Test]
    public function markup_never_survives_into_a_stored_review(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $this->actingAs($user)
            ->from('/products/'.$product->slug)
            ->post('/reviews', [
                'product' => $product->public_id,
                'rating' => 4,
                'title' => '<script>alert(1)</script>Great',
                'body' => '<img src=x onerror="alert(1)">Works well. '.
                    '<a href="javascript:alert(1)">click</a> '.
                    '<iframe src="//evil.test"></iframe> '.
                    '&lt;script&gt;alert(2)&lt;/script&gt;',
            ])
            ->assertRedirect('/products/'.$product->slug);

        /** @var ProductReview $review */
        $review = ProductReview::query()->firstOrFail();

        foreach ([$review->body, (string) $review->title] as $text) {
            $this->assertStringNotContainsString('<script', strtolower($text));
            $this->assertStringNotContainsString('<img', strtolower($text));
            $this->assertStringNotContainsString('<iframe', strtolower($text));
            $this->assertStringNotContainsString('<a ', strtolower($text));
            $this->assertStringNotContainsString('onerror', strtolower($text));
            $this->assertStringNotContainsString('javascript:', strtolower($text));
        }

        $this->assertStringContainsString('Works well.', $review->body);
        $this->assertStringContainsString('Great', (string) $review->title);
    }

    #[Test]
    public function a_review_made_entirely_of_markup_is_refused(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $this->actingAs($user)
            ->from('/products/'.$product->slug)
            ->post('/reviews', [
                'product' => $product->public_id,
                'rating' => 5,
                'body' => '<b><i><span></span></i></b><script></script>',
            ])
            ->assertSessionHasErrors('review');

        $this->assertSame(
            0,
            ProductReview::query()->count(),
            'Four thousand characters of tags is not a four-thousand-character review.',
        );
    }

    #[Test]
    public function stored_review_text_reaches_the_page_verbatim(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $this->review($user, $product, body: 'Sturdy handle & a good spout. 5 > 4 stars.');

        $this->get('/products/'.$product->slug)
            ->assertOk()
            // The characters survive intact: an ampersand is an ampersand
            // and a greater-than is a greater-than. Escaping is the
            // renderer's job, not the store's, and a review that came back
            // as "5 &amp;gt; 4" would be a review the site had corrupted.
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('reviews.data.0.body', 'Sturdy handle & a good spout. 5 > 4 stars.')
            );
    }

    /**
     * The star histogram's payload shape, pinned.
     *
     * The page sends the distribution as a list of rows and the component
     * renders the list. It was once sent as a list and read as a map
     * keyed by star, which in JavaScript quietly resolves to a row object
     * — and React refuses to render an object as a child, so the whole
     * product page threw as soon as a product had a rating. The bug was
     * invisible to the type checker because the declared type described
     * the map that was never sent, and invisible to CI because the seeded
     * product had no reviews and the histogram is skipped without one.
     *
     * Asserting the shape here means the TypeScript type and this test
     * have to be changed together, which is the only pairing that makes
     * the declared type mean anything.
     */
    #[Test]
    public function the_rating_distribution_reaches_the_page_as_ordered_rows(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $this->review($user, $product, rating: 4);

        $this->get('/products/'.$product->slug)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                /** @var array<int, mixed> $rows */
                $rows = $page->toArray()['props']['rating']['distribution'];

                // A list, not a map: sequential integer keys, five of
                // them, highest star first.
                $this->assertSame([0, 1, 2, 3, 4], array_keys($rows));
                $this->assertSame(
                    [5, 4, 3, 2, 1],
                    array_map(static fn (array $row): int => $row['rating'], $rows),
                );

                // Every row carries the share the component draws the bar
                // from, so the arithmetic stays on this side.
                foreach ($rows as $row) {
                    $this->assertSame(['rating', 'count', 'percent'], array_keys($row));
                    $this->assertIsInt($row['count']);
                    $this->assertIsInt($row['percent']);
                }

                $this->assertSame(1, $rows[1]['count']);
                $this->assertSame(100, $rows[1]['percent']);
            });
    }

    /**
     * §13: React escaping is not the only rendering context this text
     * reaches.
     *
     * Before it reaches React, the page's props travel inside a
     * `<script type="application/json">` block in the served HTML. A
     * review body that could close that block early would run in the
     * page's own origin, and React would never get the chance to escape
     * anything.
     *
     * Two independent defences hold, and this asserts the outcome of both:
     * the server strips markup before storing, and the payload's forward
     * slashes are escaped on the way out, so a literal `</script>` cannot
     * be spelled in the emitted JSON at all.
     */
    #[Test]
    public function a_review_cannot_close_the_props_script_block(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $clean = $this->get('/products/'.$product->slug)->getContent() ?: '';
        $scriptsBefore = substr_count($clean, '<script');

        $this->actingAs($user)
            ->from('/products/'.$product->slug)
            ->post('/reviews', [
                'product' => $product->public_id,
                'rating' => 5,
                'body' => 'Good kettle </script><script>alert(1)</script> and a fair price.',
            ])
            ->assertRedirect('/products/'.$product->slug);

        $html = $this->get('/products/'.$product->slug)->getContent() ?: '';

        $this->assertSame(
            $scriptsBefore,
            substr_count($html, '<script'),
            'A review body added a script tag to the page.',
        );
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('</script><script', $html);

        // What is stored is plain text: the tags never made it to the
        // database, so nothing downstream has to remember to escape them.
        $this->assertSame(
            'Good kettle alert(1) and a fair price.',
            ProductReview::query()->value('body'),
        );
    }

    // ---------------------------------------------------------------
    // The read surface.
    // ---------------------------------------------------------------

    #[Test]
    public function only_published_reviews_reach_the_page(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product, body: 'A perfectly ordinary review of a kettle.');

        $this->get('/products/'.$product->slug)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reviews.total', 1)
                ->where('rating.reviewCount', 1)
            );

        $admin = $this->makeAdmin();
        app(ModerateReview::class)->hide(
            $review,
            ReviewActor::admin((int) $admin->id),
            'Off topic.',
        );

        $this->get('/products/'.$product->slug)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reviews.total', 0)
                ->where('rating.reviewCount', 0)
                ->where('rating.hasRating', false)
            );
    }

    #[Test]
    public function an_author_still_sees_their_hidden_review_and_why(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product);

        $admin = $this->makeAdmin();
        app(ModerateReview::class)->hide(
            $review,
            ReviewActor::admin((int) $admin->id),
            'Mentions a competitor.',
        );

        $this->actingAs($author)
            ->get('/products/'.$product->slug)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reviews.total', 0)
                ->where('reviews.mine.isPublic', false)
                ->where('reviews.mine.moderationReason', 'Mentions a competitor.')
            );
    }

    #[Test]
    public function the_reviews_list_never_carries_an_email_address(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $this->review($author, $product);

        $reviews = app(GetProductReviews::class)((int) $product->id);
        $row = $reviews['data'][0];

        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('userId', $row);
        $this->assertStringNotContainsString('@', $row['author']);
        $this->assertStringNotContainsString($author->email, json_encode($reviews) ?: '');
    }

    #[Test]
    public function verified_reviews_are_listed_before_unverified_ones(): void
    {
        ['user' => $verified, 'product' => $product] = $this->deliveredPurchase();
        $this->review($verified, $product, body: 'A verified review of the kettle I bought.');

        // An unverified review, of the kind only a data migration could
        // produce today — listed, but never above a purchase-backed one.
        $other = User::factory()->create();
        ProductReview::query()->create([
            'product_id' => $product->id,
            'user_id' => $other->id,
            'rating' => 1,
            'body' => 'An unverified opinion about a kettle.',
            'status' => ReviewStatus::Published->value,
            'verified_purchase' => false,
            'published_at' => now()->addHour(),
        ]);

        $rows = app(GetProductReviews::class)((int) $product->id)['data'];

        $this->assertTrue($rows[0]['verifiedPurchase']);
        $this->assertFalse($rows[1]['verifiedPurchase']);
    }

    // ---------------------------------------------------------------
    // Editing and removing.
    // ---------------------------------------------------------------

    #[Test]
    public function a_customer_may_edit_their_own_review(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product);

        $this->actingAs($author)
            ->from('/products/'.$product->slug)
            ->put('/reviews/'.$review->public_id, [
                'rating' => 3,
                'body' => 'On reflection, the lid is loose and it drips.',
                'title' => 'Downgraded',
            ])
            ->assertRedirect('/products/'.$product->slug);

        $review->refresh();

        $this->assertSame(3, $review->rating);
        $this->assertSame('Downgraded', $review->title);
        $this->assertTrue($review->verified_purchase, 'Editing must not disturb the verified badge.');
    }

    #[Test]
    public function a_customer_cannot_touch_somebody_elses_review(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->put('/reviews/'.$review->public_id, [
                'rating' => 1,
                'body' => 'Sabotaging somebody else s review of a kettle.',
            ])
            ->assertNotFound();

        $this->actingAs($stranger)
            ->delete('/reviews/'.$review->public_id)
            ->assertNotFound();

        $this->assertSame(5, $review->refresh()->rating);
        $this->assertSame(ReviewStatus::Published, $review->status);
    }

    #[Test]
    public function withdrawing_a_review_removes_it_from_the_rating_at_once(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product);

        $this->actingAs($author)
            ->from('/products/'.$product->slug)
            ->delete('/reviews/'.$review->public_id)
            ->assertRedirect('/products/'.$product->slug);

        $this->assertSame(ReviewStatus::Withdrawn, $review->refresh()->status);

        $this->get('/products/'.$product->slug)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rating.hasRating', false)
                ->where('rating.reviewCount', 0)
            );
    }

    #[Test]
    public function the_page_says_why_a_visitor_may_not_write_one(): void
    {
        ['product' => $product] = $this->deliveredPurchase();
        $stranger = User::factory()->create();

        $this->get('/products/'.$product->slug)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reviews.canWrite.allowed', false)
                ->where('reviews.canWrite.reason', 'sign_in')
            );

        $this->actingAs($stranger)
            ->get('/products/'.$product->slug)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reviews.canWrite.allowed', false)
                ->where('reviews.canWrite.reason', 'not_purchased')
            );
    }

    #[Test]
    public function a_buyer_who_took_delivery_is_told_they_may_write_one(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $this->actingAs($user)
            ->get('/products/'.$product->slug)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reviews.canWrite.allowed', true)
                ->where('reviews.canWrite.reason', null)
            );
    }
}

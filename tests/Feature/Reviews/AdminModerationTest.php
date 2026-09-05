<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Models\ProductReviewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * Review moderation, over HTTP.
 *
 * §8: there is no approval step. A verified review is live before a
 * moderator ever sees it, so what these test is the other direction —
 * that taking one down requires the right permission and a written
 * reason, that it stops counting towards the rating the instant it comes
 * down, and that every decision leaves a record.
 */
final class AdminModerationTest extends TestCase
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
    // Access.
    // ---------------------------------------------------------------

    #[Test]
    public function a_role_without_the_permission_cannot_open_the_queue(): void
    {
        $this->asAdmin($this->makeAdmin(AdminRole::SellerOperations))
            ->get('/admin/reviews')
            ->assertForbidden();
    }

    #[Test]
    public function support_reads_the_queue_and_cannot_moderate(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product);

        $support = $this->makeAdmin(AdminRole::Support);

        $this->asAdmin($support)->get('/admin/reviews')->assertOk();

        $this->asAdmin($support)
            ->post("/admin/reviews/{$review->public_id}/hide", ['reason' => 'Off topic entirely.'])
            ->assertForbidden();

        $this->assertSame(ReviewStatus::Published, $review->refresh()->status);
    }

    #[Test]
    public function a_signed_out_visitor_cannot_reach_the_queue(): void
    {
        $this->get('/admin/reviews')->assertRedirect('/admin/login');
    }

    // ---------------------------------------------------------------
    // The queue.
    // ---------------------------------------------------------------

    #[Test]
    public function the_queue_lists_reviews_with_counts_per_state(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $this->review($author, $product, body: 'A perfectly ordinary review of a kettle.');

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->get('/admin/reviews')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reviews/Index')
                ->has('reviews.data', 1)
                ->where('reviews.data.0.verifiedPurchase', true)
                ->where('reviews.data.0.status', ReviewStatus::Published->value)
                ->where('counts.published', 1)
                ->where('counts.hidden', 0)
            );
    }

    #[Test]
    public function the_queue_can_be_filtered_and_searched(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $this->review($author, $product, body: 'The spout on this kettle drips badly.');

        $admin = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($admin)
            ->get('/admin/reviews?search=drips')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('reviews.data', 1));

        $this->asAdmin($admin)
            ->get('/admin/reviews?search=teapot')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('reviews.data', 0));

        $this->asAdmin($admin)
            ->get('/admin/reviews?status='.ReviewStatus::Hidden->value)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('reviews.data', 0));
    }

    #[Test]
    public function the_queue_never_carries_a_customers_email(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $this->review($author, $product);

        $response = $this->asAdmin($this->makeAdmin())->get('/admin/reviews');
        $response->assertOk();

        $this->assertStringNotContainsString(
            (string) $author->email,
            $response->getContent() ?: '',
        );
    }

    // ---------------------------------------------------------------
    // Decisions.
    // ---------------------------------------------------------------

    #[Test]
    public function hiding_a_review_needs_a_reason_and_takes_it_off_the_rating(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product);

        $admin = $this->makeAdmin(AdminRole::CatalogModerator);

        // No reason: refused, and nothing changes.
        $this->asAdmin($admin)
            ->from('/admin/reviews')
            ->post("/admin/reviews/{$review->public_id}/hide", [])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ReviewStatus::Published, $review->refresh()->status);

        $this->asAdmin($admin)
            ->from('/admin/reviews')
            ->post("/admin/reviews/{$review->public_id}/hide", ['reason' => 'Mentions a competitor.'])
            ->assertRedirect('/admin/reviews');

        $review->refresh();

        $this->assertSame(ReviewStatus::Hidden, $review->status);
        $this->assertSame('Mentions a competitor.', $review->moderation_reason);
        $this->assertSame((int) $admin->id, $review->moderated_by_admin_id);

        // The rating stops counting it in the same moment, not on the next
        // queue run: ModerateReview recomputes inside the transaction.
        $this->get('/products/'.$product->slug)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rating.hasRating', false)
                ->where('rating.reviewCount', 0)
            );
    }

    #[Test]
    public function rejecting_and_hiding_are_recorded_as_different_decisions(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product);

        $admin = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($admin)
            ->from('/admin/reviews')
            ->post("/admin/reviews/{$review->public_id}/reject", ['reason' => 'Abusive language.'])
            ->assertRedirect('/admin/reviews');

        $this->assertSame(ReviewStatus::Rejected, $review->refresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'review.reject',
            'actor_type' => 'admin',
            'actor_id' => $admin->id,
            'subject_type' => 'product_review',
            'subject_id' => $review->id,
        ]);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'review.hide']);
    }

    #[Test]
    public function restoring_puts_the_review_and_the_rating_back(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product, rating: 4);

        $admin = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($admin)->from('/admin/reviews')
            ->post("/admin/reviews/{$review->public_id}/hide", ['reason' => 'Checking something.']);

        $this->asAdmin($admin)->from('/admin/reviews')
            ->post("/admin/reviews/{$review->public_id}/restore", ['reason' => 'My mistake.'])
            ->assertRedirect('/admin/reviews');

        $this->assertSame(ReviewStatus::Published, $review->refresh()->status);

        $this->get('/products/'.$product->slug)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rating.hasRating', true)
                ->where('rating.reviewCount', 1)
                ->where('rating.average', 4)
            );
    }

    #[Test]
    public function every_decision_appends_to_the_reviews_own_history(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product);

        $admin = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($admin)->from('/admin/reviews')
            ->post("/admin/reviews/{$review->public_id}/hide", ['reason' => 'One.']);
        $this->asAdmin($admin)->from('/admin/reviews')
            ->post("/admin/reviews/{$review->public_id}/restore", ['reason' => 'Two.']);

        $events = ProductReviewEvent::query()
            ->where('product_review_id', $review->id)
            ->orderBy('id')
            ->pluck('to_status')
            ->all();

        $this->assertContains(ReviewStatus::Hidden->value, $events);
        $this->assertContains(ReviewStatus::Published->value, $events);
    }

    #[Test]
    public function repeating_a_decision_is_reported_rather_than_failing(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product);

        $admin = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($admin)->from('/admin/reviews')
            ->post("/admin/reviews/{$review->public_id}/hide", ['reason' => 'Once.']);

        $before = AuditLog::query()->where('action', 'review.hide')->count();

        $this->asAdmin($admin)->from('/admin/reviews')
            ->post("/admin/reviews/{$review->public_id}/hide", ['reason' => 'Again.'])
            ->assertRedirect('/admin/reviews')
            ->assertSessionHas('status');

        $this->assertSame(ReviewStatus::Hidden, $review->refresh()->status);
        $this->assertSame(
            $before,
            AuditLog::query()->where('action', 'review.hide')->count(),
            'A no-op decision must not manufacture an audit entry.',
        );
    }

    #[Test]
    public function a_guessed_review_identifier_is_a_404(): void
    {
        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->post('/admin/reviews/01JQQQQQQQQQQQQQQQQQQQQQQQ/hide', ['reason' => 'Fishing.'])
            ->assertNotFound();

        $this->assertSame(0, ProductReview::query()->count());
    }

    #[Test]
    public function a_withdrawn_review_cannot_be_restored_by_a_moderator(): void
    {
        ['user' => $author, 'product' => $product] = $this->deliveredPurchase();
        $review = $this->review($author, $product);

        $this->actingAs($author, 'web')->delete('/reviews/'.$review->public_id);

        $this->assertSame(ReviewStatus::Withdrawn, $review->refresh()->status);

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->from('/admin/reviews')
            ->post("/admin/reviews/{$review->public_id}/restore", ['reason' => 'Putting it back.'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(
            ReviewStatus::Withdrawn,
            $review->refresh()->status,
            'A customer who removed their review decided that; a moderator does not undecide it.',
        );
    }
}

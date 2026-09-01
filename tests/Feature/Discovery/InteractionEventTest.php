<?php

declare(strict_types=1);

namespace Tests\Feature\Discovery;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Events\Jobs\RecordInteractionEvent;
use App\Modules\Events\Models\InteractionEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behaviour is recorded, off the request, without an account.
 *
 * These events are the training data a recommender built in month six will
 * need; a recommender built on no history recommends noise. They are also
 * deliberately not the audit log — §48 keeps accountability and analytics
 * in different tables.
 */
final class InteractionEventTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    #[Test]
    public function a_search_is_recorded_with_what_it_found(): void
    {
        $this->publishedProduct('Aeris Kettle');

        $this->get('/search?q=Aeris')->assertOk();

        $event = InteractionEvent::query()
            ->where('event_type', InteractionEventType::SearchPerformed->value)
            ->firstOrFail();

        $this->assertSame('Aeris', $event->search_query);
        $this->assertSame(1, $event->metadata['results'] ?? null);
        $this->assertFalse($event->metadata['zero_results'] ?? true);
    }

    #[Test]
    public function a_search_that_found_nothing_is_recorded_as_such(): void
    {
        $this->publishedProduct('Aeris Kettle');

        $this->get('/search?q=zzzznothing')->assertOk();

        $event = InteractionEvent::query()
            ->where('event_type', InteractionEventType::SearchPerformed->value)
            ->firstOrFail();

        // The most actionable row in the whole stream: a search people
        // repeat that finds nothing is a product to source.
        $this->assertSame(0, $event->metadata['results'] ?? null);
        $this->assertTrue($event->metadata['zero_results'] ?? false);
    }

    #[Test]
    public function landing_on_search_without_typing_anything_is_not_a_search(): void
    {
        $this->get('/search')->assertOk();

        $this->assertSame(
            0,
            InteractionEvent::query()->where('event_type', InteractionEventType::SearchPerformed->value)->count(),
        );
    }

    #[Test]
    public function a_result_click_records_the_product_and_its_position(): void
    {
        $product = $this->publishedProduct('Aeris Kettle');

        $this->post('/search/click', [
            'product' => $product->public_id,
            'position' => 3,
            'query' => 'kettle',
        ])->assertOk();

        $event = InteractionEvent::query()
            ->where('event_type', InteractionEventType::SearchResultClicked->value)
            ->firstOrFail();

        // Position is the field that makes this usable as ranking training
        // data later; without it a click says almost nothing.
        $this->assertSame($product->id, $event->product_id);
        $this->assertSame(3, $event->result_position);
        $this->assertSame('kettle', $event->search_query);
    }

    #[Test]
    public function a_product_view_is_recorded(): void
    {
        $product = $this->publishedProduct('Aeris Kettle');

        $this->get('/products/'.$product->slug)->assertOk();

        $this->assertDatabaseHas('interaction_events', [
            'event_type' => InteractionEventType::ProductViewed->value,
            'product_id' => $product->id,
        ]);
    }

    #[Test]
    public function a_category_view_is_recorded(): void
    {
        $product = $this->publishedProduct('Aeris Kettle');
        $category = Category::query()->findOrFail($product->category_id);

        $this->get('/categories/'.$category->slug)->assertOk();

        $this->assertDatabaseHas('interaction_events', [
            'event_type' => InteractionEventType::CategoryViewed->value,
        ]);
    }

    #[Test]
    public function a_store_view_is_recorded_against_the_seller(): void
    {
        $product = $this->publishedProduct('Aeris Kettle');
        $offer = Offer::query()->where('product_id', $product->id)->firstOrFail();
        $store = Store::query()->findOrFail($offer->store_id);

        $this->get('/stores/'.$store->slug)->assertOk();

        $this->assertDatabaseHas('interaction_events', [
            'event_type' => InteractionEventType::SellerStoreViewed->value,
            'seller_account_id' => $offer->seller_account_id,
        ]);
    }

    #[Test]
    public function browsing_works_and_is_recorded_without_an_account(): void
    {
        $this->publishedProduct('Aeris Kettle');

        $this->get('/search?q=Aeris')->assertOk();

        $event = InteractionEvent::query()->firstOrFail();

        // No account, and still attributable to one browsing session —
        // which is all a ranking model needs.
        $this->assertNull($event->user_id);
        $this->assertNotNull($event->anonymous_session_id);
    }

    #[Test]
    public function a_signed_in_customer_carries_both_identities(): void
    {
        $this->publishedProduct('Aeris Kettle');
        $user = User::factory()->create();

        $this->asUser($user)->get('/search?q=Aeris')->assertOk();

        $event = InteractionEvent::query()->firstOrFail();

        // The session id is what stitches behaviour from before signing in
        // to behaviour after.
        $this->assertSame($user->id, $event->user_id);
        $this->assertNotNull($event->anonymous_session_id);
    }

    #[Test]
    public function the_same_browser_keeps_one_session_identifier(): void
    {
        $this->publishedProduct('Aeris Kettle');

        $this->get('/search?q=Aeris')->assertOk();
        $this->get('/search?q=Kettle')->assertOk();

        $ids = InteractionEvent::query()
            ->where('event_type', InteractionEventType::SearchPerformed->value)
            ->pluck('anonymous_session_id')
            ->unique();

        $this->assertCount(1, $ids, 'Two searches in one session are one visitor.');
    }

    #[Test]
    public function analytics_never_blocks_the_customer(): void
    {
        Queue::fake();
        $this->publishedProduct('Aeris Kettle');

        $this->get('/search?q=Aeris')->assertOk();

        // §34: the write happens on a worker, on the lowest-priority
        // queue. A slow analytics insert must not become a slow search.
        Queue::assertPushedOn(Queues::DEFAULT, RecordInteractionEvent::class);
    }

    #[Test]
    public function behavioural_events_stay_out_of_the_audit_log(): void
    {
        $product = $this->publishedProduct('Aeris Kettle');

        $this->get('/products/'.$product->slug)->assertOk();
        $this->get('/search?q=Aeris')->assertOk();

        // §48: an audit table carrying every anonymous view stops being
        // the place you look to answer "who suspended this seller".
        $this->assertDatabaseMissing('audit_logs', ['action' => 'product_viewed']);
        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'like', '%search%')->count(),
        );
    }

    private function publishedProduct(string $title): Product
    {
        $product = Product::factory()->create([
            'title' => $title,
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
        ]);

        $seller = SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
        $store = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'status' => OfferStatus::Published->value,
        ]);

        $this->reindex($product);

        return $product->refresh();
    }
}

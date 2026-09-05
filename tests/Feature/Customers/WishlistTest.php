<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Customers\Actions\RemoveFromWishlist;
use App\Modules\Customers\Actions\SaveToWishlist;
use App\Modules\Customers\Models\WishlistItem;
use App\Modules\Customers\Queries\GetWishlist;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Recommendations\BuildsRecommendationFixtures;
use Tests\TestCase;

/**
 * A wishlist holds canonical products, one entry each, and belongs to
 * exactly one customer.
 *
 * The isolation tests matter more than the rest: every route takes the
 * customer from the session, so the only way to reach somebody else's list
 * would be a bug in an action — and these are what would catch it.
 */
final class WishlistTest extends TestCase
{
    use BuildsRecommendationFixtures;
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Identity and idempotency.
    // ---------------------------------------------------------------

    #[Test]
    public function saving_the_same_product_twice_is_one_entry(): void
    {
        $user = User::factory()->create();
        $product = $this->listedProduct('Kettle')['product'];

        $first = app(SaveToWishlist::class)((int) $user->id, (int) $product->id);
        $second = app(SaveToWishlist::class)((int) $user->id, (int) $product->id);

        $this->assertSame($first->id, $second->id, 'Two taps on the heart are one save.');
        $this->assertSame(1, WishlistItem::query()->count());
        $this->assertSame(
            $first->created_at->toIso8601String(),
            $second->refresh()->created_at->toIso8601String(),
            'A repeat save must not reset when the customer first saved it.',
        );
    }

    #[Test]
    public function the_database_refuses_a_duplicate_even_without_the_action(): void
    {
        $user = User::factory()->create();
        $product = $this->listedProduct('Kettle')['product'];

        DB::table('wishlist_items')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'product_id' => $product->id,
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('wishlist_items')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'product_id' => $product->id,
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function two_customers_may_save_the_same_product(): void
    {
        $product = $this->listedProduct('Popular')['product'];
        $first = User::factory()->create();
        $second = User::factory()->create();

        app(SaveToWishlist::class)((int) $first->id, (int) $product->id);
        app(SaveToWishlist::class)((int) $second->id, (int) $product->id);

        $this->assertSame(2, WishlistItem::query()->count());
    }

    #[Test]
    public function a_saved_entry_cannot_be_repointed_at_another_product(): void
    {
        $user = User::factory()->create();
        $kettle = $this->listedProduct('Kettle')['product'];
        $toaster = $this->listedProduct('Toaster')['product'];

        $item = app(SaveToWishlist::class)((int) $user->id, (int) $kettle->id);

        $item->product_id = (int) $toaster->id;

        $this->assertFalse($item->save(), 'A save has no editable fields.');
        $this->assertSame(
            (int) $kettle->id,
            (int) DB::table('wishlist_items')->where('id', $item->id)->value('product_id'),
        );
    }

    #[Test]
    public function an_unpublished_product_cannot_be_saved(): void
    {
        $user = User::factory()->create();
        $product = $this->listedProduct('Withdrawn')['product'];
        $product->update(['status' => ProductStatus::Draft->value]);

        $this->expectException(RuntimeException::class);

        app(SaveToWishlist::class)((int) $user->id, (int) $product->id);
    }

    // ---------------------------------------------------------------
    // Isolation.
    // ---------------------------------------------------------------

    #[Test]
    public function removing_only_touches_your_own_entry(): void
    {
        $product = $this->listedProduct('Shared')['product'];
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        app(SaveToWishlist::class)((int) $mine->id, (int) $product->id);
        app(SaveToWishlist::class)((int) $theirs->id, (int) $product->id);

        $this->assertTrue(app(RemoveFromWishlist::class)((int) $mine->id, (int) $product->id));

        $this->assertSame(1, WishlistItem::query()->count());
        $this->assertTrue(
            WishlistItem::query()->where('user_id', $theirs->id)->exists(),
            "Removing your own save must not touch anybody else's.",
        );
    }

    #[Test]
    public function removing_something_you_never_saved_is_not_an_error(): void
    {
        $user = User::factory()->create();
        $product = $this->listedProduct('Never saved')['product'];

        $this->assertFalse(app(RemoveFromWishlist::class)((int) $user->id, (int) $product->id));
    }

    #[Test]
    public function a_customer_only_ever_reads_their_own_list(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $kettle = $this->listedProduct('Kettle')['product'];
        $toaster = $this->listedProduct('Toaster')['product'];

        app(SaveToWishlist::class)((int) $mine->id, (int) $kettle->id);
        app(SaveToWishlist::class)((int) $theirs->id, (int) $toaster->id);

        $entries = app(GetWishlist::class)((int) $mine->id);

        $this->assertCount(1, $entries);
        $this->assertSame((int) $kettle->id, $entries[0]->productId);
    }

    // ---------------------------------------------------------------
    // The read surface.
    // ---------------------------------------------------------------

    #[Test]
    public function a_withdrawn_product_stays_on_the_list_marked_unavailable(): void
    {
        $user = User::factory()->create();
        $product = $this->listedProduct('Discontinued')['product'];

        app(SaveToWishlist::class)((int) $user->id, (int) $product->id);

        $product->update(['status' => ProductStatus::Archived->value]);
        $this->reindex($product);

        $entries = app(GetWishlist::class)((int) $user->id);

        $this->assertCount(1, $entries, 'A list that quietly shrinks is worse than one that says so.');
        $this->assertFalse($entries[0]->isAvailable);
        $this->assertSame('Discontinued', $entries[0]->title);
    }

    #[Test]
    public function the_list_is_newest_first(): void
    {
        $user = User::factory()->create();

        $first = $this->listedProduct('First')['product'];
        $second = $this->listedProduct('Second')['product'];
        $third = $this->listedProduct('Third')['product'];

        foreach ([$first, $second, $third] as $product) {
            app(SaveToWishlist::class)((int) $user->id, (int) $product->id);
        }

        $titles = array_map(
            static fn ($entry): string => $entry->title,
            app(GetWishlist::class)((int) $user->id),
        );

        $this->assertSame(['Third', 'Second', 'First'], $titles);
    }

    #[Test]
    public function membership_is_answered_for_a_whole_page_at_once(): void
    {
        $user = User::factory()->create();
        $saved = $this->listedProduct('Saved')['product'];
        $unsaved = $this->listedProduct('Unsaved')['product'];

        app(SaveToWishlist::class)((int) $user->id, (int) $saved->id);

        $query = app(GetWishlist::class);
        $ids = [(int) $saved->id, (int) $unsaved->id];

        $this->assertSame([(int) $saved->id], $query->savedAmong((int) $user->id, $ids));
        $this->assertSame([], $query->savedAmong(null, $ids), 'A signed-out visitor has saved nothing.');
        $this->assertTrue($query->has((int) $user->id, (int) $saved->id));
        $this->assertFalse($query->has((int) $user->id, (int) $unsaved->id));
        $this->assertSame(1, $query->count((int) $user->id));
        $this->assertSame(0, $query->count(null));
    }

    // ---------------------------------------------------------------
    // HTTP.
    // ---------------------------------------------------------------

    #[Test]
    public function a_signed_out_visitor_is_sent_to_sign_in(): void
    {
        $product = $this->listedProduct('Kettle')['product'];

        $this->get('/account/wishlist')->assertRedirect('/login');
        $this->post('/account/wishlist', ['product' => $product->public_id])->assertRedirect('/login');
        $this->delete('/account/wishlist', ['product' => $product->public_id])->assertRedirect('/login');

        $this->assertSame(0, WishlistItem::query()->count());
    }

    #[Test]
    public function saving_and_removing_over_http_records_the_behaviour(): void
    {
        $user = User::factory()->create();
        $product = $this->listedProduct('Kettle')['product'];

        $this->actingAs($user)
            ->from('/products/'.$product->slug)
            ->post('/account/wishlist', ['product' => $product->public_id])
            ->assertRedirect('/products/'.$product->slug);

        $this->assertSame(1, WishlistItem::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('interaction_events', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'event_type' => InteractionEventType::WishlistItemAdded->value,
        ]);

        $this->actingAs($user)
            ->from('/account/wishlist')
            ->delete('/account/wishlist', ['product' => $product->public_id])
            ->assertRedirect('/account/wishlist');

        $this->assertSame(0, WishlistItem::query()->count());
        $this->assertDatabaseHas('interaction_events', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'event_type' => InteractionEventType::WishlistItemRemoved->value,
        ]);
    }

    #[Test]
    public function a_guessed_product_identifier_saves_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/')
            ->post('/account/wishlist', ['product' => 'not-a-real-public-id'])
            ->assertSessionHasErrors('product');

        $this->assertSame(0, WishlistItem::query()->count());
    }

    #[Test]
    public function the_wishlist_page_carries_its_own_suggestions(): void
    {
        $user = User::factory()->create();
        $saved = $this->listedProduct('Saved')['product'];
        $other = $this->listedProduct('Other')['product'];

        app(SaveToWishlist::class)((int) $user->id, (int) $saved->id);

        $this->actingAs($user)
            ->get('/account/wishlist')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($saved, $other): void {
                $page->component('Account/Wishlist')
                    ->has('items', 1)
                    ->where('items.0.productId', (int) $saved->id);

                /** @var array<int, array<string, mixed>> $products */
                $products = $page->toArray()['props']['suggestions']['products'];
                $suggested = array_column($products, 'productId');

                $this->assertNotContains(
                    (int) $saved->id,
                    $suggested,
                    'A shelf must not suggest what the customer already saved.',
                );
                $this->assertContains((int) $other->id, $suggested);
            });
    }

    #[Test]
    public function the_wishlist_page_is_never_indexable(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/account/wishlist')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}

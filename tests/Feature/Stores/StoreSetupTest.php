<?php

declare(strict_types=1);

namespace Tests\Feature\Stores;

use App\Modules\Media\Actions\StoreUploadedImage;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Store setup, its public address, and what the storefront will show.
 */
final class StoreSetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Aeris Kitchen Co.',
            'slug' => 'aeris-kitchen',
            'description' => 'Cast iron and carbon steel, made to be handed down.',
            'support_email' => 'help@aeris.example',
            'shipping_policy' => 'Orders before 2pm PT ship the same day.',
            'return_policy' => 'Unused items accepted within 30 days.',
            'is_open' => true,
        ], $overrides);
    }

    #[Test]
    public function an_owner_can_create_and_name_their_store(): void
    {
        ['seller' => $seller, 'store' => $store, 'user' => $owner] = $this->makeSeller();

        $this->actingAs($owner, 'web')
            ->post('/seller/store', $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'seller_account_id' => $seller->id,
            'slug' => 'aeris-kitchen',
        ]);
    }

    #[Test]
    public function the_public_address_never_contains_a_database_id(): void
    {
        ['store' => $store, 'user' => $owner] = $this->makeSeller();

        $this->actingAs($owner, 'web')->post('/seller/store', $this->payload());

        $slug = (string) $store->fresh()?->slug;

        $this->assertSame('aeris-kitchen', $slug);
        $this->assertDoesNotMatchRegularExpression('/\d/', $slug);
        $this->get('/stores/'.$slug)->assertOk();
    }

    #[Test]
    public function a_slug_already_taken_by_another_store_is_refused(): void
    {
        Store::factory()->create(['slug' => 'taken-address']);
        ['user' => $owner] = $this->makeSeller();

        $this->actingAs($owner, 'web')
            ->post('/seller/store', $this->payload(['slug' => 'taken-address']))
            ->assertSessionHasErrors('slug');
    }

    #[Test]
    public function a_reserved_word_cannot_be_claimed_as_a_store_address(): void
    {
        ['user' => $owner] = $this->makeSeller();

        foreach (['admin', 'checkout', 'seller'] as $reserved) {
            $this->actingAs($owner, 'web')
                ->post('/seller/store', $this->payload(['slug' => $reserved]))
                ->assertSessionHasErrors('slug');
        }
    }

    #[Test]
    public function a_slug_is_normalised_rather_than_bounced_back(): void
    {
        ['store' => $store, 'user' => $owner] = $this->makeSeller();

        // Someone typing their shop name into the address field should get
        // a working address, not a lecture about hyphens.
        foreach ([
            'Aeris Kitchen Co.' => 'aeris-kitchen-co',
            'aeris_kitchen' => 'aeris-kitchen',
            'aeris--kitchen' => 'aeris-kitchen',
            '-aeris-' => 'aeris',
            // Over-long addresses are cut to the limit, not rejected.
            'aeris-kitchen-company-of-portland-oregon-usa' => 'aeris-kitchen-company-of-portland-oregon',
        ] as $typed => $expected) {
            $this->actingAs($owner, 'web')
                ->post('/seller/store', $this->payload(['slug' => $typed]))
                ->assertSessionHasNoErrors();

            $this->assertSame($expected, $store->fresh()?->slug, "'{$typed}' normalised wrongly");
        }
    }

    #[Test]
    public function a_slug_normalisation_cannot_rescue_is_refused(): void
    {
        ['store' => $store, 'user' => $owner] = $this->makeSeller();
        $before = $store->slug;

        foreach (['!!!', '   ', 'ab'] as $bad) {
            $this->actingAs($owner, 'web')
                ->post('/seller/store', $this->payload(['slug' => $bad]))
                ->assertSessionHasErrors(['slug']);
        }

        $this->assertSame($before, $store->fresh()?->slug);
    }

    #[Test]
    public function renaming_a_store_keeps_the_old_address_working(): void
    {
        ['store' => $store, 'user' => $owner] = $this->makeSeller();

        $this->actingAs($owner, 'web')->post('/seller/store', $this->payload(['slug' => 'first-address']));
        $this->actingAs($owner, 'web')->post('/seller/store', $this->payload(['slug' => 'second-address']));

        $this->assertDatabaseHas('store_slug_history', [
            'store_id' => $store->id,
            'old_slug' => 'first-address',
        ]);

        // Permanently, and to the current address — search equity moves
        // with the seller rather than dying with the rename.
        $this->get('/stores/first-address')->assertRedirect('/stores/second-address');
        $this->get('/stores/second-address')->assertOk();
    }

    #[Test]
    public function another_stores_retired_address_cannot_be_claimed(): void
    {
        $other = Store::factory()->create(['slug' => 'moved-on']);
        DB::table('store_slug_history')->insert([
            'store_id' => $other->id,
            'old_slug' => 'the-old-name',
            'changed_at' => now(),
        ]);

        ['user' => $owner] = $this->makeSeller();

        $this->actingAs($owner, 'web')
            ->post('/seller/store', $this->payload(['slug' => 'the-old-name']))
            ->assertSessionHasErrors('slug');
    }

    #[Test]
    public function a_role_without_store_manage_cannot_change_the_store(): void
    {
        ['store' => $store, 'user' => $catalogManager] = $this->makeSeller(SellerRole::CatalogManager);

        $this->actingAs($catalogManager, 'web')
            ->post('/seller/store', $this->payload())
            ->assertForbidden();

        $this->assertNotSame('aeris-kitchen', $store->fresh()?->slug);
    }

    #[Test]
    public function an_uploaded_logo_is_stored_under_a_generated_path(): void
    {
        Storage::fake(config('veritas.media.disk'));

        ['store' => $store, 'user' => $owner] = $this->makeSeller();

        $this->actingAs($owner, 'web')->post('/seller/store', $this->payload([
            'logo' => UploadedFile::fake()->image('../../etc/passwd.jpg', 400, 400),
        ]))->assertRedirect();

        $stored = (string) $store->fresh()?->logo_media_id;

        $this->assertNotSame('', $stored);
        // Nothing of the uploader's filename survives into the path.
        $this->assertStringNotContainsString('passwd', $stored);
        $this->assertStringNotContainsString('..', $stored);
        $this->assertMatchesRegularExpression('#:stores/\d+/logo/[0-9A-Z]{26}\.(jpg|png|webp)$#', $stored);
    }

    #[Test]
    public function a_renamed_executable_is_not_accepted_as_an_image(): void
    {
        Storage::fake(config('veritas.media.disk'));

        $path = tempnam(sys_get_temp_dir(), 'vc').'.jpg';
        file_put_contents($path, "#!/bin/sh\necho pwned\n");

        // The extension says jpg and the browser would say image/jpeg; the
        // bytes say otherwise, and the bytes are what is trusted.
        $file = new UploadedFile($path, 'logo.jpg', 'image/jpeg', null, true);

        $this->expectException(RuntimeException::class);

        try {
            app(StoreUploadedImage::class)->put($file, 'stores/1/logo');
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function a_suspended_sellers_store_does_not_resolve_publicly(): void
    {
        ['store' => $store] = $this->makeSeller(sellerAttributes: [
            'status' => SellerStatus::Suspended->value,
            'suspended_at' => now(),
            'suspension_reason' => 'Under investigation',
        ]);

        // 404, not an empty shell: an indexable page for a store that
        // cannot trade is worse than no page at all.
        $this->get('/stores/'.$store->slug)->assertNotFound();
    }

    #[Test]
    public function an_unapproved_sellers_store_does_not_resolve_publicly(): void
    {
        ['store' => $store] = $this->makeSeller(sellerAttributes: [
            'status' => SellerStatus::Pending->value,
            'approved_at' => null,
        ]);

        $this->get('/stores/'.$store->slug)->assertNotFound();
    }

    #[Test]
    public function a_temporarily_closed_store_keeps_its_page_but_is_not_indexed(): void
    {
        ['store' => $store, 'user' => $owner] = $this->makeSeller();

        $this->actingAs($owner, 'web')->post('/seller/store', $this->payload(['is_open' => false]));

        // The seller closing for a fortnight must not lose their URL, and
        // a temporary closure must not be what search engines have on file.
        $this->get('/stores/'.$store->fresh()?->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('store.isOpen', false)
                ->where('seo.robots', 'noindex, follow'));
    }

    #[Test]
    public function the_public_store_page_carries_its_seo_identity_and_no_invented_products(): void
    {
        ['store' => $store] = $this->makeSeller();

        $this->get('/stores/'.$store->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Store/Show')
                ->where('store.name', $store->name)
                ->has('seo.title')
                ->has('seo.description')
                ->where('seo.canonical', fn (string $url) => str_ends_with($url, '/stores/'.$store->slug))
                ->where('seo.robots', 'index, follow')
                // The catalogue arrives in M2. Until then there is no
                // product list to render, invented or otherwise.
                ->missing('products'));
    }
}

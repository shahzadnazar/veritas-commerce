<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Offer> */
final class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        return [
            'seller_account_id' => SellerAccount::factory(),
            'store_id' => Store::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'seller_sku' => strtoupper($this->faker->unique()->bothify('SKU-####-???')),
            'condition' => 'new',
            'price_minor' => $this->faker->numberBetween(1_000, 200_000),
            'currency' => 'USD',
            'status' => OfferStatus::Published->value,
            'published_at' => now(),
            'handling_days' => 2,
        ];
    }

    /** Attach the offer to an existing seller and their store. */
    public function forSeller(SellerAccount $seller, ?Store $store = null): self
    {
        $store ??= $seller->store ?? Store::factory()->create(['seller_account_id' => $seller->id]);

        return $this->state(fn (): array => [
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
        ]);
    }

    public function priced(int $minor): self
    {
        return $this->state(fn (): array => ['price_minor' => $minor]);
    }

    public function draft(): self
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::Draft->value,
            'published_at' => null,
        ]);
    }
}

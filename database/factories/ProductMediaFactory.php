<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductMedia> */
final class ProductMediaFactory extends Factory
{
    protected $model = ProductMedia::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'disk' => (string) config('veritas.storage.public_disk'),
            'path' => 'products/'.$this->faker->numberBetween(1, 999).'/images/'.Str::lower((string) Str::ulid()).'.jpg',
            'mime' => 'image/jpeg',
            'bytes' => $this->faker->numberBetween(20_000, 900_000),
            'width' => 1200,
            'height' => 1200,
            'alt_text' => $this->faker->sentence(4),
            'position' => 0,
            'is_primary' => false,
            'processing_state' => ProductMedia::STATE_READY,
            'processed_at' => now(),
        ];
    }

    public function primary(): self
    {
        return $this->state(fn (): array => ['is_primary' => true, 'position' => 0]);
    }
}

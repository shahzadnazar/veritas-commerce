<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CatalogueText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $title = Str::title(implode(' ', array_map(fn (): string => $this->faker->unique()->word(), range(1, 3))));

        return [
            'category_id' => Category::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'description' => $this->faker->paragraph(),
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
            'normalised_title' => CatalogueText::normalise($title),
        ];
    }

    public function status(ProductStatus $status): self
    {
        return $this->state(fn (): array => [
            'status' => $status->value,
            'published_at' => $status === ProductStatus::Published ? now() : null,
        ]);
    }

    public function proposedBy(int $sellerAccountId): self
    {
        return $this->state(fn (): array => [
            'created_by_seller_account_id' => $sellerAccountId,
            'status' => ProductStatus::PendingReview->value,
            'submitted_at' => now(),
            'published_at' => null,
        ]);
    }
}

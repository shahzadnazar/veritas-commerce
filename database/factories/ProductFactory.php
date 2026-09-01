<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
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
            'is_active' => true,
        ];
    }
}

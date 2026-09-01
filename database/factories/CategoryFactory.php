<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Category> */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = implode(' ', array_map(fn (): string => $this->faker->unique()->word(), range(1, 2)));

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'is_visible' => true,
            'position' => 0,
        ];
    }
}

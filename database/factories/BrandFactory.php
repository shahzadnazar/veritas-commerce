<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Support\CatalogueText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Brand> */
final class BrandFactory extends Factory
{
    protected $model = Brand::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'normalised_name' => CatalogueText::normalise($name),
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'is_active' => true,
            'approved_at' => now(),
        ];
    }
}

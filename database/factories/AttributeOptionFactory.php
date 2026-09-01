<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\AttributeOption;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AttributeOption> */
final class AttributeOptionFactory extends Factory
{
    protected $model = AttributeOption::class;

    public function definition(): array
    {
        $label = Str::title($this->faker->unique()->word());

        return [
            'attribute_id' => Attribute::factory(),
            'value' => Str::slug($label),
            'label' => $label,
            'position' => 0,
        ];
    }
}

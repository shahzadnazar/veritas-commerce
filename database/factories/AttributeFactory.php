<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\AttributeType;
use App\Modules\Catalog\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Attribute> */
final class AttributeFactory extends Factory
{
    protected $model = Attribute::class;

    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->word());

        return [
            'code' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'name' => $name,
            'data_type' => AttributeType::Text->value,
            'is_filterable' => false,
            'is_searchable' => false,
            'is_variant_defining' => false,
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function ofType(AttributeType $type): self
    {
        return $this->state(fn (): array => ['data_type' => $type->value]);
    }

    public function filterable(): self
    {
        return $this->state(fn (): array => ['is_filterable' => true]);
    }

    public function variantDefining(): self
    {
        return $this->state(fn (): array => [
            'data_type' => AttributeType::Select->value,
            'is_variant_defining' => true,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductAttributeValue> */
final class ProductAttributeValueFactory extends Factory
{
    protected $model = ProductAttributeValue::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'attribute_id' => Attribute::factory(),
            'value_text' => $this->faker->word(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Store> */
final class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'seller_account_id' => SellerAccount::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'description' => $this->faker->sentence(12),
            'support_email' => $this->faker->safeEmail(),
            'shipping_policy' => 'Orders placed before 2pm PT ship the same day.',
            'return_policy' => 'Unused items accepted within 30 days.',
            'is_open' => true,
        ];
    }
}

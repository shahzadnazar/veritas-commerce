<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketplaceOrder> */
final class MarketplaceOrderFactory extends Factory
{
    protected $model = MarketplaceOrder::class;

    public function definition(): array
    {
        return [
            'reference' => 'VC-'.$this->faker->unique()->numberBetween(10_000, 99_999),
            'email' => $this->faker->safeEmail(),
            'status' => MarketplaceOrderStatus::Paid->value,
            'currency' => 'USD',
            'ship_name' => $this->faker->name(),
            'ship_line1' => $this->faker->streetAddress(),
            'ship_city' => $this->faker->city(),
            'ship_state' => 'OR',
            'ship_postcode' => $this->faker->postcode(),
            'ship_country' => 'US',
            'placed_at' => now(),
        ];
    }
}

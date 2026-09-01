<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\CustomerAddress;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAddress> */
final class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Home',
            'name' => $this->faker->name(),
            'line1' => $this->faker->streetAddress(),
            'line2' => null,
            'city' => $this->faker->city(),
            'state' => 'OR',
            'postcode' => $this->faker->postcode(),
            'country' => 'US',
            'phone' => null,
            'is_default' => false,
        ];
    }

    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }
}

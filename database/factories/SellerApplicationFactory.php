<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Models\SellerApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SellerApplication> */
final class SellerApplicationFactory extends Factory
{
    protected $model = SellerApplication::class;

    public function definition(): array
    {
        return [
            'reference' => 'APP-'.$this->faker->unique()->numberBetween(1000, 9999),
            'user_id' => User::factory(),
            'legal_name' => $this->faker->company().' LLC',
            'trading_name' => $this->faker->company(),
            'business_type' => 'LLC',
            'tax_id' => $this->faker->numerify('##-#######'),
            'address_line1' => $this->faker->streetAddress(),
            'address_city' => $this->faker->city(),
            'address_state' => 'OR',
            'address_postcode' => $this->faker->postcode(),
            'contact_name' => $this->faker->name(),
            'contact_email' => $this->faker->safeEmail(),
            'status' => SellerApplicationStatus::Submitted->value,
            'terms_accepted_at' => now(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SellerAccount> */
final class SellerAccountFactory extends Factory
{
    protected $model = SellerAccount::class;

    public function definition(): array
    {
        return [
            'legal_name' => $this->faker->company().' LLC',
            'business_type' => 'LLC',
            'tax_id' => $this->faker->numerify('##-#######'),
            'status' => SellerStatus::Approved->value,
            'approved_at' => now(),
            'ships_from_city' => $this->faker->city(),
            'ships_from_state' => 'OR',
        ];
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => [
            'status' => SellerStatus::Suspended->value,
            'suspended_at' => now(),
            'suspension_reason' => 'Test suspension',
        ]);
    }

    public function withClearingPeriod(int $days): self
    {
        return $this->state(fn (): array => ['clearing_period_days' => $days]);
    }
}

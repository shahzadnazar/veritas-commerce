<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Payouts\Enums\PayoutAccountType;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayoutAccount> */
final class PayoutAccountFactory extends Factory
{
    protected $model = PayoutAccount::class;

    public function definition(): array
    {
        return [
            'seller_account_id' => SellerAccount::factory(),
            'type' => PayoutAccountType::Manual->value,
            'provider' => 'manual',
            'display_label' => 'Business account',
            'last4' => (string) $this->faker->numberBetween(1000, 9999),
            'country' => 'US',
            'currency' => 'USD',
            'status' => PayoutAccount::STATUS_ACTIVE,
        ];
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['status' => PayoutAccount::STATUS_DISABLED]);
    }

    public function verified(): self
    {
        return $this->state(fn (): array => ['verified_at' => now()]);
    }
}

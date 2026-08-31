<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayoutRequest> */
final class PayoutRequestFactory extends Factory
{
    protected $model = PayoutRequest::class;

    public function definition(): array
    {
        return [
            'reference' => 'PO-'.$this->faker->unique()->numberBetween(1000, 9999),
            'seller_account_id' => SellerAccount::factory(),
            'currency' => 'USD',
            'amount_minor' => 10_000,
            'status' => PayoutStatus::Requested->value,
            'requested_at' => now(),
        ];
    }
}

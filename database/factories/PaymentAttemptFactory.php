<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentAttempt> */
final class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    public function definition(): array
    {
        return [
            'marketplace_order_id' => MarketplaceOrder::factory(),
            'provider' => 'fake',
            'currency' => 'USD',
            'amount_minor' => 10_000,
            'status' => PaymentStatus::Pending->value,
            'created_at' => now(),
        ];
    }
}

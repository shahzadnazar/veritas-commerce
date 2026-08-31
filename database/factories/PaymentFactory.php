<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'marketplace_order_id' => MarketplaceOrder::factory(),
            'provider' => 'fake',
            'provider_charge_id' => 'fake_ch_'.$this->faker->unique()->uuid(),
            'currency' => 'USD',
            'amount_minor' => 10_000,
            'status' => PaymentStatus::Captured->value,
            'captured_at' => now(),
        ];
    }
}

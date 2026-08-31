<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Models\OrderStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderStatusHistory> */
final class OrderStatusHistoryFactory extends Factory
{
    protected $model = OrderStatusHistory::class;

    public function definition(): array
    {
        return [
            'to_status' => 'paid',
            'actor_type' => 'system',
            'created_at' => now(),
        ];
    }
}

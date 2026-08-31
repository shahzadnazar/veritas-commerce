<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SellerApplicationEvent> */
final class SellerApplicationEventFactory extends Factory
{
    protected $model = SellerApplicationEvent::class;

    public function definition(): array
    {
        return [
            'seller_application_id' => SellerApplication::factory(),
            'to_status' => 'submitted',
            'actor_type' => 'customer',
            'created_at' => now(),
        ];
    }
}

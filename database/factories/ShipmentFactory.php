<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Shipment> */
final class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'seller_order_id' => SellerOrder::factory(),
            'carrier' => 'USPS Ground Advantage',
            'tracking_number' => $this->faker->numerify('94001000000#########'),
            'shipped_at' => now(),
        ];
    }
}

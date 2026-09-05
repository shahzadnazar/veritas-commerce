<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Shipment> */
final class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'seller_order_id' => SellerOrder::factory(),
            'reference' => 'VC-'.$this->faker->unique()->numberBetween(10_000, 99_999).'-01-S01',
            'sequence' => 1,
            // A parcel starts as a draft. Anything further is a state a
            // domain action put it in, so the factory does not presume it.
            'status' => ShipmentStatus::Draft->value,
            'created_by_type' => 'seller',
            'public_id' => (string) Str::ulid(),
        ];
    }

    public function shipped(): self
    {
        return $this->state(fn (): array => [
            'status' => ShipmentStatus::Shipped->value,
            'carrier_name' => 'USPS Ground Advantage',
            'carrier_code' => 'usps',
            'tracking_number' => $this->faker->numerify('94001000000#########'),
            'packed_at' => now()->subHour(),
            'shipped_at' => now(),
        ]);
    }

    public function delivered(): self
    {
        return $this->shipped()->state(fn (): array => [
            'status' => ShipmentStatus::Delivered->value,
            'delivered_at' => now(),
        ]);
    }
}

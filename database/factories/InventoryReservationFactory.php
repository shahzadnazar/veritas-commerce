<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryReservation> */
final class InventoryReservationFactory extends Factory
{
    protected $model = InventoryReservation::class;

    public function definition(): array
    {
        return [
            'quantity' => 1,
            'status' => ReservationStatus::Held->value,
            'expires_at' => now()->addMinutes(20),
        ];
    }
}

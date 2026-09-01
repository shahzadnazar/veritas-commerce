<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryMovement> */
final class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    public function definition(): array
    {
        return [
            'on_hand_change' => 1,
            'reserved_change' => 0,
            'resulting_on_hand' => 1,
            'resulting_reserved' => 0,
            'reason' => InventoryMovementReason::OpeningStock->value,
            'actor_type' => 'system',
            'created_at' => now(),
        ];
    }
}

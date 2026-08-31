<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\InventoryBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryBalance> */
final class InventoryBalanceFactory extends Factory
{
    protected $model = InventoryBalance::class;

    public function definition(): array
    {
        return ['on_hand' => 10];
    }
}

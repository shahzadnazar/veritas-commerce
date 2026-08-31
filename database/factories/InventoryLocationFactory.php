<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryLocation> */
final class InventoryLocationFactory extends Factory
{
    protected $model = InventoryLocation::class;

    public function definition(): array
    {
        return [
            'seller_account_id' => SellerAccount::factory(),
            'name' => 'Default',
            'is_default' => true,
        ];
    }
}

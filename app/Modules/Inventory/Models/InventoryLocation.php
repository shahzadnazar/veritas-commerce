<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Sellers\Concerns\BelongsToSellerAccount;
use Database\Factories\InventoryLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One default location per seller in Phase 1.
 *
 * The table exists now so that multiple warehouses later are a data change
 * rather than a migration of every stock row in the marketplace.
 */
final class InventoryLocation extends Model
{
    /** @use HasFactory<InventoryLocationFactory> */
    use BelongsToSellerAccount;

    use HasFactory;

    protected $table = 'inventory_locations';

    protected $fillable = ['seller_account_id', 'name', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}

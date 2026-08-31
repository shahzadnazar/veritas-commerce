<?php

declare(strict_types=1);

namespace App\Modules\Stores\Models;

use App\Modules\Sellers\Concerns\BelongsToSellerAccount;
use App\Modules\Sellers\Models\SellerAccount;
use App\Support\HasPublicId;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use BelongsToSellerAccount;

    use HasFactory;
    use HasPublicId;

    protected $table = 'stores';

    protected $fillable = [
        'seller_account_id',
        'name',
        'slug',
        'description',
        'support_email',
        'support_phone',
        'shipping_policy',
        'return_policy',
        'is_open',
    ];

    protected function casts(): array
    {
        return ['is_open' => 'boolean'];
    }

    /** @return BelongsTo<SellerAccount, $this> */
    public function sellerAccount(): BelongsTo
    {
        return $this->belongsTo(SellerAccount::class);
    }

    /** Slugs a store used before; each one 301s to the current URL forever. */
    public const RESERVED_SLUGS = [
        'admin', 'api', 'seller', 'sellers', 'checkout', 'cart', 'search',
        'store', 'stores', 'account', 'orders', 'login', 'register', 'c', 'p',
    ];
}

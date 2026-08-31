<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerRole;
use Database\Factories\SellerMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which user may act for which seller, and in what role. */
final class SellerMembership extends Model
{
    /** @use HasFactory<SellerMembershipFactory> */
    use HasFactory;

    protected $table = 'seller_memberships';

    protected $fillable = ['seller_account_id', 'user_id', 'role', 'invited_at', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'role' => SellerRole::class,
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SellerAccount, $this> */
    public function sellerAccount(): BelongsTo
    {
        return $this->belongsTo(SellerAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

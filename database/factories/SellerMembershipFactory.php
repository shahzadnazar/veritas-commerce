<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SellerMembership> */
final class SellerMembershipFactory extends Factory
{
    protected $model = SellerMembership::class;

    public function definition(): array
    {
        return [
            'seller_account_id' => SellerAccount::factory(),
            'user_id' => User::factory(),
            'role' => SellerRole::Owner->value,
            'accepted_at' => now(),
        ];
    }
}

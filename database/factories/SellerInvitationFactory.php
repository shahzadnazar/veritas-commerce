<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sellers\Enums\InvitationStatus;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<SellerInvitation> */
final class SellerInvitationFactory extends Factory
{
    protected $model = SellerInvitation::class;

    public function definition(): array
    {
        return [
            'seller_account_id' => SellerAccount::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'role' => SellerRole::CatalogManager->value,
            'token_hash' => Hash::make('test-token'),
            'status' => InvitationStatus::Pending->value,
            'expires_at' => now()->addDays(14),
        ];
    }
}

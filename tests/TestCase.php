<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerMembership;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a fully-formed seller: account, store, owner user and membership.
     *
     * @param  array<string, mixed>  $sellerAttributes
     * @return array{seller: SellerAccount, store: Store, user: User, membership: SellerMembership}
     */
    protected function makeSeller(
        SellerRole $role = SellerRole::Owner,
        array $sellerAttributes = [],
    ): array {
        $seller = SellerAccount::factory()->create($sellerAttributes);
        $store = Store::factory()->create(['seller_account_id' => $seller->id]);
        $user = User::factory()->create();

        $membership = SellerMembership::factory()->create([
            'seller_account_id' => $seller->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);

        return ['seller' => $seller, 'store' => $store, 'user' => $user, 'membership' => $membership];
    }

    /**
     * An admin who has already cleared the second factor, so a test about
     * authorisation is not also a test about enrolment.
     */
    protected function makeAdmin(AdminRole $role = AdminRole::SuperAdmin): AdminUser
    {
        return AdminUser::factory()->role($role)->withTwoFactor()->create();
    }

    /**
     * Sign in as a customer or seller, naming the guard.
     *
     * actingAs() with no guard writes to whichever guard is currently the
     * default — so a test that has already acted as an admin would put a
     * customer into the admin guard. Both realms are always named here for
     * the same reason the application never calls $request->user() bare.
     */
    protected function asUser(User $user): static
    {
        return $this->actingAs($user, 'web');
    }

    protected function asAdmin(AdminUser $admin): static
    {
        return $this->actingAs($admin, 'admin');
    }
}

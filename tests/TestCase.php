<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerMembership;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a fully-formed seller: account, store, owner user and membership.
     *
     * @return array{seller: SellerAccount, store: Store, user: User}
     */
    protected function makeSeller(): array
    {
        $seller = SellerAccount::factory()->create();
        $store = Store::factory()->create(['seller_account_id' => $seller->id]);
        $user = User::factory()->create();

        SellerMembership::factory()->create([
            'seller_account_id' => $seller->id,
            'user_id' => $user->id,
        ]);

        return ['seller' => $seller, 'store' => $store, 'user' => $user];
    }
}

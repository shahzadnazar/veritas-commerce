<?php

declare(strict_types=1);

/*
 * Fixtures for the CI Docker commerce smoke.
 *
 * Built through the same models and actions the application uses — stock
 * arrives through the ledger, memberships through the factory — so the
 * smoke cannot pass against a state the real system could not produce.
 *
 * Printed as key=value lines the workflow reads from a file rather than a
 * pipe: piping discards the exit status, and a seeding failure would then
 * surface as a confusing curl error three steps later.
 */

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerMembership;
use App\Modules\Stores\Models\Store;
use Illuminate\Support\Facades\Hash;

$makeUser = static function (string $email, string $first): User {
    $user = User::query()->firstOrCreate($email !== '' ? ['email' => $email] : [], [
        'password' => Hash::make('m4-smoke-password'),
        'first_name' => $first,
        'last_name' => 'Smoke',
    ]);

    $user->forceFill(['email_verified_at' => now()])->save();

    return $user;
};

$makeUser('m4-customer@veritas.test', 'Customer');
$sellerUser = $makeUser('m4-seller@veritas.test', 'Seller');
$otherUser = $makeUser('m4-other-seller@veritas.test', 'Other');
$makeUser('m4-stranger@veritas.test', 'Stranger');

$seller = SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
$store = Store::factory()->create([
    'seller_account_id' => $seller->id,
    'is_open' => true,
    'name' => 'M4 Smoke Store',
]);
SellerMembership::factory()->create([
    'seller_account_id' => $seller->id,
    'user_id' => $sellerUser->id,
    'role' => SellerRole::Owner->value,
]);

// A second seller, so cross-tenant denial has something real to deny.
$other = SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
Store::factory()->create(['seller_account_id' => $other->id, 'is_open' => true, 'name' => 'M4 Other Store']);
SellerMembership::factory()->create([
    'seller_account_id' => $other->id,
    'user_id' => $otherUser->id,
    'role' => SellerRole::Owner->value,
]);

$product = Product::factory()->create([
    'title' => 'M4 Smoke Kettle',
    'status' => ProductStatus::Published->value,
    'published_at' => now(),
]);

$offer = Offer::factory()->create([
    'seller_account_id' => $seller->id,
    'store_id' => $store->id,
    'product_id' => $product->id,
    'product_variant_id' => null,
    'price_minor' => 4_500,
    'status' => OfferStatus::Published->value,
]);

$location = InventoryLocation::query()->firstOrCreate(
    ['seller_account_id' => $seller->id, 'is_default' => true],
    ['name' => 'Default'],
);

InventoryBalance::query()->firstOrCreate(
    ['offer_id' => $offer->id, 'inventory_location_id' => $location->id],
    ['on_hand' => 0],
);

app(AdjustInventory::class)->openingStock($offer, 25, 'seller', $sellerUser->id);

echo 'offer='.$offer->public_id, PHP_EOL;
echo 'product='.$product->slug, PHP_EOL;
echo 'store='.$store->slug, PHP_EOL;

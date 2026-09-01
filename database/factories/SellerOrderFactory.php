<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SellerOrder> */
final class SellerOrderFactory extends Factory
{
    protected $model = SellerOrder::class;

    public function definition(): array
    {
        return [
            'marketplace_order_id' => MarketplaceOrder::factory(),
            'seller_account_id' => SellerAccount::factory(),
            'store_id' => Store::factory(),
            'position' => 1,
            'reference' => function (array $attributes): string {
                $order = MarketplaceOrder::query()->whereKey($attributes['marketplace_order_id'])->firstOrFail();

                return Reference::subOrder($order->reference, $attributes['position']);
            },
            'status' => SellerOrderStatus::Paid->value,
            'currency' => 'USD',
        ];
    }
}

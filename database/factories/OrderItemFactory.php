<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commission\Enums\CommissionScope;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
final class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $unit = 10_000;
        $quantity = 1;
        $lineTotal = $unit * $quantity;
        $commission = (int) round($lineTotal * 0.12);

        return [
            'seller_order_id' => SellerOrder::factory(),
            'product_title' => $this->faker->words(3, true),
            'seller_sku' => strtoupper($this->faker->bothify('SKU-####')),
            'currency' => 'USD',
            'unit_price_snapshot_minor' => $unit,
            'quantity' => $quantity,
            'line_total_minor' => $lineTotal,
            'commission_rate_snapshot' => '12.00',
            'commission_scope_snapshot' => CommissionScope::Global->value,
            'commission_amount_minor' => $commission,
            'seller_earning_amount_minor' => $lineTotal - $commission,
            'snapshotted_at' => now(),
        ];
    }
}

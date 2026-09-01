<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commission\Enums\CommissionScope;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
final class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'seller_order_id' => SellerOrder::factory(),
            'product_title' => $this->faker->words(3, true),
            'seller_sku' => strtoupper($this->faker->bothify('SKU-####')),
            'currency' => 'USD',
            'unit_price_snapshot_minor' => 10_000,
            'quantity' => 1,
            'line_total_minor' => 10_000,
            'commission_rate_snapshot' => '12.00',
            'commission_scope_snapshot' => CommissionScope::Global->value,
            'snapshotted_at' => now(),
        ];
    }

    /**
     * The commission split is derived from whatever line total the caller
     * ended up with, never from the factory's default — a test that sets
     * its own price must still satisfy `commission + earning = line_total`,
     * which the database checks.
     */
    public function configure(): self
    {
        return $this->afterMaking(function (OrderItem $item): void {
            [$commission, $earning] = Money::of(
                (int) $item->line_total_minor,
                (string) $item->currency,
            )->splitPercentage((string) $item->commission_rate_snapshot);

            $item->commission_amount_minor = $commission->minor;
            $item->seller_earning_amount_minor = $earning->minor;
        });
    }
}

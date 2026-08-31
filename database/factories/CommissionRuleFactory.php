<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commission\Enums\CommissionScope;
use App\Modules\Commission\Models\CommissionRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommissionRule> */
final class CommissionRuleFactory extends Factory
{
    protected $model = CommissionRule::class;

    public function definition(): array
    {
        return [
            'scope' => CommissionScope::Global->value,
            'rate_percent' => '12.00',
            'effective_from' => now()->subYear(),
            'created_at' => now()->subYear(),
        ];
    }

    public function rate(string $percent): self
    {
        return $this->state(fn (): array => ['rate_percent' => $percent]);
    }

    public function scoped(CommissionScope $scope, ?int $sellerAccountId = null, ?int $categoryId = null): self
    {
        return $this->state(fn (): array => [
            'scope' => $scope->value,
            'seller_account_id' => $sellerAccountId,
            'category_id' => $categoryId,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Commission\Enums\CommissionScope;
use App\Modules\Commission\Models\CommissionRule;
use Illuminate\Database\Seeder;

/**
 * The platform default commission must exist before any order can be
 * snapshotted — ResolveCommissionRule throws rather than guessing a rate.
 */
final class CommissionSeeder extends Seeder
{
    public function run(): void
    {
        if (CommissionRule::query()->where('scope', CommissionScope::Global->value)->exists()) {
            return;
        }

        CommissionRule::create([
            'scope' => CommissionScope::Global->value,
            'rate_percent' => config('veritas.commission.default_rate_percent'),
            'effective_from' => now()->subYear(),
            'note' => 'Platform default, seeded at install.',
            'created_at' => now(),
        ]);
    }
}

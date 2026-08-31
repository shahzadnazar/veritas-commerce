<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SellerLedgerEntry> */
final class SellerLedgerEntryFactory extends Factory
{
    protected $model = SellerLedgerEntry::class;

    public function definition(): array
    {
        return [
            'seller_account_id' => SellerAccount::factory(),
            'type' => LedgerEntryType::SaleEarning->value,
            'status' => LedgerEntryStatus::Available->value,
            'currency' => 'USD',
            'amount_minor' => 10_000,
            'balance_after_minor' => 10_000,
            'available_at' => now()->subDay(),
            'created_at' => now(),
        ];
    }
}

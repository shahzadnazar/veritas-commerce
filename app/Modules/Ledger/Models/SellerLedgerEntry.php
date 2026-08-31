<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Sellers\Concerns\BelongsToSellerAccount;
use App\Support\HasPublicId;
use App\Support\Money;
use Database\Factories\SellerLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * APPEND ONLY, with one deliberate exception.
 *
 * The amount, type and running balance of an entry are permanent: a mistake
 * is corrected by an Adjustment that references the original, never by
 * editing a row. What may change is `status`, as an entry moves
 * clearing -> available -> reserved -> paid; that is the entry's lifecycle,
 * not a rewrite of what happened.
 */
final class SellerLedgerEntry extends Model
{
    /** @use HasFactory<SellerLedgerEntryFactory> */
    use BelongsToSellerAccount;

    use HasFactory;
    use HasPublicId;

    /** Columns that record what happened and may never change. */
    public const IMMUTABLE_COLUMNS = [
        'seller_account_id', 'type', 'currency', 'amount_minor',
        'balance_after_minor', 'seller_order_id', 'order_item_id',
        'reverses_entry_id', 'created_at',
    ];

    protected $table = 'seller_ledger_entries';

    public $timestamps = false;

    protected $fillable = [
        'seller_account_id', 'type', 'status', 'currency', 'amount_minor',
        'balance_after_minor', 'seller_order_id', 'order_item_id',
        'payout_request_id', 'reverses_entry_id', 'available_at', 'note', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'status' => LedgerEntryStatus::class,
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'available_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $entry): void {
            foreach (self::IMMUTABLE_COLUMNS as $column) {
                if ($entry->isDirty($column)) {
                    throw new RuntimeException(
                        "seller_ledger_entries.{$column} is immutable. ".
                        'Post an adjustment or reversal entry instead.'
                    );
                }
            }
        });

        self::deleting(function (): never {
            throw new RuntimeException('seller_ledger_entries is append-only and cannot be deleted.');
        });
    }

    public function amount(): Money
    {
        return Money::of(abs($this->amount_minor), $this->currency);
    }

    public function isCredit(): bool
    {
        return $this->amount_minor > 0;
    }
}

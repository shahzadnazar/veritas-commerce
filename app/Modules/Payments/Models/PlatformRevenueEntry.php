<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Support\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The platform's own side of the commission split. APPEND ONLY.
 *
 * The seller's earning has had a ledger since M0; the platform's revenue
 * did not, which meant "what did the marketplace earn this month" was a
 * query over order items rather than a set of posted entries. That works
 * until the first refund, at which point the answer depends on remembering
 * to subtract — so commission is posted here on payment and reversed here
 * on refund, both from the item's own snapshot.
 *
 * `source_key` is what makes posting exactly-once: a retried job finds the
 * row already there rather than posting a second one, enforced by a unique
 * index rather than by a check that races.
 *
 * @property int $id
 * @property string $public_id
 * @property int $marketplace_order_id
 * @property int|null $seller_order_id
 * @property int|null $order_item_id
 * @property int $seller_account_id
 * @property string $type
 * @property string $currency
 * @property int $amount_minor
 * @property string|null $rate_percent_snapshot
 * @property int|null $reverses_entry_id
 * @property string|null $source_key
 * @property Carbon $created_at
 */
final class PlatformRevenueEntry extends Model
{
    use HasPublicId;

    public const TYPE_COMMISSION = 'commission';

    public const TYPE_REVERSAL = 'commission_reversal';

    protected $table = 'platform_revenue_entries';

    public $timestamps = false;

    protected $fillable = [
        'marketplace_order_id', 'seller_order_id', 'order_item_id',
        'seller_account_id', 'type', 'currency', 'amount_minor',
        'rate_percent_snapshot', 'reverses_entry_id', 'source_key', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function amount(): Money
    {
        return Money::of(abs($this->amount_minor), $this->currency);
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'platform_revenue_entries is append-only: reverse an entry rather than editing it.'
            );
        });

        self::deleting(function (): never {
            throw new RuntimeException('platform_revenue_entries is append-only.');
        });
    }
}

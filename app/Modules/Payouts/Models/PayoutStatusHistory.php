<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Every state a payout request passed through, and who moved it.
 *
 * Append-only. The request row carries the current state because that is
 * what a query filters on; this carries how it got there, which is what a
 * dispute needs. Amounts are not repeated here — a payout has exactly one
 * amount for its whole life, and duplicating it would create a second
 * place for it to be wrong.
 *
 * @property int $id
 * @property int $payout_request_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string|null $reason
 * @property Carbon $created_at
 */
final class PayoutStatusHistory extends Model
{
    protected $table = 'payout_status_history';

    public $timestamps = false;

    protected $fillable = [
        'payout_request_id', 'from_status', 'to_status',
        'actor_type', 'actor_id', 'actor_label', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('payout_status_history is append-only.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('payout_status_history is append-only.');
        });
    }

    /** @return BelongsTo<PayoutRequest, $this> */
    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequest::class);
    }
}

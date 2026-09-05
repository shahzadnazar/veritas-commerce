<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\FulfilmentIssueReason;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "This cannot be fulfilled as sold."
 *
 * The seller's way of raising a hand without being handed the platform's
 * money: reporting a problem is not the same authority as issuing a
 * refund, and §26 keeps those apart deliberately. An admin with the refund
 * permission decides what happens to the money.
 *
 * @property int $id
 * @property string $public_id
 * @property int $seller_order_id
 * @property int|null $shipment_id
 * @property FulfilmentIssueReason $reason
 * @property string $note
 * @property string $reported_by_type
 * @property int|null $reported_by_id
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_admin_id
 * @property string|null $resolution_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FulfilmentIssue extends Model
{
    use HasPublicId;

    protected $table = 'fulfilment_issues';

    protected $fillable = [
        'seller_order_id', 'shipment_id', 'reason', 'note',
        'reported_by_type', 'reported_by_id',
        'resolved_at', 'resolved_by_admin_id', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'reason' => FulfilmentIssueReason::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SellerOrder, $this> */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}

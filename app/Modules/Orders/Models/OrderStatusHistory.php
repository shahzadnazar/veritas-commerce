<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Database\Factories\OrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** APPEND ONLY. Every transition is its own row with an actor. */
final class OrderStatusHistory extends Model
{
    /** @use HasFactory<OrderStatusHistoryFactory> */
    use HasFactory;

    protected $table = 'order_status_history';

    public $timestamps = false;

    protected $fillable = [
        'seller_order_id', 'marketplace_order_id', 'from_status', 'to_status',
        'actor_type', 'actor_id', 'note', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('order_status_history is append-only.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('order_status_history is append-only.');
        });
    }
}

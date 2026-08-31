<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use Database\Factories\ProviderWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The idempotency ledger for inbound provider events.
 *
 * The unique index on (provider, event_id) is what stops a replayed webhook
 * posting duplicate financial rows — an application-level "have I seen
 * this?" check races under concurrency and this does not.
 */
final class ProviderWebhookEvent extends Model
{
    /** @use HasFactory<ProviderWebhookEventFactory> */
    use HasFactory;

    protected $table = 'provider_webhook_events';

    public $timestamps = false;

    protected $fillable = [
        'provider', 'event_id', 'type', 'payload', 'received_at', 'processed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}

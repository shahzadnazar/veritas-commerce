<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Data\WebhookEvent;
use App\Modules\Payments\Models\ProviderWebhookEvent;

/**
 * Idempotent intake for provider webhooks.
 *
 * A replayed event must not post a second financial row. The guarantee is
 * the unique index on (provider, event_id) — an application-level
 * "have I seen this?" check races under concurrency and this does not.
 *
 * The insert is INSERT ... ON CONFLICT DO NOTHING rather than a try/catch
 * on the unique violation, and that detail matters: in PostgreSQL a failed
 * statement aborts the whole surrounding transaction, so catching the
 * violation inside a handler that is already in a transaction would poison
 * every statement after it. ON CONFLICT DO NOTHING simply affects no rows.
 */
final class RecordWebhookEvent
{
    /** @return ProviderWebhookEvent|null null when this event was already recorded */
    public function __invoke(WebhookEvent $event): ?ProviderWebhookEvent
    {
        $inserted = ProviderWebhookEvent::query()->insertOrIgnore([
            'provider' => $event->provider,
            'event_id' => $event->eventId,
            'type' => $event->type,
            'payload' => json_encode($event->payload),
            'received_at' => now(),
        ]);

        if ($inserted === 0) {
            return null;
        }

        return ProviderWebhookEvent::query()
            ->where('provider', $event->provider)
            ->where('event_id', $event->eventId)
            ->first();
    }
}

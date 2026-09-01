<?php

declare(strict_types=1);

namespace App\Modules\Events\Jobs;

use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Events\Models\InteractionEvent;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Carbon;

/**
 * Writes one behavioural event, off the request.
 *
 * §34 is explicit that analytics must not block a customer: a slow write
 * here would show up as a slow search results page, which is the one place
 * latency is most visible. So the controller hands over the fields and
 * returns.
 *
 * The fields are named parameters rather than an array, because an
 * analytics row assembled from a free-form bag is exactly the thing that
 * silently loses a column when somebody renames a key — and nobody notices
 * for six months, by which time the training data has a hole in it.
 *
 * Carries scalars rather than models: by the time a worker runs this the
 * product may have been renamed, and the event should record what was true
 * when it happened.
 */
final class RecordInteractionEvent implements ShouldQueue
{
    use FoundationQueueable;
    use Queueable;

    public int $tries = 2;

    /** @param  array<string, mixed>|null  $metadata */
    public function __construct(
        public readonly string $eventId,
        public readonly InteractionEventType $type,
        public readonly ?int $userId = null,
        public readonly ?string $anonymousSessionId = null,
        public readonly ?int $productId = null,
        public readonly ?int $sellerAccountId = null,
        public readonly ?string $searchQuery = null,
        public readonly ?int $resultPosition = null,
        public readonly ?string $context = null,
        public readonly ?array $metadata = null,
    ) {
        // Its own low-priority lane. Losing an analytics row is a shame;
        // delaying a payment because of one is not acceptable.
        $this->onQueue(Queues::DEFAULT);
    }

    public function handle(): void
    {
        InteractionEvent::query()->create([
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
            'anonymous_session_id' => $this->anonymousSessionId,
            'event_type' => $this->type->value,
            'product_id' => $this->productId,
            'seller_account_id' => $this->sellerAccountId,
            'search_query' => $this->searchQuery,
            'result_position' => $this->resultPosition,
            'context' => $this->context,
            'metadata' => $this->metadata,
            'created_at' => Carbon::now(),
        ]);
    }
}

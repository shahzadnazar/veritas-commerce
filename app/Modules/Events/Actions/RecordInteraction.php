<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Events\Jobs\RecordInteractionEvent;
use App\Modules\Events\Support\AnonymousSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one way behaviour is recorded.
 *
 * Analytics, deliberately separate from the audit log. §48 draws the line
 * and it matters: an audit table that also carries every anonymous product
 * view stops being the place you look to answer "who suspended this
 * seller, and why". These events are for ranking and recommendations
 * later; the audit is for accountability now.
 *
 * Every write is queued. A customer's search must not wait on an analytics
 * insert, and an analytics failure must never fail their page.
 */
final class RecordInteraction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Request $request,
        InteractionEventType $type,
        ?string $subjectType = null,
        ?string $subjectPublicId = null,
        ?int $productId = null,
        ?int $sellerAccountId = null,
        array $payload = [],
        ?int $offerId = null,
        ?int $valueMinor = null,
        /**
         * The customer, when the request cannot say who they are.
         *
         * Most events are recorded in the customer's own request and the
         * session answers this. A payment is decided by a webhook, in a
         * queued job with no session at all, so the order carries the
         * attribution instead — otherwise every purchase in the stream
         * would be anonymous.
         */
        ?int $userId = null,
    ): void {
        $productId ??= $subjectType === 'product' && $subjectPublicId !== null
            ? $this->productIdFor($subjectPublicId)
            : null;

        $user = $request->user('web');

        $query = isset($payload['query']) && is_string($payload['query']) && $payload['query'] !== ''
            ? mb_substr($payload['query'], 0, 200)
            : null;

        $job = new RecordInteractionEvent(
            eventId: (string) Str::ulid(),
            type: $type,
            userId: $userId ?? ($user === null ? null : (int) $user->getAuthIdentifier()),
            // Present for a signed-in customer too: it is what stitches
            // their behaviour before signing in to their behaviour after.
            anonymousSessionId: AnonymousSession::idFor($request),
            productId: $productId,
            offerId: $offerId,
            sellerAccountId: $sellerAccountId,
            valueMinor: $valueMinor,
            searchQuery: $query,
            resultPosition: isset($payload['position']) ? (int) $payload['position'] : null,
            context: isset($payload['context']) && is_string($payload['context']) ? $payload['context'] : null,
            metadata: $payload === [] ? null : $payload,
        );

        // After commit where there is a transaction, so an event never
        // describes something that was rolled back.
        DB::afterCommit(static function () use ($job): void {
            dispatch($job);
        });
    }

    /**
     * A search, with how many results it produced.
     *
     * The result count is the point: zero-result searches are the most
     * actionable thing in the whole event stream, and an event that only
     * recorded the words would not distinguish them.
     *
     * @param  array<string, mixed>  $filters
     */
    public function search(Request $request, string $phrase, int $resultCount, array $filters = []): void
    {
        if (trim($phrase) === '' && ($filters['hasFilters'] ?? false) === false) {
            // Landing on /search with nothing typed is not a search.
            return;
        }

        $this->record($request, InteractionEventType::SearchPerformed, payload: [
            'query' => $phrase,
            'results' => $resultCount,
            'filters' => array_filter([
                'brand' => $filters['brand'] ?? [],
                'condition' => $filters['condition'] ?? [],
                'attributes' => $filters['attributes'] ?? [],
                'in_stock' => $filters['in_stock'] ?? false,
            ]),
            'sort' => $filters['sort'] ?? null,
            'zero_results' => $resultCount === 0,
        ]);
    }

    private function productIdFor(string $publicId): ?int
    {
        $id = DB::table('products')->where('public_id', $publicId)->value('id');

        return $id === null ? null : (int) $id;
    }
}

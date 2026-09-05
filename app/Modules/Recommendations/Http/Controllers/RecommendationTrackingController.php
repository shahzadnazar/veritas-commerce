<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Http\Controllers;

use App\Modules\Events\Actions\RecordInteraction;
use App\Modules\Recommendations\Enums\RecommendationSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Impressions and clicks on recommendation shelves.
 *
 * A shelf that is clicked rarely and one that is *shown* rarely look
 * identical without the impression, so both are recorded — and the
 * fallback chain can then be judged rather than assumed.
 *
 * Everything the browser sends is treated as a claim about presentation,
 * never about anything else. A product is named by slug and resolved
 * server-side; a slot must be one of the declared cases; a position is
 * bounded. The worst a hostile client achieves is a wrong number on an
 * analytics chart — which is why §48 keeps money out of this stream
 * entirely.
 */
final class RecommendationTrackingController
{
    /** More cards than any shelf renders; anything beyond is a script. */
    private const MAX_PRODUCTS = 48;

    public function __construct(private readonly RecordInteraction $interactions) {}

    public function impressions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slot' => ['required', 'string', 'max:64'],
            'products' => ['required', 'array', 'max:'.self::MAX_PRODUCTS],
            'products.*' => ['string', 'max:255'],
        ]);

        $slot = RecommendationSlot::tryFrom((string) $validated['slot']);

        if ($slot === null) {
            return response()->json(['recorded' => false], 422);
        }

        /** @var array<int, string> $slugs */
        $slugs = array_values(array_unique(array_map(strval(...), $validated['products'])));
        $position = 0;

        foreach ($this->resolve($slugs) as $slug => $productId) {
            $position++;
            unset($slug);

            $this->interactions->recommendationShown($request, $productId, $slot->value, $position);
        }

        return response()->json(['recorded' => true]);
    }

    public function clicks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slot' => ['required', 'string', 'max:64'],
            'product' => ['required', 'string', 'max:255'],
            'position' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PRODUCTS],
        ]);

        $slot = RecommendationSlot::tryFrom((string) $validated['slot']);
        $resolved = $this->resolve([(string) $validated['product']]);

        if ($slot === null || $resolved === []) {
            return response()->json(['recorded' => false], 422);
        }

        $this->interactions->recommendationClicked(
            $request,
            (int) reset($resolved),
            $slot->value,
            (int) $validated['position'],
        );

        return response()->json(['recorded' => true]);
    }

    /**
     * Slugs to product ids, in the order the browser listed them.
     *
     * One query, and unknown slugs simply disappear — a client naming a
     * product that does not exist records nothing rather than erroring,
     * because an analytics beacon should never be a way to probe the
     * catalogue for what is there.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, int>
     */
    private function resolve(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $found = DB::table('products')
            ->whereIn('slug', $slugs)
            ->pluck('id', 'slug')
            ->map(intval(...))
            ->all();

        $ordered = [];

        foreach ($slugs as $slug) {
            if (isset($found[$slug])) {
                $ordered[$slug] = $found[$slug];
            }
        }

        return $ordered;
    }
}

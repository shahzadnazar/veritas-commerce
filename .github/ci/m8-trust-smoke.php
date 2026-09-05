<?php

declare(strict_types=1);

/*
 * Reviews, recommendations and analytics, end to end, inside the built
 * image.
 *
 * Runs against the container's real PostgreSQL and Redis, through the same
 * actions and services the storefront and portals call. Nothing here
 * inserts a review, a rating summary, a popularity score or a daily metric
 * by hand — a smoke that did could pass while the real path produced
 * something different.
 *
 * Five things are exercised:
 *
 *   1. A verified review, established from a real delivered order, and a
 *      stranger's attempt at one being refused.
 *   2. One canonical product with two sellers: one rating, and a
 *      recommendation shelf that shows the product once.
 *   3. The recommendation rebuild: deterministic, idempotent, and unable
 *      to touch a transactional table.
 *   4. The analytics rebuild, and its money columns agreeing with M7's
 *      finance summary to the penny.
 *   5. The rating reconciliation, which must report clean after all of it.
 *
 * Printed as key=value lines the workflow greps, so a failure here fails
 * this step rather than surfacing as a confusing assertion later.
 */

use App\Console\Commands\RebuildRecommendationsCommand;
use App\Modules\Analytics\Actions\RebuildDailyMetrics;
use App\Modules\Analytics\Support\AnalyticsDay;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\BuildIndexableProduct;
use App\Modules\Identity\Models\User;
use App\Modules\Offers\Models\Offer;
use App\Modules\Payouts\Queries\SummarisePlatformFinance;
use App\Modules\Recommendations\Actions\RebuildProductAssociations;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Enums\RecommendationSlot;
use App\Modules\Recommendations\RecommendationService;
use App\Modules\Reviews\Actions\ModerateReview;
use App\Modules\Reviews\Actions\SubmitReview;
use App\Modules\Reviews\Data\ReviewActor;
use App\Modules\Reviews\Exceptions\ReviewRefused;
use App\Modules\Reviews\Queries\GetRatingSummary;
use App\Modules\Reviews\Queries\ReconcileRatings;
use App\Modules\Search\Contracts\SearchIndex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/* ---- 0. Find the seeded fixtures --------------------------------------- */

/*
 * The M4 smoke seed has already placed, paid, shipped and delivered
 * orders. This finds a delivered line and reviews the product on it,
 * rather than building a second parallel fixture — the point is that the
 * review rests on the same order records the rest of the marketplace
 * produced.
 */
$line = DB::table('order_items as oi')
    ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
    ->join('marketplace_orders as mo', 'mo.id', '=', 'so.marketplace_order_id')
    ->join('payments as pay', 'pay.marketplace_order_id', '=', 'mo.id')
    ->whereIn('so.status', ['delivered', 'completed'])
    ->whereIn('pay.status', ['captured', 'partially_refunded'])
    ->whereNotNull('mo.user_id')
    ->orderBy('oi.id')
    ->select(['oi.product_id', 'mo.user_id', 'so.id as seller_order_id'])
    ->first();

if ($line === null) {
    printf("fixture missing=delivered_paid_line\n");

    exit(1);
}

$productId = (int) $line->product_id;
$buyerId = (int) $line->user_id;

/** @var Product $product */
$product = Product::query()->findOrFail($productId);
/** @var User $buyer */
$buyer = User::query()->findOrFail($buyerId);

printf("fixture product=%d buyer=%d seller_order=%d\n", $productId, $buyerId, (int) $line->seller_order_id);
printf("product_slug=%s\n", $product->slug);

/* ---- 1. A verified review, and one that cannot be faked ---------------- */

$review = app(SubmitReview::class)(
    userId: $buyerId,
    productId: $productId,
    rating: 5,
    body: 'Arrived on time and does exactly what the listing said it would.',
    title: 'As described',
);

printf("review id=%d verified=%s status=%s order_item=%s\n",
    $review->id,
    $review->verified_purchase ? 'yes' : 'no',
    $review->status->value,
    $review->order_item_id === null ? 'none' : 'set');

// A stranger with no purchase behind them.
$stranger = User::query()->create([
    'first_name' => 'Ci',
    'last_name' => 'Stranger',
    'email' => 'ci-stranger-'.uniqid().'@veritas.test',
    'password' => bcrypt('password'),
]);

$refused = 'allowed';

try {
    app(SubmitReview::class)(
        userId: (int) $stranger->id,
        productId: $productId,
        rating: 5,
        body: 'A review from somebody who has never bought this product.',
    );
} catch (ReviewRefused $refusal) {
    $refused = $refusal->reason;
}

printf("unverified reason=%s\n", $refused);

/* ---- 2. One product, many sellers, one rating -------------------------- */

$offerCount = Offer::query()->where('product_id', $productId)->count();
$summary = app(GetRatingSummary::class)($productId);
$summaryRows = DB::table('product_rating_summaries')->where('product_id', $productId)->count();

printf("rating offers=%d summaries=%d average=%s count=%d verified=%d\n",
    $offerCount,
    $summaryRows,
    $summary->average === null ? 'none' : number_format($summary->average, 2, '.', ''),
    $summary->reviewCount,
    $summary->verifiedCount);

// Hiding it must take it off the rating in the same moment.
$hidden = app(ModerateReview::class)->hide($review, ReviewActor::system('CI'), 'Smoke check.');
$afterHide = app(GetRatingSummary::class)($productId);

printf("hidden changed=%s has_rating=%s count=%d\n",
    $hidden ? 'yes' : 'no',
    $afterHide->hasRating ? 'yes' : 'no',
    $afterHide->reviewCount);

app(ModerateReview::class)->restore($review, ReviewActor::system('CI'), 'Putting it back.');

$afterRestore = app(GetRatingSummary::class)($productId);
printf("restored has_rating=%s count=%d\n",
    $afterRestore->hasRating ? 'yes' : 'no',
    $afterRestore->reviewCount);

/* ---- 3. Recommendations: one product, however many sellers ------------- */

// Make sure the anchor and its neighbours are indexed as the storefront
// would have them.
$index = app(SearchIndex::class);

foreach (Product::query()->limit(50)->pluck('id') as $id) {
    $document = app(BuildIndexableProduct::class)->describe((int) $id);

    if ($document === null) {
        $index->forget((int) $id);

        continue;
    }

    $index->index($document);
}

$asOf = Carbon::now();
app(RebuildProductAssociations::class)($asOf);

$anchor = Product::query()
    ->where('id', '!=', $productId)
    ->whereHas('offers')
    ->orderBy('id')
    ->first();

$set = app(RecommendationService::class)->for(new RecommendationRequest(
    slot: RecommendationSlot::SimilarProducts,
    anchorProductId: $anchor === null ? $productId : (int) $anchor->id,
    limit: 12,
));

$ids = $set->productIds();

printf("recommendations count=%d distinct=%d contains_anchor=%s strategies=%s\n",
    count($ids),
    count(array_unique($ids)),
    in_array($anchor === null ? $productId : (int) $anchor->id, $ids, true) ? 'yes' : 'no',
    implode(',', $set->strategies) ?: 'none');

// Every recommended product must be publicly visible right now.
$ineligible = $ids === [] ? 0 : DB::table('products')
    ->whereIn('id', $ids)
    ->where(function ($query): void {
        $query->where('status', '!=', 'published')->orWhereNotNull('merged_into_product_id');
    })
    ->count();

printf("recommendations ineligible=%d\n", $ineligible);

/* ---- 4. The rebuilds are idempotent and cannot touch the money --------- */

$fingerprint = static function (): array {
    $signatures = [];

    foreach (RebuildRecommendationsCommand::PROTECTED_TABLES as $table) {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            continue;
        }

        $row = DB::table($table)->selectRaw('count(*) as rows, coalesce(sum(id), 0) as checksum')->first();
        $signatures[$table] = $row === null ? 'unreadable' : $row->rows.'/'.$row->checksum;
    }

    return $signatures;
};

$projection = static fn (): string => md5((string) json_encode([
    DB::table('product_popularity_scores')->orderBy('product_id')->orderBy('window_days')
        ->get(['product_id', 'window_days', 'score'])->toArray(),
    DB::table('product_associations')->orderBy('kind')->orderBy('product_id')->orderBy('associated_product_id')
        ->get(['product_id', 'associated_product_id', 'kind', 'support'])->toArray(),
]));

$before = $fingerprint();
$pinned = $asOf->toDateTimeString();

Artisan::call('recommendations:rebuild', ['--as-of' => $pinned]);
$first = $projection();

Artisan::call('recommendations:rebuild', ['--as-of' => $pinned]);
$second = $projection();

$after = $fingerprint();

printf("reco_rebuild idempotent=%s transactional_unchanged=%s\n",
    $first === $second ? 'yes' : 'no',
    $before === $after ? 'yes' : 'no');

$verifyExit = Artisan::call('recommendations:rebuild', ['--as-of' => $pinned, '--verify' => true]);
printf("reco_verify exit=%d\n", $verifyExit);

/* ---- 5. Analytics agrees with M7 to the penny -------------------------- */

$days = AnalyticsDay::lastDays(30);
app(RebuildDailyMetrics::class)->forDays($days);

$rolled = DB::table('daily_marketplace_metrics')
    ->where('currency', 'USD')
    ->selectRaw(
        'coalesce(sum(gmv_minor), 0) as gmv, '.
        'coalesce(sum(refunds_minor), 0) as refunds, '.
        'coalesce(sum(commission_minor), 0) as commission'
    )
    ->first();

$finance = app(SummarisePlatformFinance::class)(
    Carbon::instance($days[0]->startsAt->toDateTime()),
    Carbon::instance($days[count($days) - 1]->endsAt->toDateTime()),
    'USD',
);

printf("analytics gmv_rolled=%d gmv_m7=%d refunds_rolled=%d refunds_m7=%d commission_rolled=%d commission_m7=%d\n",
    (int) $rolled->gmv,
    (int) $finance['flows']['gmvMinor'],
    (int) $rolled->refunds,
    (int) $finance['flows']['refundsMinor'],
    (int) $rolled->commission,
    (int) $finance['flows']['commissionMinor']);

printf("analytics agrees=%s\n",
    ((int) $rolled->gmv === (int) $finance['flows']['gmvMinor']
        && (int) $rolled->refunds === (int) $finance['flows']['refundsMinor']
        && (int) $rolled->commission === (int) $finance['flows']['commissionMinor']) ? 'yes' : 'no');

$analyticsExit = Artisan::call('analytics:rebuild', ['--days' => 30, '--verify' => true]);
printf("analytics_verify exit=%d\n", $analyticsExit);

/* ---- 6. Do the ratings add up? ----------------------------------------- */

$problems = app(ReconcileRatings::class)();

printf("reconcile problems=%d\n", count($problems));

foreach ($problems as $problem) {
    printf("reconcile_problem product=%s %s: %s\n",
        $problem['product_id'],
        $problem['check'],
        $problem['detail']);
}

Artisan::call('reviews:reconcile-ratings');
printf("reconcile_command %s\n", str_replace("\n", ' ', trim(Artisan::output())));

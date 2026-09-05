<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Actions;

use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Recommendations\Enums\AssociationKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Recomputes product_associations: which products go with which.
 *
 * Two counts, from two different kinds of evidence:
 *
 *   - **bought together** — pairs of products in the same paid order. The
 *     order is read, never written; §2 in practice. What makes an order
 *     count is the payment status the payments module already decided, so
 *     this class has no opinion about what "paid" means.
 *   - **viewed together** — pairs of products viewed by the same visitor
 *     inside one session.
 *
 * Both are symmetric: A goes with B *and* B goes with A, written as two
 * rows, because a query for one anchor should not have to search both
 * columns. Support is stored raw and thresholded at read time, so raising
 * or lowering the bar later needs no rebuild.
 *
 * Idempotent by construction: each kind is deleted and reinserted inside
 * one transaction, so two runs over unchanged data produce the same rows.
 */
final class RebuildProductAssociations
{
    /** Beyond this, a single order or session says nothing useful. */
    private const MAX_BASKET_SIZE = 20;

    /** How far back co-occurrence still counts. */
    private const LOOKBACK_DAYS = 180;

    /** @return array<string, int> rows written, per kind */
    public function __invoke(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();
        $since = $asOf->copy()->subDays(self::LOOKBACK_DAYS);

        return [
            AssociationKind::BoughtTogether->value => $this->rebuild(
                AssociationKind::BoughtTogether,
                $this->purchaseBaskets($since, $asOf),
                $asOf,
            ),
            AssociationKind::ViewedTogether->value => $this->rebuild(
                AssociationKind::ViewedTogether,
                $this->viewBaskets($since, $asOf),
                $asOf,
            ),
        ];
    }

    /**
     * @param  array<int|string, array<int, int>>  $baskets  each a set of product ids
     */
    private function rebuild(AssociationKind $kind, array $baskets, Carbon $asOf): int
    {
        /** @var array<string, array{product_id: int, associated_product_id: int, support: int}> $pairs */
        $pairs = [];

        foreach ($baskets as $products) {
            $products = array_values(array_unique($products));

            if (count($products) < 2 || count($products) > self::MAX_BASKET_SIZE) {
                // A basket of one produces no pairs; a basket of fifty is
                // a bulk buyer or a crawler, and letting it contribute
                // 1,225 pairs would drown every real signal.
                continue;
            }

            sort($products);

            foreach ($products as $left) {
                foreach ($products as $right) {
                    if ($left === $right) {
                        continue;
                    }

                    $key = $left.':'.$right;
                    $pairs[$key] ??= [
                        'product_id' => $left,
                        'associated_product_id' => $right,
                        'support' => 0,
                    ];
                    $pairs[$key]['support']++;
                }
            }
        }

        $rows = [];

        foreach ($pairs as $pair) {
            $rows[] = [
                'product_id' => $pair['product_id'],
                'associated_product_id' => $pair['associated_product_id'],
                'kind' => $kind->value,
                'support' => $pair['support'],
                // Score and support are the same number today. They are
                // separate columns because they answer different
                // questions — "how much evidence" and "how strongly should
                // this rank" — and a later scorer that discounts old
                // baskets will change one without touching the other.
                'score' => $pair['support'],
                'computed_at' => $asOf,
            ];
        }

        DB::transaction(function () use ($kind, $rows): void {
            DB::table('product_associations')->where('kind', $kind->value)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('product_associations')->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * Products bought in the same paid marketplace order.
     *
     * Read-only across three commerce tables. The join to payments is what
     * makes "paid" mean what the payments module says it means — the one
     * definition — rather than a status string copied into this file.
     *
     * @return array<int, array<int, int>>
     */
    private function purchaseBaskets(Carbon $since, Carbon $asOf): array
    {
        $rows = DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->join('marketplace_orders as mo', 'mo.id', '=', 'so.marketplace_order_id')
            ->join('payments as pay', 'pay.marketplace_order_id', '=', 'mo.id')
            ->whereIn('pay.status', $this->paidPaymentStatuses())
            ->where('mo.created_at', '>=', $since)
            ->where('mo.created_at', '<=', $asOf)
            ->distinct()
            ->select(['mo.id as basket_id', 'oi.product_id'])
            ->get();

        return $this->group($rows);
    }

    /**
     * Products viewed by the same visitor in the same session.
     *
     * The session is the basket. An anonymous session id is pseudonymous
     * and rotating by design (M0), so this groups behaviour without ever
     * identifying anybody — which is the point: co-occurrence needs to
     * know that two views happened together, not who had them.
     *
     * @return array<string, array<int, int>>
     */
    private function viewBaskets(Carbon $since, Carbon $asOf): array
    {
        $rows = DB::table('interaction_events')
            ->whereNotNull('product_id')
            ->where('event_type', InteractionEventType::ProductViewed->value)
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $asOf)
            ->whereRaw('(user_id is not null or anonymous_session_id is not null)')
            ->distinct()
            ->select([
                DB::raw("coalesce('u'||user_id::text, 's'||anonymous_session_id) as basket_id"),
                'product_id',
            ])
            ->get();

        return $this->group($rows);
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<array-key, array<int, int>>
     */
    private function group(Collection $rows): array
    {
        $baskets = [];

        foreach ($rows as $row) {
            $basket = is_int($row->basket_id) ? $row->basket_id : (string) $row->basket_id;
            $baskets[$basket][] = (int) $row->product_id;
        }

        return $baskets;
    }

    /**
     * The payment statuses that mean money actually moved.
     *
     * Partially refunded still counts: the customer bought both things and
     * sent one back, which is exactly the co-occurrence being measured.
     * Fully refunded does not appear, because a reversed order is not
     * evidence of anything.
     *
     * @return array<int, string>
     */
    private function paidPaymentStatuses(): array
    {
        return [
            PaymentStatus::Captured->value,
            PaymentStatus::PartiallyRefunded->value,
        ];
    }
}

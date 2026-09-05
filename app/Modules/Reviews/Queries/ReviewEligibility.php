<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Queries;

use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Reviews\Data\ReviewEvidence;
use App\Modules\Reviews\Enums\ReviewIneligibility;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\ProductReview;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Whether this customer may review this product, and on what evidence.
 *
 * THE ONLY PLACE `verified_purchase` COMES FROM. §4 is unambiguous that
 * the client must never submit it, and the way that is guaranteed is that
 * nothing accepts it: SubmitReview does not take the flag as a parameter,
 * it takes a customer and a product and asks this. A request body can
 * carry `verified_purchase=true` all it likes and reach nothing.
 *
 * The evidence, all five parts, in the order they are checked:
 *
 *   1. An order item for this canonical product exists on an order this
 *      customer owns — matched on `user_id`, never on an id the request
 *      supplied.
 *   2. The payment for that order was captured. Not "an order exists":
 *      an unpaid order is a shopping list.
 *   3. The seller order carrying the line reached DELIVERED or COMPLETED.
 *      A review of something that has not arrived is a review of a
 *      photograph.
 *   4. The line was not refunded in full. Somebody who sent it all back
 *      before it arrived has nothing to report on.
 *   5. The customer has no live review of this product already (§6).
 *
 * The order line found becomes the review's evidence and is stored on it,
 * so a moderator can check the claim rather than trust the flag.
 *
 * Reads only. Nothing in this file writes to an order, a payment or a
 * ledger, and the analytics boundary test covers the modules that must
 * never do so.
 */
final class ReviewEligibility
{
    public function __invoke(int $userId, int $productId): ReviewEvidence
    {
        $existing = $this->liveReviewFor($userId, $productId);

        if ($existing !== null) {
            /*
             * A rejected review is live for the uniqueness rule but is not
             * "you already reviewed this" to the customer — they are told
             * it was refused, which is a different sentence and the only
             * honest one.
             */
            return ReviewEvidence::refused(
                $existing->status === ReviewStatus::Rejected
                    ? ReviewIneligibility::Rejected
                    : ReviewIneligibility::AlreadyReviewed,
                existingReviewId: $existing->id,
            );
        }

        $line = $this->bestPurchaseLine($userId, $productId);

        if ($line === null) {
            return ReviewEvidence::refused($this->whyNoQualifyingLine($userId, $productId));
        }

        return ReviewEvidence::verified(
            orderItemId: $line['order_item_id'],
            sellerOrderId: $line['seller_order_id'],
        );
    }

    /** The customer's current live review of this product, if any. */
    public function liveReviewFor(int $userId, int $productId): ?ProductReview
    {
        /** @var ProductReview|null $review */
        $review = ProductReview::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->whereIn('status', $this->liveStatusValues())
            ->first();

        return $review;
    }

    /**
     * Reviewable products from a single order, for the order screen.
     *
     * One query for the whole order rather than one per line: an order
     * detail page asks this for every item on it.
     *
     * @return array<int, int> product ids this customer may review now
     */
    public function reviewableProductsIn(int $userId, int $marketplaceOrderId): array
    {
        $products = $this->qualifyingLines($userId)
            ->where('so.marketplace_order_id', $marketplaceOrderId)
            ->distinct()
            ->pluck('oi.product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($products === []) {
            return [];
        }

        $alreadyReviewed = ProductReview::query()
            ->where('user_id', $userId)
            ->whereIn('product_id', $products)
            ->whereIn('status', $this->liveStatusValues())
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_diff($products, $alreadyReviewed));
    }

    /**
     * The order line this review will rest on.
     *
     * The most recent qualifying one, so a repeat buyer's review is
     * founded on the purchase they most likely have in front of them.
     *
     * @return array{order_item_id: int, seller_order_id: int}|null
     */
    private function bestPurchaseLine(int $userId, int $productId): ?array
    {
        $line = $this->qualifyingLines($userId)
            ->where('oi.product_id', $productId)
            ->orderByDesc('so.id')
            ->first();

        return $line === null ? null : [
            'order_item_id' => (int) $line->order_item_id,
            'seller_order_id' => (int) $line->seller_order_id,
        ];
    }

    /**
     * Order lines that satisfy every part of the evidence.
     *
     * Built as one query so the five conditions cannot drift apart, and
     * joined through `marketplace_orders.user_id` so ownership is a fact
     * about the data rather than a parameter the caller passed.
     */
    private function qualifyingLines(int $userId): Builder
    {
        return DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->join('marketplace_orders as mo', 'mo.id', '=', 'so.marketplace_order_id')
            ->join('payments as p', 'p.marketplace_order_id', '=', 'mo.id')
            ->where('mo.user_id', $userId)
            ->whereNotNull('oi.product_id')
            // 2. The money actually arrived.
            ->whereIn('p.status', [
                PaymentStatus::Captured->value,
                PaymentStatus::PartiallyRefunded->value,
            ])
            // 3. The seller's slice of the order reached the customer.
            ->whereIn('so.status', [
                SellerOrderStatus::Delivered->value,
                SellerOrderStatus::Completed->value,
            ])
            // 4. Not sent back in full. `line_total_minor` is the
            // snapshot; `refunded_amount_minor` is what came back.
            ->whereColumn('oi.refunded_amount_minor', '<', 'oi.line_total_minor')
            ->select([
                'oi.id as order_item_id',
                'oi.product_id',
                'so.id as seller_order_id',
                'so.marketplace_order_id',
            ]);
    }

    /**
     * Which part of the evidence is missing, for a message worth reading.
     *
     * Deliberately a second pass rather than five separate queries in the
     * happy path: the common case is a customer who may review, and it
     * costs one query. Only a refusal pays for the explanation.
     */
    private function whyNoQualifyingLine(int $userId, int $productId): ReviewIneligibility
    {
        $owned = DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->join('marketplace_orders as mo', 'mo.id', '=', 'so.marketplace_order_id')
            ->where('mo.user_id', $userId)
            ->where('oi.product_id', $productId)
            ->select(['so.status', 'so.marketplace_order_id', 'oi.refunded_amount_minor', 'oi.line_total_minor'])
            ->get();

        if ($owned->isEmpty()) {
            return ReviewIneligibility::NotPurchased;
        }

        $paidOrderIds = DB::table('payments')
            ->whereIn('marketplace_order_id', $owned->pluck('marketplace_order_id')->unique())
            ->whereIn('status', [
                PaymentStatus::Captured->value,
                PaymentStatus::PartiallyRefunded->value,
            ])
            ->pluck('marketplace_order_id')
            ->all();

        $paid = $owned->filter(
            static fn (object $row): bool => in_array($row->marketplace_order_id, $paidOrderIds, true),
        );

        if ($paid->isEmpty()) {
            return ReviewIneligibility::NotPaid;
        }

        $delivered = $paid->filter(static fn (object $row): bool => in_array((string) $row->status, [
            SellerOrderStatus::Delivered->value,
            SellerOrderStatus::Completed->value,
        ], true));

        if ($delivered->isEmpty()) {
            return ReviewIneligibility::NotDelivered;
        }

        // Delivered and paid, so the only thing left is that every one of
        // them came back.
        return ReviewIneligibility::FullyRefunded;
    }

    /** @return array<int, string> */
    private function liveStatusValues(): array
    {
        return array_values(array_map(
            static fn (ReviewStatus $status): string => $status->value,
            array_filter(ReviewStatus::cases(), static fn (ReviewStatus $status): bool => $status->isLive()),
        ));
    }
}

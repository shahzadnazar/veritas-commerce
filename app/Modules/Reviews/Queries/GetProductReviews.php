<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Queries;

use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\ProductReview;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The reviews a visitor may read, and the one they wrote.
 *
 * Only `published` reviews are public. Hidden, rejected and withdrawn ones
 * are absent from the list and absent from the count, so a moderator
 * hiding a review removes it from view *and* from the arithmetic in the
 * same moment — there is no intermediate state where a rating counts a
 * review nobody can see (§8).
 *
 * The author's name is a display name and never an email: a reviews list
 * is the easiest place on a marketplace to leak an address, and the query
 * simply does not select the column.
 */
final class GetProductReviews
{
    public const PER_PAGE = 10;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $productId, int $page = 1, ?int $viewerId = null): array
    {
        $page = max(1, $page);

        $total = DB::table('product_reviews')
            ->where('product_id', $productId)
            ->where('status', ReviewStatus::Published->value)
            ->count();

        $rows = DB::table('product_reviews as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.product_id', $productId)
            ->where('r.status', ReviewStatus::Published->value)
            // Verified reviews first, then most recent. A purchase-backed
            // review is the one a shopper came for; burying it under an
            // unverified one because it is a day older helps nobody.
            ->orderByDesc('r.verified_purchase')
            ->orderByDesc('r.published_at')
            ->orderByDesc('r.id')
            ->forPage($page, self::PER_PAGE)
            ->select([
                'r.public_id', 'r.rating', 'r.title', 'r.body',
                'r.verified_purchase', 'r.published_at', 'r.user_id',
                'u.first_name', 'u.last_name',
            ])
            ->get();

        return [
            'data' => $rows->map(
                fn (stdClass $row): array => $this->toArray($row, $viewerId)
            )->all(),
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'lastPage' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    /**
     * The visitor's own review of this product, whatever state it is in.
     *
     * Returned separately from the public list because a customer must be
     * able to see and edit a review a moderator has hidden — being told
     * why is the point of a moderation reason, and a hidden review that
     * simply vanished would leave them writing it again.
     *
     * @return array<string, mixed>|null
     */
    public function mine(int $productId, ?int $viewerId): ?array
    {
        if ($viewerId === null) {
            return null;
        }

        /** @var ProductReview|null $review */
        $review = ProductReview::query()
            ->where('product_id', $productId)
            ->where('user_id', $viewerId)
            ->whereIn('status', $this->liveStatusValues())
            ->first();

        if ($review === null) {
            return null;
        }

        return [
            'publicId' => $review->public_id,
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'status' => $review->status->value,
            'statusLabel' => $review->status->label(),
            'isPublic' => $review->status->isPublic(),
            'isEditable' => $review->status->isEditableByAuthor(),
            'verifiedPurchase' => $review->verified_purchase,
            'moderationReason' => $review->moderation_reason,
            'publishedAt' => $review->published_at?->toIso8601String(),
        ];
    }

    /** @return array<int, string> */
    private function liveStatusValues(): array
    {
        return array_values(array_map(
            static fn (ReviewStatus $status): string => $status->value,
            array_filter(ReviewStatus::cases(), static fn (ReviewStatus $status): bool => $status->isLive()),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(stdClass $row, ?int $viewerId): array
    {
        return [
            'publicId' => (string) $row->public_id,
            'rating' => (int) $row->rating,
            'title' => is_string($row->title) ? $row->title : null,
            'body' => (string) $row->body,
            'verifiedPurchase' => (bool) $row->verified_purchase,
            'publishedAt' => is_string($row->published_at) ? $row->published_at : null,
            'author' => $this->authorName($row),
            'isMine' => $viewerId !== null && (int) $row->user_id === $viewerId,
        ];
    }

    /**
     * A first name and a surname initial.
     *
     * Enough for a reader to tell two reviews apart, and not enough to
     * identify a customer to anybody who does not already know them. Never
     * the email, which is not selected at all.
     */
    private function authorName(stdClass $row): string
    {
        $first = trim((string) $row->first_name);
        $last = trim((string) $row->last_name);

        if ($first === '') {
            return 'Verified customer';
        }

        return $last === '' ? $first : $first.' '.mb_substr($last, 0, 1).'.';
    }
}

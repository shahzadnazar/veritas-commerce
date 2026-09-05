<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Queries;

use App\Modules\Reviews\Enums\ReviewStatus;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The admin's view of reviews, filtered.
 *
 * §8: verified reviews publish immediately, so this is a *review* queue
 * and not an *approval* queue — an admin looks at what is already live and
 * decides whether something should not be. Building a mandatory approval
 * step for every normal verified review would delay honest feedback by a
 * working day to catch the rare abusive one, and the marketplace would
 * quietly become one where reviews arrive late.
 *
 * The default filter is therefore "published", not "pending": there is no
 * pending state to look at.
 */
final class BuildModerationQueue
{
    public const PER_PAGE = 25;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $status, ?string $search, int $page = 1): array
    {
        $page = max(1, $page);
        $status = ReviewStatus::tryFrom((string) $status);

        $base = DB::table('product_reviews as r')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->join('users as u', 'u.id', '=', 'r.user_id');

        if ($status !== null) {
            $base->where('r.status', $status->value);
        }

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.mb_strtolower(trim($search)).'%';

            $base->where(function ($query) use ($term): void {
                $query->whereRaw('lower(r.body) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(r.title, \'\')) like ?', [$term])
                    ->orWhereRaw('lower(p.title) like ?', [$term]);
            });
        }

        $total = (clone $base)->count();

        $rows = $base
            ->orderByDesc('r.id')
            ->forPage($page, self::PER_PAGE)
            ->select([
                'r.public_id', 'r.rating', 'r.title', 'r.body', 'r.status',
                'r.verified_purchase', 'r.created_at', 'r.published_at',
                'r.moderation_reason', 'r.moderated_by_admin_id',
                'p.title as product_title', 'p.slug as product_slug',
                'u.first_name', 'u.last_name',
            ])
            ->get();

        return [
            'data' => $rows->map(static fn (stdClass $row): array => [
                'publicId' => (string) $row->public_id,
                'rating' => (int) $row->rating,
                'title' => is_string($row->title) ? $row->title : null,
                'body' => (string) $row->body,
                'status' => (string) $row->status,
                'verifiedPurchase' => (bool) $row->verified_purchase,
                'createdAt' => (string) $row->created_at,
                'publishedAt' => is_string($row->published_at) ? $row->published_at : null,
                'moderationReason' => is_string($row->moderation_reason) ? $row->moderation_reason : null,
                'wasModerated' => $row->moderated_by_admin_id !== null,
                'product' => [
                    'title' => (string) $row->product_title,
                    'slug' => (string) $row->product_slug,
                ],
                // A first name and a surname initial: enough to recognise
                // a repeat reviewer, and never the email.
                'author' => trim((string) $row->first_name.' '.mb_substr((string) $row->last_name, 0, 1).'.'),
            ])->all(),
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'lastPage' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    /**
     * How many reviews sit in each state.
     *
     * A single grouped query rather than one count per tab: the counts are
     * on every page load and a tab bar should not cost four round trips.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = DB::table('product_reviews')
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $byStatus = [];

        foreach (ReviewStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $byStatus;
    }
}

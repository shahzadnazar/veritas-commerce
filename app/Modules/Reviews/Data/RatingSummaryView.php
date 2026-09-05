<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Data;

use App\Modules\Reviews\Models\ProductRatingSummary;

/**
 * A product's rating, as the page shows it and the structured data emits
 * it — from ONE source, so the two can never disagree (§16).
 *
 * `hasRating` is the load-bearing field. A product nobody has reviewed has
 * no average at all rather than a 0.0, because 0 is not on the scale and a
 * rich result showing zero stars for a product with no reviews is a lie
 * that costs the domain's standing (§69).
 */
final readonly class RatingSummaryView
{
    /**
     * @param  array<int, int>  $distribution  rating (1-5) => count
     */
    public function __construct(
        public bool $hasRating,
        public ?float $average,
        public int $reviewCount,
        public int $verifiedCount,
        public array $distribution,
    ) {}

    public static function empty(): self
    {
        return new self(
            hasRating: false,
            average: null,
            reviewCount: 0,
            verifiedCount: 0,
            distribution: [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        );
    }

    public static function from(?ProductRatingSummary $summary): self
    {
        if ($summary === null || ! $summary->hasPublicRating()) {
            return self::empty();
        }

        return new self(
            hasRating: true,
            average: $summary->average(),
            reviewCount: $summary->published_review_count,
            verifiedCount: $summary->verified_review_count,
            distribution: [
                1 => $summary->count_1,
                2 => $summary->count_2,
                3 => $summary->count_3,
                4 => $summary->count_4,
                5 => $summary->count_5,
            ],
        );
    }

    /**
     * The share of reviews at each star, for the bar chart on the page.
     *
     * Computed here rather than in React, which would otherwise be doing
     * arithmetic on review counts — and would do it differently from the
     * next surface that needed it.
     *
     * @return array<int, array{rating: int, count: int, percent: int}>
     */
    public function distributionRows(): array
    {
        $rows = [];

        foreach ([5, 4, 3, 2, 1] as $rating) {
            $count = $this->distribution[$rating] ?? 0;

            $rows[] = [
                'rating' => $rating,
                'count' => $count,
                'percent' => $this->reviewCount === 0
                    ? 0
                    : (int) round($count / $this->reviewCount * 100),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'hasRating' => $this->hasRating,
            'average' => $this->average,
            // One decimal on screen, two in the data. A page showing "4.4"
            // and JSON-LD emitting 4.35 are the same rating rendered for
            // two audiences, not two different claims.
            'averageLabel' => $this->average === null ? null : number_format($this->average, 1),
            'reviewCount' => $this->reviewCount,
            'verifiedCount' => $this->verifiedCount,
            'distribution' => $this->distributionRows(),
        ];
    }
}

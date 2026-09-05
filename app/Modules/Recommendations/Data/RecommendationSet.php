<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Data;

use App\Modules\Recommendations\Enums\RecommendationSlot;

/**
 * A finished shelf: a heading, its products, and how they were chosen.
 *
 * The strategy keys are carried for explainability (§41) and for the admin
 * surface — "why is this here" has an answer that does not require reading
 * a log. They are not secret, but they are not customer copy either: the
 * page renders `title`, and the keys are there for whoever has to debug a
 * shelf that looks wrong.
 */
final readonly class RecommendationSet
{
    /**
     * @param  array<int, RecommendedProduct>  $products
     * @param  array<int, string>  $strategies  in the order they contributed
     */
    public function __construct(
        public RecommendationSlot $slot,
        public array $products,
        public array $strategies,
        public bool $usedFallback,
    ) {}

    public static function empty(RecommendationSlot $slot): self
    {
        return new self($slot, [], [], false);
    }

    public function isEmpty(): bool
    {
        return $this->products === [];
    }

    public function count(): int
    {
        return count($this->products);
    }

    /** @return array<int, int> */
    public function productIds(): array
    {
        return array_map(
            static fn (RecommendedProduct $product): int => $product->productId,
            $this->products,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slot' => $this->slot->value,
            'title' => $this->slot->title(),
            'products' => array_map(
                static fn (RecommendedProduct $product): array => $product->toArray(),
                $this->products,
            ),
            'strategies' => $this->strategies,
            'usedFallback' => $this->usedFallback,
        ];
    }
}

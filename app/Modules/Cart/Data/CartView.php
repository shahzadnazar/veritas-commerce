<?php

declare(strict_types=1);

namespace App\Modules\Cart\Data;

use App\Support\Money;

/**
 * The whole cart, revalidated, grouped by seller.
 *
 * Grouping is part of the read model rather than a React concern: a cart
 * spanning three sellers is one purchase the customer is making from three
 * businesses, and which lines belong together is a fact about the data.
 */
final readonly class CartView
{
    /**
     * @param  array<int, CartSellerGroup>  $groups
     * @param  array<int, CartIssue>  $issues  cart-level, not line-level
     */
    public function __construct(
        public ?string $cartPublicId,
        public array $groups,
        public array $issues,
        public Money $subtotal,
        public int $itemCount,
        public int $quantityCount,
        public string $currency,
    ) {}

    public static function empty(string $currency = 'USD'): self
    {
        return new self(
            cartPublicId: null,
            groups: [],
            issues: [],
            subtotal: Money::zero($currency),
            itemCount: 0,
            quantityCount: 0,
            currency: $currency,
        );
    }

    public function isEmpty(): bool
    {
        return $this->itemCount === 0;
    }

    /** @return array<int, CartLine> */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->groups as $group) {
            foreach ($group->lines as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Whether anything stops this cart becoming an order. */
    public function hasBlockingIssues(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->isBlocking()) {
                return true;
            }
        }

        foreach ($this->lines() as $line) {
            if ($line->hasBlockingIssue()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, CartIssue> */
    public function allIssues(): array
    {
        $issues = $this->issues;

        foreach ($this->lines() as $line) {
            foreach ($line->issues as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'groups' => array_map(
                static fn (CartSellerGroup $group): array => $group->toArray(),
                $this->groups,
            ),
            'issues' => array_map(static fn (CartIssue $issue): array => $issue->toArray(), $this->issues),
            'subtotal' => $this->subtotal->format(),
            'subtotalMinor' => $this->subtotal->minor,
            'itemCount' => $this->itemCount,
            'quantityCount' => $this->quantityCount,
            'currency' => $this->currency,
            'hasBlockingIssues' => $this->hasBlockingIssues(),
        ];
    }
}

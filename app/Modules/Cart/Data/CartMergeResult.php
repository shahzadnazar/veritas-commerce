<?php

declare(strict_types=1);

namespace App\Modules\Cart\Data;

/**
 * What happened when a signing-in customer's two carts became one.
 *
 * A merge is not a silent operation. A line can be dropped because the
 * seller was suspended while the customer was away, and a combined
 * quantity can exceed what is left in stock — §12 forbids resolving
 * either by quietly overselling, so both come back as issues the cart
 * page shows and the customer can act on.
 */
final readonly class CartMergeResult
{
    /**
     * @param  array<int, CartIssue>  $issues
     */
    public function __construct(
        /** Lines that existed only in the anonymous cart and were carried over. */
        public int $moved = 0,
        /** Lines that existed in both, whose quantities were combined. */
        public int $combined = 0,
        /** Lines that could not be carried over at all. */
        public int $dropped = 0,
        public array $issues = [],
    ) {}

    public static function nothing(): self
    {
        return new self;
    }

    public function changedAnything(): bool
    {
        return $this->moved > 0 || $this->combined > 0 || $this->dropped > 0;
    }

    /** @return array<int, CartIssue> */
    public function blockingIssues(): array
    {
        return array_values(array_filter($this->issues, static fn (CartIssue $i): bool => $i->isBlocking()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'moved' => $this->moved,
            'combined' => $this->combined,
            'dropped' => $this->dropped,
            'issues' => array_map(static fn (CartIssue $i): array => $i->toArray(), $this->issues),
        ];
    }
}

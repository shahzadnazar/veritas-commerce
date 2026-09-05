<?php

declare(strict_types=1);

namespace App\Support\Performance;

use InvalidArgumentException;

/**
 * How big the generated dataset is, in one place.
 *
 * Two profiles, and the difference between them is not cosmetic. `phase1`
 * is the shape the launch is planned for and the only size a query plan
 * may be judged on: PostgreSQL chooses a sequential scan over a table of
 * four hundred rows no matter how good the index is, so a plan captured
 * against a small dataset says nothing about production. `small` exists
 * so the seeder itself can be tested in seconds without asserting
 * anything about plans.
 *
 * Every count is derived from the four headline numbers rather than
 * written out, so the two profiles differ in scale and not in shape — the
 * skew a plan depends on survives the shrink.
 */
final class PerformanceScale
{
    private function __construct(
        public readonly string $name,
        public readonly int $sellers,
        public readonly int $users,
        public readonly int $products,
        public readonly int $orders,
        public readonly int $events,
    ) {}

    public static function named(string $name): self
    {
        return match ($name) {
            'phase1' => new self('phase1', sellers: 300, users: 5_000, products: 10_000, orders: 20_000, events: 200_000),
            'small' => new self('small', sellers: 20, users: 200, products: 400, orders: 500, events: 2_000),
            default => throw new InvalidArgumentException("Unknown scale profile [{$name}]. Use phase1 or small."),
        };
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return ['phase1', 'small'];
    }

    public function categories(): int
    {
        return max(20, min(200, intdiv($this->products, 50)));
    }

    public function brands(): int
    {
        return max(10, intdiv($this->products, 20));
    }

    /**
     * The products every seller competes over.
     *
     * A marketplace is not a uniform grid: a few hundred items carry
     * twenty offers each and the long tail carries one or two. That
     * bimodal shape is the whole reason `offers.product_id` statistics
     * matter, so it is generated deliberately rather than emerging from
     * a uniform draw.
     */
    public function hotProducts(): int
    {
        return max(4, intdiv($this->products * 3, 100));
    }

    /** Sellers with a catalogue in the thousands. */
    public function largeSellers(): int
    {
        return max(1, intdiv($this->sellers, 30));
    }

    /** Sellers with a catalogue in the hundreds. */
    public function mediumSellers(): int
    {
        return max(2, intdiv($this->sellers, 6));
    }

    public function largeSellerOffers(): int
    {
        return max(4, intdiv($this->products * 8, 100));
    }

    public function mediumSellerOffers(): int
    {
        return max(3, intdiv($this->products * 2, 100));
    }

    public function smallSellerOffers(): int
    {
        return max(2, intdiv($this->products * 3, 1000));
    }

    /** How many of a seller's offers land on a contested product. */
    public function hotOffersPerSeller(): int
    {
        return max(1, intdiv($this->hotProducts(), 15));
    }

    public function reviews(): int
    {
        return intdiv($this->orders * 3, 4);
    }

    public function payoutRequests(): int
    {
        return max(2, intdiv($this->orders, 10));
    }

    /**
     * The stride a seller walks through the uncontested catalogue.
     *
     * Has to be coprime to the width of that band, or a seller with
     * enough offers wraps around onto a product it already lists and the
     * unique index rejects the whole statement. Computed rather than
     * hard-coded because the band width moves with the profile, and a
     * stride that happens to work at ten thousand products is not a
     * stride that works at a hundred thousand.
     */
    public function coldStep(): int
    {
        $width = max(1, $this->products - $this->hotProducts());

        for ($candidate = 7; $candidate < $width; $candidate++) {
            if (self::coprime($candidate, $width)) {
                return $candidate;
            }
        }

        return 1;
    }

    public static function coprime(int $a, int $b): bool
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 1;
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return [
            'sellers' => $this->sellers,
            'users' => $this->users,
            'categories' => $this->categories(),
            'brands' => $this->brands(),
            'products' => $this->products,
            'orders' => $this->orders,
            'events' => $this->events,
        ];
    }
}

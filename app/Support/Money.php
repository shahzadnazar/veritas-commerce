<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is always an integer count of minor units plus a currency.
 *
 * There is no float anywhere in this class and none may be introduced: a
 * fraction of a cent lost in a commission split is unrecoverable, and the
 * ledger invariants in tests/Invariants depend on exact arithmetic.
 */
final readonly class Money
{
    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function of(int $minor, string $currency = 'USD'): self
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('Money cannot be negative; use a signed ledger entry instead.');
        }

        return new self($minor, strtoupper($currency));
    }

    public static function zero(string $currency = 'USD'): self
    {
        return new self(0, strtoupper($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function times(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    /**
     * Split off a percentage, returning [taken, remainder].
     *
     * The remainder is defined as (total - taken) rather than computed
     * independently, so the two parts always sum back to the original no
     * matter how the rate rounds. Half-up at the exact half.
     *
     * @param  string  $ratePercent  decimal string, e.g. "12.00" — never a float
     * @return array{0: self, 1: self}
     */
    public function splitPercentage(string $ratePercent): array
    {
        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $ratePercent)) {
            throw new InvalidArgumentException("Commission rate must be a decimal string like \"12.00\", got \"{$ratePercent}\".");
        }

        // Work in basis points to stay in integers: 12.00% -> 1200 bp.
        $basisPoints = (int) round(((float) $ratePercent) * 100);

        $scaled = $this->minor * $basisPoints;
        $taken = intdiv($scaled, 10_000);

        if (($scaled % 10_000) * 2 >= 10_000) {
            $taken++;
        }

        return [
            new self($taken, $this->currency),
            new self($this->minor - $taken, $this->currency),
        ];
    }

    public function format(): string
    {
        return sprintf('%s%s%s', $this->currency === 'USD' ? '$' : '', number_format(intdiv($this->minor, 100)), '.'.str_pad((string) ($this->minor % 100), 2, '0', STR_PAD_LEFT));
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}

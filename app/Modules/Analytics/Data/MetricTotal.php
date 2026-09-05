<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use App\Support\Money;

/**
 * One headline number, with the same number from the previous window.
 *
 * A total with nothing to compare it to is a number somebody has to
 * remember last month's value to interpret. Carrying the comparison here
 * means every card on every dashboard states its change the same way, and
 * that "no previous data" is a distinct answer from "no change" — the
 * difference between a new marketplace and a dead one.
 */
final readonly class MetricTotal
{
    public function __construct(
        public string $key,
        public string $label,
        public int $value,
        public ?int $previous = null,
        public bool $isMoney = false,
        public ?string $currency = null,
    ) {}

    /**
     * Percentage change, or null when there is nothing to compare.
     *
     * Null rather than zero when the previous window was empty: growing
     * from nothing is not "0% change", and it is not infinite growth
     * either — it is a comparison that cannot be made.
     */
    public function changePercent(): ?float
    {
        if ($this->previous === null || $this->previous === 0) {
            return null;
        }

        return round((($this->value - $this->previous) / abs($this->previous)) * 100, 1);
    }

    public function formatted(): string
    {
        if (! $this->isMoney) {
            return number_format($this->value);
        }

        return Money::formatSigned(
            $this->value,
            $this->currency ?? (string) config('veritas.money.default_currency'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'formatted' => $this->formatted(),
            'previous' => $this->previous,
            'changePercent' => $this->changePercent(),
            'isMoney' => $this->isMoney,
            'currency' => $this->currency,
        ];
    }
}

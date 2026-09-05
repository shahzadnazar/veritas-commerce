<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

/**
 * One measure over a run of days, with no gaps.
 *
 * Gap-filling is the whole point. A projection only writes rows for days
 * that had something to record, so a chart built straight from the table
 * would silently join Friday to Monday and draw a line through a weekend
 * that never happened. Every series here carries one point per day in the
 * window, zero where the projection had nothing.
 */
final readonly class MetricSeries
{
    /**
     * @param  array<int, string>  $days  ISO dates, oldest first
     * @param  array<int, int>  $values  one per day, same order
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $days,
        public array $values,
        public bool $isMoney = false,
    ) {}

    /**
     * @param  array<int, string>  $days
     * @param  array<string, int>  $byDay
     */
    public static function fill(
        string $key,
        string $label,
        array $days,
        array $byDay,
        bool $isMoney = false,
    ): self {
        return new self(
            key: $key,
            label: $label,
            days: $days,
            values: array_map(static fn (string $day): int => $byDay[$day] ?? 0, $days),
            isMoney: $isMoney,
        );
    }

    public function total(): int
    {
        return array_sum($this->values);
    }

    public function peak(): int
    {
        return $this->values === [] ? 0 : max($this->values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'days' => $this->days,
            'values' => $this->values,
            'total' => $this->total(),
            'peak' => $this->peak(),
            'isMoney' => $this->isMoney,
        ];
    }
}

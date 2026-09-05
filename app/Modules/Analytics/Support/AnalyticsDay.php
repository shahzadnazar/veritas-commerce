<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * One day, unambiguously.
 *
 * §70, and the reason this is a class rather than two Carbon calls: a
 * "day" is a platform-timezone day whose boundaries are UTC instants.
 * Every projection needs both — the date to key the row by, and the
 * instants to filter timestamps with — and computing them separately in
 * four different rebuilds is how a day ends up 23 hours long in one table
 * and 25 in another.
 *
 * The browser's timezone is never consulted. Two admins in different
 * countries asking for the 3rd of March get the same 3rd of March.
 */
final readonly class AnalyticsDay
{
    private function __construct(
        public string $date,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    public static function of(string|Carbon|CarbonImmutable $day): self
    {
        $timezone = self::timezone();

        $local = $day instanceof Carbon || $day instanceof CarbonImmutable
            ? CarbonImmutable::parse($day)->setTimezone($timezone)
            : CarbonImmutable::parse($day, $timezone);

        $start = $local->startOfDay();

        return new self(
            date: $start->toDateString(),
            startsAt: $start->utc(),
            // Exclusive at the top: the last microsecond of a day is a
            // place bugs live, and "< midnight tomorrow" has no such edge.
            endsAt: $start->addDay()->utc(),
        );
    }

    public static function today(): self
    {
        return self::of(CarbonImmutable::now(self::timezone()));
    }

    /**
     * A run of days, oldest first.
     *
     * @return array<int, self>
     */
    public static function range(string|Carbon|CarbonImmutable $from, string|Carbon|CarbonImmutable $to): array
    {
        $start = self::of($from);
        $end = self::of($to);

        if ($end->date < $start->date) {
            return [];
        }

        $days = [];
        $cursor = CarbonImmutable::parse($start->date, self::timezone());

        while ($cursor->toDateString() <= $end->date) {
            $days[] = self::of($cursor);
            $cursor = $cursor->addDay();

            if (count($days) > 3_660) {
                // Ten years. A rebuild asked for more than that is a typo,
                // and running it would be a self-inflicted outage.
                break;
            }
        }

        return $days;
    }

    /**
     * The last N days ending today, oldest first.
     *
     * @return array<int, self>
     */
    public static function lastDays(int $days): array
    {
        $today = CarbonImmutable::now(self::timezone());

        return self::range($today->subDays(max(1, $days) - 1), $today);
    }

    public static function timezone(): string
    {
        $configured = config('veritas.identity.timezone');

        return is_string($configured) && $configured !== '' ? $configured : 'UTC';
    }
}

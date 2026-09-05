<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Enums;

use App\Modules\Analytics\Support\AnalyticsDay;
use Carbon\CarbonImmutable;

/**
 * The windows a dashboard offers.
 *
 * A fixed list rather than a free date range, on purpose: every one of
 * these is a period the projection has actually been built for, and a
 * dashboard that let somebody ask for an arbitrary range would answer
 * confidently with whatever days happened to exist.
 *
 * §70 applies: these are platform-timezone days, so "last 7 days" is the
 * same seven days for every reader.
 */
enum AnalyticsPeriod: string
{
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case Last90Days = 'last_90_days';

    public function days(): int
    {
        return match ($this) {
            self::Last7Days => 7,
            self::Last30Days => 30,
            self::Last90Days => 90,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Last7Days => 'Last 7 days',
            self::Last30Days => 'Last 30 days',
            self::Last90Days => 'Last 90 days',
        };
    }

    /** @return array<int, AnalyticsDay> oldest first */
    public function dayRange(): array
    {
        return AnalyticsDay::lastDays($this->days());
    }

    /**
     * The equally long window immediately before this one, for comparison.
     *
     * @return array<int, AnalyticsDay>
     */
    public function previousDayRange(): array
    {
        $current = $this->dayRange();

        if ($current === []) {
            return [];
        }

        $earliest = $current[0]->date;
        $end = CarbonImmutable::parse($earliest, AnalyticsDay::timezone())->subDay();

        return AnalyticsDay::range($end->subDays($this->days() - 1), $end);
    }

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Last30Days;
    }

    /** @return array<int, array<string, string>> */
    public static function options(): array
    {
        return array_map(
            static fn (self $period): array => ['value' => $period->value, 'label' => $period->label()],
            self::cases(),
        );
    }
}

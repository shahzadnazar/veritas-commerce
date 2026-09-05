<?php

declare(strict_types=1);

namespace App\Support\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "This is still running", written where an outage cannot hide it.
 *
 * Two callers and one reader: the scheduler records a beat whenever a
 * scheduled task finishes, and `ops:queue-health` and
 * `app:production-check` ask how long ago that was.
 *
 * Recording never throws. A heartbeat that could fail a scheduled task
 * would be a monitoring system that causes outages, which is the wrong
 * way round — if the write fails, the beat goes stale and the staleness
 * is itself the signal.
 */
final class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    private const TABLE = 'operational_heartbeats';

    public static function record(string $name, ?string $note = null): void
    {
        try {
            DB::table(self::TABLE)->upsert(
                [[
                    'name' => $name,
                    'ran_at' => now(),
                    'note' => $note === null ? null : mb_substr($note, 0, 255),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['name'],
                ['ran_at', 'note', 'updated_at'],
            );
        } catch (Throwable) {
            // Never the reason a scheduled task fails. Staleness is the
            // signal; an exception here would be a monitoring system that
            // causes the outage it reports.
        }
    }

    /** When this last beat, or null if it never has. */
    public static function lastSeen(string $name): ?Carbon
    {
        try {
            $value = DB::table(self::TABLE)->where('name', $name)->value('ran_at');
        } catch (Throwable) {
            return null;
        }

        return $value === null ? null : Carbon::parse((string) $value);
    }

    /** How many minutes ago, or null if it has never run. */
    public static function minutesSince(string $name): ?int
    {
        $seen = self::lastSeen($name);

        return $seen === null ? null : (int) $seen->diffInMinutes(now());
    }
}

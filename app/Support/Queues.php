<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The queues, named in one place.
 *
 * Separation exists so one slow class of work cannot starve another: a
 * burst of image derivatives must not delay a payment webhook, and a
 * search reindex must not delay a seller's approval email. Each queue gets
 * its own worker pool in config/horizon.php, sized for its latency budget
 * rather than its volume.
 *
 * A job that does not name a queue lands on `default`, which is fine for
 * work that is neither urgent nor slow.
 */
final class Queues
{
    /** Money and state transitions: never queued behind anything. */
    public const CRITICAL = 'critical';

    /** Mail and notifications — a person is waiting, but not on a page. */
    public const EMAILS = 'emails';

    /** Image processing: slow by nature, and nothing waits on it. */
    public const MEDIA = 'media';

    /** Catalogue projections and derived data after a moderation decision. */
    public const CATALOGUE = 'catalogue';

    /** Search index writes, which may lag without anyone noticing. */
    public const SEARCH = 'search';

    public const DEFAULT = 'default';

    /**
     * Every queue, highest priority first.
     *
     * The order is the order a worker should drain them in, and the order
     * the Horizon supervisors are declared in.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::CRITICAL,
            self::EMAILS,
            self::CATALOGUE,
            self::DEFAULT,
            self::SEARCH,
            self::MEDIA,
        ];
    }
}

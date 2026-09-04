<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;

/**
 * What happened to one inbound provider event.
 *
 * `Ignored` is a real outcome, not a failure: a provider sends many event
 * types, and one this platform has no handler for is processed correctly by
 * being recorded and left alone. Distinguishing it from `Failed` is what
 * keeps the operator's "needs attention" list meaningful.
 */
enum ProviderEventStatus: string implements HasStatusTone
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function needsAttention(): bool
    {
        return $this === self::Failed;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Processed => StatusTone::Neutral,
            self::Received => StatusTone::Pending,
            self::Ignored => StatusTone::Inactive,
            self::Failed => StatusTone::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processed => 'Processed',
            self::Ignored => 'Not applicable',
            self::Failed => 'Failed',
        };
    }
}

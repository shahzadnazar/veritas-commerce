<?php

declare(strict_types=1);

namespace App\Modules\Audit\Actions;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Request;

/**
 * The single entry point to the audit trail.
 *
 * Two rules hold for every call. First, the request context is captured
 * here rather than passed in, so a caller cannot forget it. Second, values
 * are scrubbed before they are written: an audit record must never become
 * the place a secret leaks, and the cost of that mistake is far higher
 * than the cost of losing a field from a log line.
 */
final class RecordAuditEvent
{
    /**
     * Keys whose values are never written, whatever a caller passes.
     *
     * Matched as substrings, so `two_factor_secret`, `password_hash` and
     * `recovery_code` are all caught without enumerating every variant.
     */
    public const REDACTED_KEYS = [
        'password', 'secret', 'token', 'recovery_code', 'code_hash',
        'two_factor', 'remember_token', 'api_key', 'authorization',
    ];

    /** @param  array<string, mixed>|null  $changes */
    public function __invoke(
        string $action,
        string $actorType,
        ?int $actorId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $changes = null,
        ?string $reason = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'changes' => $changes === null ? null : self::redact($changes),
            'reason' => $reason,
            'ip_address' => Request::hasSession() || app()->runningInConsole() ? Request::ip() : null,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Recursively replaces sensitive values with a marker.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function redact(array $changes): array
    {
        $clean = [];

        foreach ($changes as $key => $value) {
            $lowered = strtolower((string) $key);

            foreach (self::REDACTED_KEYS as $sensitive) {
                if (str_contains($lowered, $sensitive)) {
                    $clean[$key] = '[redacted]';

                    continue 2;
                }
            }

            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }
}

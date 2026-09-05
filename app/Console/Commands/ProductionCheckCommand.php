<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Diagnostics\Check;
use App\Support\Diagnostics\ProductionReadiness;
use Illuminate\Console\Command;

/**
 * `app:production-check` — is this deployment safe to take money on?
 *
 * Exits non-zero on any failure, so a deployment pipeline can gate on it
 * (§126). Exits zero on warnings: a warning is something an operator
 * should read before launch, not something that makes the configuration
 * unsafe, and a check that blocked on every preference would be a check
 * people learn to skip.
 *
 * It never prints a secret. A key is reported as "set, 107 characters",
 * a connection failure as the exception class and a redacted first line.
 * The whole point is that this is safe to run — and safe to log — in the
 * environment that holds the credentials.
 *
 * `--json` for automation, which gets the same data without the box
 * drawing.
 */
final class ProductionCheckCommand extends Command
{
    protected $signature = 'app:production-check
        {--json : Emit the result as JSON for a deployment pipeline}';

    protected $description = 'Verify this deployment is configured safely enough to serve real customers.';

    public function handle(ProductionReadiness $readiness): int
    {
        $checks = $readiness->all();

        $failures = array_values(array_filter($checks, static fn (Check $check): bool => $check->isFailure()));
        $warnings = array_values(array_filter($checks, static fn (Check $check): bool => $check->status === Check::WARN));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $failures === [],
                'failures' => count($failures),
                'warnings' => count($warnings),
                'checks' => array_map(static fn (Check $check): array => $check->toArray(), $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        $group = null;

        foreach ($checks as $check) {
            if ($check->group !== $group) {
                $group = $check->group;
                $this->newLine();
                $this->line('<options=bold>'.strtoupper($group).'</>');
            }

            $this->line(sprintf(
                '  %s %-26s %s',
                $this->marker($check->status),
                $check->name,
                $check->detail,
            ));

            if ($check->remedy !== null) {
                $this->line('       <fg=gray>'.$check->remedy.'</>');
            }
        }

        $this->newLine();

        if ($failures !== []) {
            $this->error(sprintf(
                '%d check(s) failed. This deployment must not serve real customers.',
                count($failures),
            ));

            return self::FAILURE;
        }

        if ($warnings !== []) {
            $this->warn(sprintf('Configuration is safe. %d warning(s) to read before launch.', count($warnings)));

            return self::SUCCESS;
        }

        $this->info('Every check passed.');

        return self::SUCCESS;
    }

    private function marker(string $status): string
    {
        return match ($status) {
            Check::PASS => '<fg=green>PASS</>',
            Check::WARN => '<fg=yellow>WARN</>',
            Check::FAIL => '<fg=red>FAIL</>',
            default => '<fg=gray>SKIP</>',
        };
    }
}

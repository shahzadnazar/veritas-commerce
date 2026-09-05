<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Diagnostics\Check;
use App\Support\Diagnostics\ProductionReadiness;
use Illuminate\Console\Command;

/**
 * `app:pre-deploy` — can this release be deployed at all?
 *
 * The narrower sibling of `app:production-check`, meant to run *before*
 * traffic is cut over: it asks only whether the infrastructure and
 * artifacts are in place, and deliberately does not judge business
 * configuration.
 *
 * The distinction matters operationally. A missing SSR bundle or an
 * unreachable database means the deploy will not work and should stop.
 * A support email still set to the shipped placeholder is a launch
 * question, not a deploy question — blocking a hotfix on it would teach
 * people to pass `--force`.
 *
 * §127: nothing here mutates. No migration is run, no cache is written,
 * no job is dispatched and no commerce or finance row is touched.
 */
final class PreDeployCheckCommand extends Command
{
    protected $signature = 'app:pre-deploy {--json : Emit the result as JSON}';

    protected $description = 'Non-mutating check that this release can be deployed: config, connectivity, migrations, artifacts.';

    /** The groups a deploy depends on. Business settings are not among them. */
    private const DEPLOY_GROUPS = ['application', 'session', 'database', 'redis', 'queue', 'cache', 'storage', 'frontend'];

    public function handle(ProductionReadiness $readiness): int
    {
        $checks = array_values(array_filter(
            $readiness->all(),
            static fn (Check $check): bool => in_array($check->group, self::DEPLOY_GROUPS, true),
        ));

        $failures = array_values(array_filter($checks, static fn (Check $check): bool => $check->isFailure()));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $failures === [],
                'failures' => count($failures),
                'checks' => array_map(static fn (Check $check): array => $check->toArray(), $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $check) {
            if ($check->isFailure()) {
                $this->line(sprintf('  <fg=red>FAIL</> %-26s %s', $check->name, $check->detail));
            }
        }

        if ($failures !== []) {
            $this->error(count($failures).' deploy prerequisite(s) not met.');

            return self::FAILURE;
        }

        $this->info('Deploy prerequisites met: configuration, connectivity, migrations and build artifacts.');
        $this->line('<fg=gray>Business configuration is not checked here — run app:production-check for that.</>');

        return self::SUCCESS;
    }
}

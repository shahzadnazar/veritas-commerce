<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Operations\Heartbeat;
use App\Support\Queues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * `ops:queue-health` — is the background half of the system running?
 *
 * Deliberately not part of `/health/ready`. Readiness answers "should
 * this container receive HTTP traffic", and a thousand queued emails is
 * not a reason to stop serving pages — taking every replica out of the
 * load balancer because a mail provider is slow would turn a backlog
 * into an outage. Worker health and request health are different
 * questions with different consequences, and mixing them is how a minor
 * degradation becomes a major one.
 *
 * So this is an operator's command and a monitoring target: it exits
 * non-zero when something needs a person, and prints what.
 *
 * The three things that go wrong, in the order they hurt:
 *
 *   The scheduler stops. Nothing loud happens. Earnings stay in
 *   `clearing`, expired holds keep their stock, and the first symptom is
 *   a seller asking why their money has not moved — days later.
 *
 *   Workers stop. The queue grows, which is survivable, and then the
 *   payments queue grows, which is not: those are customers whose orders
 *   are not being finalised.
 *
 *   Jobs fail permanently. They are in `failed_jobs`, which is only
 *   useful if somebody looks.
 */
final class QueueHealthCommand extends Command
{
    protected $signature = 'ops:queue-health
        {--scheduler-minutes=15 : How long the scheduler may be silent before it counts as stopped}
        {--backlog=500 : Jobs on one queue before it counts as a backlog}
        {--payments-backlog=25 : The same for the payments queue, which holds money}
        {--json : Emit the result for a monitoring agent}';

    protected $description = 'Report scheduler, worker and failed-job health for an operator';

    public function handle(): int
    {
        $problems = [];
        $report = [];

        // ── The scheduler ───────────────────────────────────────────
        $silentFor = Heartbeat::minutesSince(Heartbeat::SCHEDULER);
        $limit = max(1, (int) $this->option('scheduler-minutes'));

        $report['scheduler'] = ['minutes_since_last_task' => $silentFor];

        if ($silentFor === null) {
            $problems[] = 'The scheduler has never recorded a completed task.';
        } elseif ($silentFor > $limit) {
            $problems[] = sprintf(
                'The scheduler has not completed a task for %d minutes. Earnings will not clear and expired holds will not be released.',
                $silentFor,
            );
        }

        // ── Workers ─────────────────────────────────────────────────
        $report['horizon'] = ['running' => $this->horizonIsRunning()];

        if ($report['horizon']['running'] === false) {
            $problems[] = 'No Horizon master supervisor is reporting. Nothing is draining the queues.';
        }

        $report['queues'] = [];

        foreach (Queues::all() as $queue) {
            $size = $this->depthOf($queue);
            $report['queues'][$queue] = $size;

            if ($size === null) {
                $problems[] = "Queue depth for {$queue} could not be read.";

                continue;
            }

            $threshold = $queue === Queues::PAYMENTS
                ? max(1, (int) $this->option('payments-backlog'))
                : max(1, (int) $this->option('backlog'));

            if ($size > $threshold) {
                $problems[] = sprintf('%s has %s job(s) waiting.', $queue, number_format($size));
            }
        }

        // ── Failures ────────────────────────────────────────────────
        $failed = $this->failedJobs();
        $report['failed_jobs'] = $failed;

        if ($failed['total'] > 0) {
            $problems[] = sprintf(
                '%d failed job(s), oldest %s. Inspect with: php artisan queue:failed',
                $failed['total'],
                $failed['oldest'] ?? 'unknown',
            );
        }

        // ── Stranded provider events ────────────────────────────────
        $stranded = $this->strandedProviderEvents();
        $report['stranded_provider_events'] = $stranded;

        if ($stranded > 0) {
            $problems[] = sprintf(
                '%d provider webhook event(s) recorded but never processed. Run: php artisan payments:replay-stranded',
                $stranded,
            );
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['ok' => $problems === [], 'problems' => $problems] + $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->renderTable($report);

        if ($problems === []) {
            $this->info('Queues, workers and the scheduler all look healthy.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($problems as $problem) {
            $this->line('  <fg=red>needs attention</> '.$problem);
        }

        return self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function renderTable(array $report): void
    {
        /** @var array{minutes_since_last_task: int|null} $scheduler */
        $scheduler = $report['scheduler'];
        /** @var array{running: bool|null} $horizon */
        $horizon = $report['horizon'];
        /** @var array<string, int|null> $queues */
        $queues = $report['queues'];
        /** @var array{total: int, oldest: string|null} $failed */
        $failed = $report['failed_jobs'];

        $rows = [
            ['scheduler', $scheduler['minutes_since_last_task'] === null
                ? 'never run'
                : $scheduler['minutes_since_last_task'].' min since last task'],
            ['horizon', match ($horizon['running']) {
                true => 'running',
                false => 'not running',
                default => 'unknown',
            }],
        ];

        foreach ($queues as $queue => $depth) {
            $rows[] = ['queue: '.$queue, $depth === null ? 'unreadable' : number_format($depth).' waiting'];
        }

        $rows[] = ['failed jobs', $failed['total'].($failed['oldest'] === null ? '' : ', oldest '.$failed['oldest'])];
        $rows[] = ['stranded events', (string) $report['stranded_provider_events']];

        $this->table(['Component', 'State'], $rows);
    }

    /** null when Horizon cannot be asked, which is not the same as "stopped". */
    private function horizonIsRunning(): ?bool
    {
        try {
            /** @var MasterSupervisorRepository $masters */
            $masters = app(MasterSupervisorRepository::class);

            return $masters->all() !== [];
        } catch (Throwable) {
            return null;
        }
    }

    private function depthOf(string $queue): ?int
    {
        try {
            return (int) Queue::connection('redis')->size($queue);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{total: int, oldest: string|null} */
    private function failedJobs(): array
    {
        try {
            $total = (int) DB::table('failed_jobs')->count();
            $oldest = $total === 0 ? null : (string) DB::table('failed_jobs')->min('failed_at');
        } catch (Throwable) {
            return ['total' => 0, 'oldest' => null];
        }

        return ['total' => $total, 'oldest' => $oldest];
    }

    private function strandedProviderEvents(): int
    {
        try {
            return (int) DB::table('provider_webhook_events')
                ->where('status', 'received')
                ->where('received_at', '<', now()->subMinutes(15))
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }
}

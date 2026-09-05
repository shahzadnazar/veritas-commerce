<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Retry policy, asserted rather than assumed.
 *
 * Two failures are being prevented, and they pull in opposite
 * directions. A job with no bound retries a business-rule violation
 * until somebody notices, which for a payment event means an argument
 * with the provider replayed every few seconds for a week. A job with no
 * backoff retries a provider that has just fallen over as fast as the
 * worker pool allows, which is how a brief provider outage becomes a
 * self-inflicted denial of service against it the moment it recovers.
 *
 * The specific numbers are the application's business and change with
 * experience. What must not change is that each one is a decision
 * somebody made.
 */
final class QueueRetryPolicyTest extends TestCase
{
    /** Queues whose work is money, and which therefore get more patience. */
    private const MONEY_QUEUES = [Queues::PAYMENTS];

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function queuedJobs(): array
    {
        $cases = [];

        foreach (self::jobClasses() as $class) {
            $cases[class_basename($class)] = [$class];
        }

        return $cases;
    }

    /** @return array<int, class-string> */
    private static function jobClasses(): array
    {
        $found = [];
        $root = dirname(__DIR__, 2).'/app/Modules';

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (! str_contains($file->getPathname(), '/Jobs/')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) !== 1) {
                continue;
            }

            /** @var class-string $class */
            $class = trim($namespace[1]).'\\'.$file->getBasename('.php');

            if (class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The discovery works.
     *
     * A scan that found nothing would make every assertion below pass by
     * vacuum, which is the failure mode of every reflection-driven test.
     */
    #[Test]
    public function there_are_queued_jobs_to_check(): void
    {
        // A discovery bug that found nothing would make every test below
        // pass by vacuum.
        $this->assertGreaterThanOrEqual(5, count(self::jobClasses()));
    }

    /**
     * Every job stops.
     *
     * `$tries = 0` means "retry until `retryUntil` says otherwise", and
     * none of these declare one — so zero would mean forever.
     */
    /** @param class-string $class */
    #[Test]
    #[DataProvider('queuedJobs')]
    public function every_queued_job_declares_a_bounded_number_of_attempts(string $class): void
    {
        $reflection = new ReflectionClass($class);

        $this->assertTrue(
            $reflection->hasProperty('tries') || $reflection->hasMethod('retryUntil'),
            "{$class} declares neither \$tries nor retryUntil(); it would retry on the worker's default forever.",
        );

        if (! $reflection->hasProperty('tries')) {
            return;
        }

        $tries = $reflection->getProperty('tries')->getDefaultValue();

        $this->assertIsInt($tries);
        $this->assertGreaterThan(0, $tries, "{$class} sets \$tries to 0, which means unlimited.");
        $this->assertLessThanOrEqual(
            10,
            $tries,
            "{$class} retries {$tries} times. Past a handful, a retry is a way of not noticing.",
        );
    }

    /**
     * Work that talks to a payment provider backs off.
     *
     * A fixed short retry against a provider that has just failed is a
     * stampede: every worker returns at the same moment the provider
     * comes back, and takes it down again. The backoff has to grow, and
     * it has to reach far enough that the last attempt lands after a
     * human-scale outage rather than inside it.
     */
    /** @param class-string $class */
    #[Test]
    #[DataProvider('queuedJobs')]
    public function work_that_touches_a_payment_provider_backs_off_progressively(string $class): void
    {
        $reflection = new ReflectionClass($class);

        $job = $reflection->newInstanceWithoutConstructor();

        if (! $this->runsOnAMoneyQueue($class)) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertTrue($reflection->hasProperty('backoff'), "{$class} runs on a money queue with no backoff.");

        $backoff = $reflection->getProperty('backoff')->getDefaultValue();

        $this->assertIsArray($backoff);
        $this->assertGreaterThanOrEqual(3, count($backoff), "{$class} backs off in fewer than three steps.");

        $previous = 0;

        foreach ($backoff as $wait) {
            $this->assertIsInt($wait);
            $this->assertGreaterThan($previous, $wait, "{$class} has a backoff that does not grow: it is a stampede.");
            $previous = $wait;
        }

        $this->assertGreaterThanOrEqual(
            300,
            $previous,
            "{$class} gives up waiting after {$previous}s. A provider outage lasts longer than that.",
        );

        unset($job);
    }

    /**
     * Whether this job's constructor puts it on a queue that carries money.
     *
     * @param  class-string  $class
     */
    private function runsOnAMoneyQueue(string $class): bool
    {
        $source = (string) file_get_contents((new ReflectionClass($class))->getFileName() ?: '');

        foreach (self::MONEY_QUEUES as $queue) {
            if (str_contains($source, 'Queues::'.strtoupper($queue))) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Payouts\Http\Controllers\SellerFinanceController;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * A controller may not remember anything between requests.
 *
 * This exists because of a defect M9 found and reproduced. The seller
 * finance controller memoised the acting seller's membership into an
 * instance property — sensible-looking, and it saved four queries. But a
 * controller instance does not live for one request: Laravel caches it on
 * the Route object, and Routes live as long as the application does.
 *
 * Under php-fpm the application dies with the request, so nothing was ever
 * observed. Under any runtime that keeps it alive between requests —
 * Octane, RoadRunner, Swoole — the second seller to load the payouts page
 * was served the first seller's membership and shown their payouts. That
 * was demonstrated in-process, two requests, one leak.
 *
 * The rule that prevents the whole class of bug is simpler than reasoning
 * about which runtime is deployed: a controller holds injected
 * collaborators and nothing else, so every instance property is readonly.
 * Anything a request needs is resolved during that request.
 */
final class ControllerStateTest extends TestCase
{
    #[Test]
    public function no_controller_holds_mutable_state(): void
    {
        $offenders = [];

        foreach ($this->controllers() as $controller) {
            $reflection = new ReflectionClass($controller);

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $controller) {
                    continue;
                }

                if ($property->isStatic()) {
                    $offenders[] = $controller.'::$'.$property->getName().' (static)';

                    continue;
                }

                if (! $property->isReadOnly()) {
                    $offenders[] = $controller.'::$'.$property->getName().' (not readonly)';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A controller instance outlives the request that created it, so anything it remembers can be\n"
                ."served to the next caller — who may be a different tenant. Inject it readonly, or resolve\n"
                ."it inside the request.\n\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function the_scan_actually_found_the_controllers(): void
    {
        // A rule that silently matched nothing would pass forever.
        $controllers = $this->controllers();

        $this->assertGreaterThan(20, count($controllers));

        $this->assertContains(
            SellerFinanceController::class,
            $controllers,
            'The controller whose memoised membership caused this test must be covered by it.',
        );
    }

    /** @return array<int, class-string> */
    private function controllers(): array
    {
        $found = [];

        foreach ((new Finder)->files()->in(app_path())->path('Http/Controllers')->name('*.php') as $file) {
            $class = $this->classFor($file);

            if ($class !== null && class_exists($class)) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }

    /** @return class-string|null */
    private function classFor(SplFileInfo $file): ?string
    {
        $source = (string) file_get_contents($file->getRealPath());

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }

        if (preg_match('/^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/m', $source, $name) !== 1) {
            return null;
        }

        /** @var class-string $class */
        $class = trim($namespace[1]).'\\'.$name[1];

        return $class;
    }
}

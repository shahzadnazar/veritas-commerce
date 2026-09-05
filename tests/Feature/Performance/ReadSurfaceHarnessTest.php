<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Modules\Commission\Models\CommissionRule;
use App\Support\Performance\ReadSurfaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\Feature\Security\BuildsTenantScenarios;
use Tests\TestCase;
use Throwable;

/**
 * The plan harness has to still work, or the audit quietly stops being an
 * audit.
 *
 * `ReadSurfaces` names three dozen query objects and calls them the way
 * the controllers do. Nothing in the ordinary suite runs it — it is
 * pointed at a scratch database and driven by hand — so a renamed method,
 * a changed signature or a query object that gained a constructor
 * argument would break it silently, and would be discovered the next time
 * somebody needed a measurement, which is exactly when they have least
 * patience for it.
 *
 * This runs every surface against the test database. The numbers are
 * meaningless at that size and are not asserted; what is asserted is that
 * each surface still runs. Query *counts* for these screens are already
 * pinned where they matter, by the bounded-read tests in the cart,
 * catalogue, discovery, fulfilment, order and finance suites.
 */
final class ReadSurfaceHarnessTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsTenantScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The order builders price their lines through the commission
        // domain, which refuses to guess a rate.
        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    #[Test]
    public function every_measured_surface_still_runs(): void
    {
        $this->tenantWorld('harness');

        $failures = [];

        foreach (app(ReadSurfaces::class)->all() as $surface) {
            try {
                ($surface['run'])();
            } catch (Throwable $e) {
                $failures[] = sprintf('%s / %s: %s', $surface['group'], $surface['name'], $e->getMessage());
            }
        }

        $this->assertSame([], $failures, 'A read surface the plan audit measures no longer runs.');
    }

    #[Test]
    public function the_surfaces_are_named_once_each(): void
    {
        $this->tenantWorld('harness');

        $names = array_map(
            static fn (array $surface): string => $surface['group'].'/'.$surface['name'],
            app(ReadSurfaces::class)->all(),
        );

        // Duplicated names would silently collapse two rows of the report
        // into one, and the one that survived would be whichever ran last.
        $this->assertSame(array_values(array_unique($names)), $names);
        $this->assertGreaterThan(20, count($names));
    }
}

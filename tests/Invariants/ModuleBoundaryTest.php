<?php

declare(strict_types=1);

namespace Tests\Invariants;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Module boundaries, enforced in the test suite.
 *
 * The architecture calls for Deptrac; it cannot be installed in this
 * environment (see docs/architecture/M0-REPORT.md), so the same rule is
 * asserted here instead — which has the side benefit of running in the same
 * command as everything else.
 *
 * The rule: a module may depend on another module's Contracts, Data, Enums
 * or Events, but never reach into its Models. Cross-module writes go
 * through an Action or an Event, so extracting a module later is mechanical
 * rather than archaeological.
 */
final class ModuleBoundaryTest extends TestCase
{
    /** Modules permitted to touch another module's models, and why. */
    private const ALLOWED_MODEL_CONSUMERS = [
        // The admin area is the one place that legitimately reads across
        // every module — it is the platform's own back office.
        'AdminPortal',
    ];

    #[Test]
    public function no_module_reaches_into_another_modules_models(): void
    {
        $violations = [];

        foreach ($this->modulePhpFiles() as $module => $file) {
            if (in_array($module, self::ALLOWED_MODEL_CONSUMERS, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            preg_match_all(
                '/^use\s+App\\\\Modules\\\\(\w+)\\\\Models\\\\(\w+);/m',
                $contents,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                [$statement, $importedModule, $class] = $match;

                if ($importedModule === $module) {
                    continue;
                }

                if ($this->isPermittedCrossModelUse($module, $importedModule)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s imports %s\'s model %s (%s)',
                    $module,
                    $importedModule,
                    $class,
                    str_replace(base_path().'/', '', $file),
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Cross-module model access:\n  - ".implode("\n  - ", $violations).
            "\n\nDepend on the other module's Contracts, Data, Enums or Events instead.",
        );
    }

    #[Test]
    public function the_shared_kernel_holds_no_business_meaning(): void
    {
        $forbidden = ['Order', 'Seller', 'Commission', 'Payout', 'Offer', 'Inventory'];

        foreach (glob(app_path('Support/*.php')) ?: [] as $file) {
            $name = basename($file, '.php');

            foreach ($forbidden as $word) {
                if (str_contains($name, $word) && $name !== 'StatusRegistry') {
                    $this->fail(
                        "app/Support/{$name}.php carries domain meaning. ".
                        'The shared kernel holds Money, identifiers and presentation only; move it into its module.'
                    );
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function every_module_directory_is_a_declared_module(): void
    {
        $expected = [
            'AdminPortal', 'Audit', 'Cart', 'Catalog', 'Commission', 'Customers',
            'Events', 'Fulfillment', 'Identity', 'Inventory', 'Ledger',
            'Notifications', 'Offers', 'Orders', 'Payments', 'Payouts',
            'Search', 'Sellers', 'Stores',
        ];

        $actual = array_map('basename', glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: []);
        sort($actual);

        $this->assertSame($expected, $actual, 'A module was added or removed without updating the architecture.');
    }

    /**
     * A handful of pairs are legitimately coupled at the model level because
     * one module owns a concept the other stores a foreign key to.
     */
    private function isPermittedCrossModelUse(string $module, string $importedModule): bool
    {
        $allowed = [
            // Tenancy: anything seller-owned resolves its owner.
            '*' => ['Sellers'],
            // A store is the public face of a seller account, and a
            // membership is by definition the join between a user and a
            // seller — both are the coupling, not a leak through it.
            'Sellers' => ['Stores', 'Identity'],
            'Offers' => ['Catalog', 'Inventory', 'Stores'],
            'Inventory' => ['Offers'],
            'Catalog' => ['Offers'],
            'Orders' => ['Identity'],
            'Payouts' => ['Ledger'],
            'Ledger' => ['Orders'],
            'Stores' => ['Sellers'],
        ];

        if (in_array($importedModule, $allowed['*'], true)) {
            return true;
        }

        return in_array($importedModule, $allowed[$module] ?? [], true);
    }

    /** @return iterable<string, string> module name => absolute file path */
    private function modulePhpFiles(): iterable
    {
        $root = app_path('Modules');

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($root.'/', '', $file->getPathname());
            $module = explode('/', $relative)[0];

            yield $module => $file->getPathname();
        }
    }
}

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
            'AdminPortal', 'Audit', 'Cart', 'Catalog', 'Checkout', 'Commission', 'Customers',
            'Events', 'Fulfillment', 'Identity', 'Inventory', 'Ledger',
            'Media', 'Notifications', 'Offers', 'Orders', 'Payments',
            'Payouts', 'Search', 'Sellers', 'Stores',
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
            /*
             * A cart line IS a seller offer — that is the M4 requirement,
             * not an implementation detail — and describing what is in a
             * cart means naming the product it points at. Both are reads
             * of upstream modules, the same direction Offers already reads
             * Catalog in. Identity for the same reason Orders has it: a
             * cart belongs to a customer.
             */
            'Cart' => ['Offers', 'Catalog', 'Identity'],
            /*
             * Checkout turns a cart into orders, so it touches everything
             * the transaction spans. It writes through the owning modules'
             * actions wherever one exists; the model reads here are for
             * validation and snapshotting, which is what a checkout is.
             */
            'Checkout' => ['Cart', 'Offers', 'Catalog', 'Orders', 'Inventory', 'Identity', 'Payments', 'Stores'],
            /*
             * Fulfilment made Orders read two more things. The seller
             * account is read to resolve that seller's clearing period —
             * an override on their account, or the platform's default —
             * and the ledger is read to move a delivered order's earnings
             * from pending to clearing. Both writes still go through the
             * owning module's action: PostLedgerEntry remains the only
             * thing that inserts a ledger row.
             */
            'Orders' => ['Identity', 'Offers', 'Catalog', 'Sellers', 'Ledger'],
            /*
             * Payment is the moment an order's money becomes real, so the
             * payment module reads the order it is settling and writes the
             * obligations that settlement creates. The Ledger read is the
             * idempotency check that stops a replayed event posting a
             * second earning; the write still goes through Ledger's own
             * PostLedgerEntry, which remains the only thing that inserts a
             * ledger row.
             */
            'Payments' => ['Orders', 'Ledger', 'Sellers'],
            /*
             * Payouts reads the ledger it is paid out of, and reads a
             * user to say who asked and who to tell. Identity is the same
             * coupling Orders and Payments already have: a payout belongs
             * to a person as well as to a store. Every write still goes
             * through the owning module's action — PostLedgerEntry
             * remains the only thing that inserts a ledger row.
             */
            'Payouts' => ['Ledger', 'Identity'],
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

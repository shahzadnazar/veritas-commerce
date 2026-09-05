<?php

declare(strict_types=1);

namespace Tests\Invariants;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * §2, enforced structurally: the insight layer reads, it never writes.
 *
 * Analytics and recommendations are allowed to know everything — orders,
 * payments, the ledger, the catalogue, behaviour — and allowed to change
 * nothing outside their own projections. A dashboard that could adjust a
 * seller's balance is not a dashboard, and the difference between the two
 * is not something a code review will catch reliably at three in the
 * morning, so it is asserted here instead.
 *
 * The rule is deliberately blunt: these modules may not import any model,
 * may not call a domain action from another module, and their raw table
 * writes must name one of their own projections. That leaves them exactly
 * one way to write anything — DB::table('one_of_our_projections') — and
 * the M8 rebuild tests prove at runtime that this is what actually
 * happens.
 */
final class AnalyticsBoundaryTest extends TestCase
{
    /** The modules that read the whole marketplace and own no truth. */
    private const INSIGHT_MODULES = ['Analytics', 'Recommendations'];

    /**
     * The tables these modules may write.
     *
     * Every one is derived: drop it, run the rebuild, and it comes back
     * identical. Nothing on this list is anybody's source of truth.
     */
    private const OWN_PROJECTIONS = [
        'product_popularity_scores',
        'product_associations',
        'daily_marketplace_metrics',
        'daily_product_metrics',
        'daily_seller_metrics',
        'daily_search_metrics',
    ];

    #[Test]
    public function the_insight_modules_import_no_models_at_all(): void
    {
        $violations = [];

        foreach ($this->insightFiles() as $file) {
            preg_match_all(
                '/^use\s+(App\\\\Modules\\\\\w+\\\\Models\\\\\w+);/m',
                (string) file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] as $imported) {
                $violations[] = $this->relative($file).' imports '.$imported;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Analytics and recommendations must read through queries, not models:\n  - ".
            implode("\n  - ", $violations).
            "\n\nA model brings save(), update() and delete() with it, and the point is that ".
            'those are unreachable from here.',
        );
    }

    /**
     * The one action the insight layer may call, and why.
     *
     * Recording that a shelf was shown or a card clicked is the insight
     * layer's own business: interaction_events is behavioural input, not
     * commerce truth, and §2's list — inventory, order totals, payments,
     * refunds, the seller ledger, clearing, payout reservations, payouts —
     * does not include it. Everything else stays forbidden, and the
     * allowance is spelled out here so adding a second one is a decision
     * somebody makes deliberately rather than a test that quietly stopped
     * failing.
     */
    private const ALLOWED_ACTIONS = [
        'App\\Modules\\Events\\Actions\\RecordInteraction',
    ];

    #[Test]
    public function the_insight_modules_call_no_other_modules_actions(): void
    {
        $violations = [];

        foreach ($this->insightFiles() as $file) {
            preg_match_all(
                '/^use\s+(App\\\\Modules\\\\(\w+)\\\\Actions\\\\\w+);/m',
                (string) file_get_contents($file),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [$statement, $imported, $module]) {
                if (in_array($module, self::INSIGHT_MODULES, true)) {
                    continue;
                }

                if (in_array($imported, self::ALLOWED_ACTIONS, true)) {
                    continue;
                }

                $violations[] = $this->relative($file).' imports '.$imported;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "An insight module reached for a domain action:\n  - ".implode("\n  - ", $violations).
            "\n\nAn action exists to change something. Analytics changes nothing but its own ".
            'behavioural input — see ALLOWED_ACTIONS.',
        );
    }

    #[Test]
    public function every_raw_write_names_one_of_their_own_projections(): void
    {
        $violations = [];

        foreach ($this->insightFiles() as $file) {
            $contents = (string) file_get_contents($file);

            // Any DB::table('x')-> chain that ends in a write verb.
            preg_match_all(
                "/DB::table\(\s*'([a-z_]+)'[^;]*?->\s*(insert|insertOrIgnore|update|updateOrInsert|upsert|delete|truncate|increment|decrement)\s*\(/s",
                $contents,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [$statement, $table, $verb]) {
                if (in_array($table, self::OWN_PROJECTIONS, true)) {
                    continue;
                }

                $violations[] = sprintf('%s: %s() on %s', $this->relative($file), $verb, $table);
            }
        }

        $this->assertSame(
            [],
            $violations,
            "An insight module wrote to a table it does not own:\n  - ".implode("\n  - ", $violations).
            "\n\n§2: analytics and recommendations are never authoritative for inventory, order ".
            'totals, payments, refunds, the seller ledger, clearing, payout reservations or payouts.',
        );
    }

    #[Test]
    public function the_one_permitted_action_is_behavioural_and_nothing_else(): void
    {
        $this->assertCount(
            1,
            self::ALLOWED_ACTIONS,
            'A second allowance was added. Every one of these is a hole in §2 and needs arguing for.',
        );

        foreach (self::ALLOWED_ACTIONS as $action) {
            $this->assertStringStartsWith(
                'App\\Modules\\Events\\Actions\\',
                $action,
                'Only the behavioural event module may be called from the insight layer.',
            );
        }
    }

    #[Test]
    public function the_insight_modules_never_open_a_transaction_over_foreign_data(): void
    {
        $forbidden = ['lockForUpdate', 'sharedLock'];
        $violations = [];

        foreach ($this->insightFiles() as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $violations[] = $this->relative($file).' uses '.$needle;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "A read-only module took a write lock:\n  - ".implode("\n  - ", $violations).
            "\n\nA rebuild that locks order rows can block checkout, which is a way for a ".
            'dashboard to take the marketplace down without ever writing a byte.',
        );
    }

    /**
     * The projection tables are named in one place.
     *
     * Without this, adding a projection and forgetting to list it above
     * would make the write test fail for the right reason but the wrong
     * one — and adding a *financial* table to the list would silently
     * widen what these modules may touch.
     */
    #[Test]
    public function the_owned_projection_list_holds_nothing_authoritative(): void
    {
        $forbidden = ['order', 'payment', 'refund', 'ledger', 'payout', 'inventory', 'offer', 'seller_account'];

        foreach (self::OWN_PROJECTIONS as $table) {
            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $table,
                    "{$table} looks like transactional truth and must not be writable from the insight layer.",
                );
            }

            $this->assertTrue(
                DB::getSchemaBuilder()->hasTable($table),
                "{$table} is listed as an owned projection but does not exist.",
            );
        }
    }

    /** @return array<int, string> */
    private function insightFiles(): array
    {
        $files = [];

        foreach (self::INSIGHT_MODULES as $module) {
            $root = app_path('Modules/'.$module);

            if (! is_dir($root)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}

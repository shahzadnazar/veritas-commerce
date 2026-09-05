<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §78 and §79 — the finance screens are bounded, and carry DTOs.
 *
 * The numbers below are ceilings, not targets, and every one was taken
 * with several rows present so a query per row would show up. The point is
 * not the exact figure but that adding a tenth payout does not add ten
 * queries — the N+1 that turns a finance dashboard into a timeout.
 */
final class FinanceReadSurfaceTest extends TestCase
{
    use BuildsSellerFinance;
    use RefreshDatabase;

    /** @return array{seller: SellerAccount, user: User, payout: PayoutRequest} */
    private function busyStore(): array
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();

        // Enough history that a per-row query would be obvious.
        foreach (range(1, 8) as $_) {
            $this->availableEarning($seller, 5_000);
        }

        $this->reversal($seller, 1_000);
        $this->clearingEarning($seller, 4_000);
        $this->pendingEarning($seller, 3_000);
        $this->destination($seller);

        return ['seller' => $seller, 'user' => $user, 'payout' => $this->requestPayout($seller, 20_000)];
    }

    /** @param callable(): mixed $work */
    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function the_position_is_two_queries_however_much_history_there_is(): void
    {
        ['seller' => $seller] = $this->busyStore();

        // One over the ledger, one over the allocations. Eleven entries
        // and an open payout do not change that.
        $count = $this->countQueries(fn () => app(GetSellerFinancialPosition::class)($seller->id));

        $this->assertSame(2, $count);
    }

    #[Test]
    public function positions_for_many_sellers_stay_at_two_queries(): void
    {
        $ids = [];

        foreach (range(1, 5) as $_) {
            ['seller' => $seller] = $this->busyStore();
            $ids[] = (int) $seller->id;
        }

        $count = $this->countQueries(
            fn () => app(GetSellerFinancialPosition::class)->forSellers($ids),
        );

        $this->assertSame(2, $count, 'Five stores, still two queries. §78.');
    }

    #[Test]
    public function the_seller_statement_is_bounded(): void
    {
        ['user' => $user] = $this->busyStore();

        $count = $this->countQueries(fn () => $this->actingAs($user)->get('/seller/earnings')->assertOk());

        $this->assertLessThanOrEqual(16, $count, "The statement took {$count} queries.");
    }

    #[Test]
    public function the_seller_payout_list_is_bounded(): void
    {
        ['user' => $user] = $this->busyStore();

        $count = $this->countQueries(fn () => $this->actingAs($user)->get('/seller/payouts')->assertOk());

        $this->assertLessThanOrEqual(16, $count, "The payout list took {$count} queries.");
    }

    #[Test]
    public function the_seller_payout_detail_is_bounded(): void
    {
        ['user' => $user, 'payout' => $payout] = $this->busyStore();

        $count = $this->countQueries(
            fn () => $this->actingAs($user)->get("/seller/payouts/{$payout->reference}")->assertOk(),
        );

        $this->assertLessThanOrEqual(15, $count, "The payout detail took {$count} queries.");
    }

    #[Test]
    public function the_admin_payout_queue_does_not_grow_with_the_number_of_payouts(): void
    {
        $admin = $this->makeAdmin(AdminRole::FinanceAdmin);

        $this->busyStore();

        $withOne = $this->countQueries(
            fn () => $this->actingAs($admin, 'admin')->get('/admin/payouts')->assertOk(),
        );

        foreach (range(1, 5) as $_) {
            $this->busyStore();
        }

        $withSix = $this->countQueries(
            fn () => $this->actingAs($admin, 'admin')->get('/admin/payouts')->assertOk(),
        );

        $this->assertSame(
            $withOne,
            $withSix,
            "One payout took {$withOne} queries and six took {$withSix}. That is a query per row.",
        );
    }

    #[Test]
    public function the_admin_payout_detail_is_bounded(): void
    {
        $admin = $this->makeAdmin(AdminRole::FinanceAdmin);
        ['payout' => $payout] = $this->busyStore();

        $count = $this->countQueries(
            fn () => $this->actingAs($admin, 'admin')->get("/admin/payouts/{$payout->reference}")->assertOk(),
        );

        $this->assertLessThanOrEqual(16, $count, "The payout detail took {$count} queries.");
    }

    #[Test]
    public function the_admin_seller_finance_page_is_bounded(): void
    {
        $admin = $this->makeAdmin(AdminRole::FinanceAdmin);
        ['seller' => $seller] = $this->busyStore();

        $count = $this->countQueries(
            fn () => $this->actingAs($admin, 'admin')
                ->get("/admin/sellers/{$seller->public_id}/finance")->assertOk(),
        );

        $this->assertLessThanOrEqual(18, $count, "The seller finance page took {$count} queries.");
    }

    #[Test]
    public function the_finance_dashboard_does_not_grow_with_the_number_of_sellers(): void
    {
        $admin = $this->makeAdmin(AdminRole::FinanceAdmin);

        $this->busyStore();

        $withOne = $this->countQueries(
            fn () => $this->actingAs($admin, 'admin')->get('/admin/finance')->assertOk(),
        );

        foreach (range(1, 5) as $_) {
            $this->busyStore();
        }

        $withSix = $this->countQueries(
            fn () => $this->actingAs($admin, 'admin')->get('/admin/finance')->assertOk(),
        );

        $this->assertSame($withOne, $withSix, 'Six stores must cost what one does.');
    }

    #[Test]
    public function no_eloquent_graph_reaches_the_browser(): void
    {
        ['user' => $user, 'payout' => $payout] = $this->busyStore();

        $props = $this->actingAs($user)
            ->get("/seller/payouts/{$payout->reference}")
            ->viewData('page')['props'];

        // §79: typed arrays, not models. A serialized relation would show
        // up as snake_case columns and foreign keys.
        $this->assertIsArray($props['payout']);
        $this->assertArrayNotHasKey('seller_account_id', $props['payout']);
        $this->assertArrayNotHasKey('payout_account_id', $props['payout']);
        $this->assertArrayHasKey('amountMinor', $props['payout']);
        $this->assertArrayHasKey('allocations', $props['payout']);

        foreach ($props['payout']['allocations'] as $allocation) {
            $this->assertArrayNotHasKey('seller_ledger_entry_id', $allocation);
            $this->assertArrayHasKey('amountMinor', $allocation);
            $this->assertArrayHasKey('currency', $allocation);
        }
    }

    #[Test]
    public function every_money_field_carries_minor_units_and_a_currency(): void
    {
        ['user' => $user] = $this->busyStore();

        $props = $this->actingAs($user)->get('/seller/earnings')->viewData('page')['props'];

        // §80: no floats anywhere, and the currency travels with them.
        foreach (['pendingMinor', 'clearingMinor', 'availableMinor', 'reservedMinor', 'withdrawableMinor'] as $key) {
            $this->assertIsInt($props['position'][$key], "{$key} must be an integer count of minor units.");
        }

        $this->assertSame('USD', $props['position']['currency']);
        $this->assertSame('USD', $props['eligibility']['currency']);

        foreach ($props['statement']['rows'] as $row) {
            $this->assertIsInt($row['amountMinor']);
            $this->assertIsInt($row['balanceAfterMinor']);
            $this->assertSame('USD', $row['currency']);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Actions\CompleteDeliveredSellerOrders;
use App\Modules\Payouts\Actions\ApprovePayout;
use App\Modules\Payouts\Actions\PostFinancialAdjustment;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Queries\BuildSellerStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * §35, §36 and §75 — the statement, and the balance beside it.
 *
 * The running balance is the one the ledger recorded when each row was
 * written, under the seller's lock, rather than one this query re-adds. So
 * two entries written in the same second cannot swap places on the way to
 * the screen, which is the failure §36 asks about.
 */
final class SellerStatementTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsSellerFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    #[Test]
    public function the_statement_comes_from_the_ledger_and_names_the_orders(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->destination($seller);

        $payout = $this->requestPayout($seller, 8_800);
        app(ApprovePayout::class)($payout, PayoutActor::admin(null));
        app(RecordPayoutSettlement::class)($payout, PayoutActor::admin(null), 'wire', 'FT-1');

        $statement = app(BuildSellerStatement::class)($seller->id);

        $this->assertSame(2, $statement['total']);

        // Newest first: the payout, then the sale.
        [$payoutRow, $saleRow] = $statement['rows'];

        $this->assertSame("Payout — {$payout->reference}", $payoutRow['description']);
        $this->assertSame('payout', $payoutRow['referenceKind']);
        $this->assertSame($payout->reference, $payoutRow['reference']);
        $this->assertSame('$88.00', $payoutRow['debit']);
        $this->assertNull($payoutRow['credit'], 'A debit row has nothing in the "in" column.');

        $this->assertSame("Sale — {$sellerOrder->reference}", $saleRow['description']);
        $this->assertSame('order', $saleRow['referenceKind']);
        $this->assertSame('$88.00', $saleRow['credit']);
        $this->assertNull($saleRow['debit']);
    }

    #[Test]
    public function the_running_balance_is_the_one_the_ledger_recorded(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 10_000);
        $this->availableEarning($seller, 5_000);
        $this->reversal($seller, 2_500);

        $statement = app(BuildSellerStatement::class)($seller->id);

        // Newest first on screen, so the balances read downwards as the
        // history read backwards.
        $this->assertSame(
            [12_500, 15_000, 10_000],
            array_column($statement['rows'], 'balanceAfterMinor'),
        );

        // And each one is exactly the row the ledger wrote, not a sum this
        // query invented.
        $stored = SellerLedgerEntry::query()->withoutGlobalScopes()
            ->where('seller_account_id', $seller->id)
            ->orderByDesc('id')
            ->pluck('balance_after_minor')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $this->assertSame($stored, array_column($statement['rows'], 'balanceAfterMinor'));
    }

    #[Test]
    public function equal_timestamps_do_not_reorder_the_statement(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        // Three entries written in the same instant. §36's failure mode
        // is that these come back in a different order each time; the
        // ordering is by id, which cannot tie.
        $this->travelTo(now()->startOfSecond());

        $this->availableEarning($seller, 1_000);
        $this->availableEarning($seller, 2_000);
        $this->availableEarning($seller, 3_000);

        $first = array_column(app(BuildSellerStatement::class)($seller->id)['rows'], 'balanceAfterMinor');
        $second = array_column(app(BuildSellerStatement::class)($seller->id)['rows'], 'balanceAfterMinor');

        $this->assertSame([6_000, 3_000, 1_000], $first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function each_currency_has_its_own_statement_and_its_own_running_balance(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 10_000, 'USD');
        $this->availableEarning($seller, 4_000, 'EUR');
        $this->availableEarning($seller, 6_000, 'EUR');

        $usd = app(BuildSellerStatement::class)($seller->id, 'USD');
        $eur = app(BuildSellerStatement::class)($seller->id, 'EUR');

        $this->assertSame(1, $usd['total']);
        $this->assertSame(2, $eur['total']);
        $this->assertSame([10_000], array_column($usd['rows'], 'balanceAfterMinor'));
        $this->assertSame([10_000, 4_000], array_column($eur['rows'], 'balanceAfterMinor'));
    }

    #[Test]
    public function an_adjustment_says_what_it_was_for(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        app(PostFinancialAdjustment::class)(
            seller: $seller,
            amountMinor: -1_500,
            reason: 'Duplicate commission corrected.',
            actor: PayoutActor::admin(null, 'Finance'),
        );

        $row = app(BuildSellerStatement::class)($seller->id)['rows'][0];

        $this->assertSame('Adjustment — Duplicate commission corrected.', $row['description']);
        $this->assertSame('$15.00', $row['debit']);
    }
}

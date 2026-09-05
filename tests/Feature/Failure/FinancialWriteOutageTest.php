<?php

declare(strict_types=1);

namespace Tests\Feature\Failure;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Actions\RequestPayout;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\Feature\Payouts\BuildsSellerFinance;
use Tests\Support\Failure\BreaksInfrastructure;
use Tests\TestCase;
use Throwable;

/**
 * Money cannot be created while the ledger is unreachable.
 *
 * This is the drill the whole failure block exists for. Every other
 * degradation is recoverable; a system that reports "paid" for a
 * transaction that never persisted is not, because the customer has a
 * receipt and the database has nothing, and no reconciliation can invent
 * the missing row from a promise made in an HTTP response.
 *
 * Each drill has the same shape: record the world, take PostgreSQL away
 * by pointing at a closed port, attempt a financial action, put
 * PostgreSQL back, and prove that nothing moved and nothing claimed
 * otherwise. The action must raise — a silent no-op that returned a
 * success value would be the defect, not the fix.
 */
final class FinancialWriteOutageTest extends TestCase
{
    use BreaksInfrastructure;
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsSellerFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    /**
     * @return array{order: MarketplaceOrder, reference: string}
     */
    private function preparedOrder(): array
    {
        ['offer' => $offer] = $this->sellableOffer();

        $order = $this->placeOrder([[$offer, 1]]);
        ['reference' => $reference] = $this->prepare($order);

        return ['order' => $order, 'reference' => $reference];
    }

    /**
     * An order that cannot be written is not an order.
     *
     * The customer sees a failure and can try again; what must not
     * happen is a reference handed out for a row that does not exist.
     */
    #[Test]
    public function checkout_cannot_create_an_order_while_the_database_is_gone(): void
    {
        ['offer' => $offer] = $this->sellableOffer();

        $before = MarketplaceOrder::query()->count();

        $this->withDatabaseDown(function () use ($offer): void {
            $raised = false;

            try {
                $this->placeOrder([[$offer, 1]]);
            } catch (Throwable) {
                $raised = true;
            }

            $this->assertTrue($raised, 'Placing an order without a database must not appear to succeed.');
        });

        $this->assertSame($before, MarketplaceOrder::query()->count(), 'An order was created during the outage.');
    }

    /**
     * The provider says the customer paid. The database cannot hear it.
     *
     * This is the dangerous case, because the money really has moved on
     * the provider's side. The only safe answer is to fail — loudly, so
     * the webhook is retried — and to write nothing. Inventing a local
     * `paid` row from the provider's word would be a guess, and the one
     * time the guess is wrong it is a guess about somebody's money.
     */
    #[Test]
    public function a_verified_payment_cannot_be_finalised_while_the_database_is_gone(): void
    {
        ['order' => $order, 'reference' => $reference] = $this->preparedOrder();

        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $this->withDatabaseDown(function () use ($reference): void {
            $raised = false;

            try {
                $this->deliverEvent('payment_intent.succeeded', $reference);
            } catch (Throwable) {
                $raised = true;
            }

            $this->assertTrue($raised, 'Finalising a payment without a database must not appear to succeed.');
        });

        $order->refresh();

        $this->assertNotSame('paid', $order->status->value, 'The order was marked paid without a persisted payment.');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        $this->assertDatabaseCount('platform_revenue_entries', 0);
    }

    /**
     * And the provider's word is not lost — it is still deliverable.
     *
     * The recovery path is the webhook itself: the provider retries a
     * delivery it did not get a 2xx for, and once the database is back
     * the same event finalises exactly once. Proving that here is what
     * makes the refusal above safe rather than merely strict.
     */
    #[Test]
    public function the_payment_finalises_once_when_the_database_returns(): void
    {
        ['order' => $order, 'reference' => $reference] = $this->preparedOrder();

        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $this->withDatabaseDown(function () use ($reference): void {
            try {
                $this->deliverEvent('payment_intent.succeeded', $reference);
            } catch (Throwable) {
                // Expected; asserted by the drill above.
            }
        });

        // The provider redelivers. Same event, database back.
        $this->deliverEvent('payment_intent.succeeded', $reference);

        $order->refresh();

        $this->assertSame('paid', $order->status->value);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(
            1,
            DB::table('seller_ledger_entries')->where('type', 'sale_earning')->count(),
            'The earning was posted more than once across the outage and the retry.',
        );
    }

    /**
     * A payout request reserves a seller's money, so it cannot be a
     * promise made in memory.
     *
     * A request that appeared to succeed without persisting would leave
     * the seller believing funds were on their way and the allocation
     * that holds them nowhere at all.
     */
    #[Test]
    public function a_payout_cannot_be_requested_while_the_database_is_gone(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $this->withDatabaseDown(function () use ($seller): void {
            $raised = false;

            try {
                app(RequestPayout::class)($seller, 10_000, 'USD', $this->financeActor());
            } catch (Throwable) {
                $raised = true;
            }

            $this->assertTrue($raised, 'Requesting a payout without a database must not appear to succeed.');
        });

        $this->assertDatabaseCount('payout_requests', 0);
        $this->assertDatabaseCount('payout_allocations', 0);
    }

    /**
     * A settlement is a debit against a seller's balance. If the debit
     * cannot be written, the payout must not read as paid — otherwise the
     * money leaves twice: once because an operator saw "paid", and once
     * when the settlement is repeated after the outage.
     */
    #[Test]
    public function a_payout_cannot_be_settled_while_the_database_is_gone(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->approve($this->requestPayout($seller, 10_000));

        $this->withDatabaseDown(function () use ($request): void {
            $raised = false;

            try {
                app(RecordPayoutSettlement::class)($request, $this->financeActor(), 'wire', 'FT-OUTAGE-1');
            } catch (Throwable) {
                $raised = true;
            }

            $this->assertTrue($raised, 'Settling a payout without a database must not appear to succeed.');
        });

        /** @var PayoutRequest $fresh */
        $fresh = PayoutRequest::query()->findOrFail($request->id);

        $this->assertSame(PayoutStatus::Approved, $fresh->status, 'The payout moved to paid without a persisted debit.');
        $this->assertSame(
            0,
            DB::table('seller_ledger_entries')->where('type', 'payout')->count(),
            'A payout debit was written during a database outage.',
        );
    }

    /**
     * Nothing above left the seller's authoritative balance changed.
     *
     * The per-table assertions prove no row was written; this proves the
     * projection those rows feed still answers the same number, which is
     * the figure a person would actually look at.
     */
    #[Test]
    public function the_seller_position_is_unchanged_by_every_failed_write(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $before = $this->positionOf($seller);

        $this->withDatabaseDown(function () use ($seller): void {
            foreach ([1_000, 2_000, 3_000] as $amount) {
                try {
                    app(RequestPayout::class)($seller, $amount, 'USD', $this->financeActor());
                } catch (Throwable) {
                    // Expected.
                }
            }
        });

        $after = $this->positionOf(SellerAccount::query()->findOrFail($seller->id));

        $this->assertSame($before->availableMinor, $after->availableMinor);
        $this->assertSame($before->reservedMinor, $after->reservedMinor);
        $this->assertSame($before->withdrawableMinor(), $after->withdrawableMinor());
    }
}

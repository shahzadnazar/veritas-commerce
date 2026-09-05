<?php

declare(strict_types=1);

namespace Tests\Feature\Failure;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Notifications\SellerStatusChanged;
use App\Support\Queues;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\Feature\Payouts\BuildsSellerFinance;
use Tests\Support\Failure\BreaksInfrastructure;
use Tests\TestCase;
use Throwable;

/**
 * The mail provider is down. Nothing financial may move because of it.
 *
 * Email is the least important thing in this system and the easiest one
 * to let break something important. A confirmation that cannot be sent is
 * an inconvenience; an order that rolls back because a confirmation could
 * not be sent is a customer charged for nothing. The direction of that
 * dependency has to be one-way, and these drills are what says so.
 *
 * The transport is replaced rather than faked, because `Mail::fake()`
 * proves the opposite of what is under test — that sending succeeded
 * without leaving the process.
 */
final class MailFailureTest extends TestCase
{
    use BreaksInfrastructure;
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

    /**
     * A payment stays paid when its receipt cannot be sent.
     *
     * Everything financial happens inside the transaction; the receipt is
     * dispatched after it commits. So the send failing can only fail the
     * request, never the payment.
     */
    #[Test]
    public function a_failing_receipt_does_not_undo_a_payment(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);

        $this->withMailFailing(function () use ($order): void {
            try {
                $this->payFor($order);
            } catch (Throwable) {
                // The send fails after the commit.
            }
        });

        $order->refresh();

        $this->assertSame('paid', $order->status->value);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('seller_ledger_entries')->where('type', 'sale_earning')->count());
        $this->assertSame(1, DB::table('platform_revenue_entries')->where('type', 'commission')->count());
    }

    /** And the stock the payment committed stays committed. */
    #[Test]
    public function a_failing_receipt_does_not_return_committed_stock(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 3]]);

        $this->withMailFailing(function () use ($order): void {
            try {
                $this->payFor($order);
            } catch (Throwable) {
                // Expected.
            }
        });

        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        $this->assertSame(7, (int) $balance?->on_hand, 'The sale did not commit its stock.');
        $this->assertSame(0, (int) $balance?->reserved, 'A hold outlived the sale it belonged to.');
    }

    /**
     * A shipment stays shipped when its notification cannot be sent.
     *
     * The seller physically handed a parcel to a carrier. No mail
     * provider gets to un-happen that.
     */
    #[Test]
    public function a_failing_notification_does_not_undo_a_shipment(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 2]]);
        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->confirm($sellerOrder);

        $this->withMailFailing(function () use ($sellerOrder): void {
            try {
                $this->shipEverything($sellerOrder);
            } catch (Throwable) {
                // Expected.
            }
        });

        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('shipments', ['status' => 'shipped']);
        $this->assertSame('shipped', $sellerOrder->refresh()->status->value);
    }

    /**
     * A payout stays paid when its notification cannot be sent.
     *
     * The three things that must not happen are named individually,
     * because each is a different way of turning a mail outage into a
     * money problem: rolling the payout back, debiting twice, or bringing
     * the reservation back to life so the same funds can be requested
     * again.
     */
    #[Test]
    public function a_failing_notification_does_not_undo_a_payout(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->approve($this->requestPayout($seller, 10_000));

        $this->withMailFailing(function () use ($request): void {
            try {
                app(RecordPayoutSettlement::class)($request, $this->financeActor(), 'wire', 'FT-MAIL-1');
            } catch (Throwable) {
                // Expected.
            }
        });

        /** @var PayoutRequest $fresh */
        $fresh = PayoutRequest::query()->findOrFail($request->id);

        $this->assertSame(PayoutStatus::Paid, $fresh->status, 'A mail failure rolled a payout back.');
        $this->assertSame(
            1,
            DB::table('seller_ledger_entries')->where('type', 'payout')->count(),
            'A mail failure produced a second debit.',
        );
        $this->assertSame(
            0,
            DB::table('payout_allocations')->where('status', 'held')->count(),
            'A mail failure resurrected the payout reservation.',
        );

        $position = $this->positionOf($seller);
        $this->assertSame(40_000, $position->availableMinor);
        $this->assertSame(10_000, $position->paidOutMinor);
    }

    /**
     * A permanent rejection is retried a bounded number of times.
     *
     * "Bounded" is the requirement, not "once". The address that does not
     * exist will not exist on the fifth attempt either, so the five are
     * wasted — but they end, and they end somewhere a person can see. The
     * alternative, a job that retries a 550 for ever, is a queue that
     * fills with deliveries that were never going to happen.
     *
     * The distinction between permanent and transient is not currently
     * drawn: both are `TransportException` and both get the emails
     * queue's five attempts. That is recorded rather than fixed, because
     * telling them apart means parsing provider prose, and a
     * misclassified permanent failure would silently stop retrying a
     * message that would have gone through.
     */
    #[Test]
    public function a_permanently_rejected_message_stops_after_its_configured_attempts(): void
    {
        config(['queue.default' => 'redis']);

        ['seller' => $seller, 'user' => $user] = $this->makeSeller();

        $this->withMailFailing(function () use ($user): void {
            $user->notify(new SellerStatusChanged(
                storeName: 'Drill Store',
                status: SellerStatus::Approved,
            ));

            $tries = (int) (config('horizon.environments.local.emails.tries')
                ?? config('horizon.environments.production.emails.tries')
                ?? 5);

            for ($attempt = 0; $attempt <= $tries; $attempt++) {
                $this->workOnce(Queues::EMAILS);
            }
        }, permanent: true);

        $this->assertSame(0, Queue::connection('redis')->size(Queues::EMAILS));
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    /**
     * The failed record says enough to investigate and no more.
     *
     * A failed job is read by whoever is on call, in a log aggregator
     * that anybody with access can search. The exception and the queue
     * name are what they need; the recipient's address is not, and a
     * transport that helpfully included the message body would put every
     * order's contents in there too.
     */
    #[Test]
    public function a_failed_mail_job_is_visible_without_dumping_the_recipient(): void
    {
        config(['queue.default' => 'redis']);

        ['user' => $user] = $this->makeSeller();
        $address = (string) $user->email;

        $this->withMailFailing(function () use ($user): void {
            $user->notify(new SellerStatusChanged(
                storeName: 'Drill Store',
                status: SellerStatus::Suspended,
                reason: 'Drill',
            ));

            for ($attempt = 0; $attempt < 8; $attempt++) {
                $this->workOnce(Queues::EMAILS);
            }
        });

        $this->assertDatabaseCount('failed_jobs', 1);

        $failed = (string) DB::table('failed_jobs')->value('exception');

        $this->assertStringContainsString('TransportException', $failed, 'The failure does not say what went wrong.');
        $this->assertStringNotContainsString($address, $failed, 'The failed-job record dumped the recipient address.');
    }

    /** Run exactly one job, the way a worker process would. */
    private function workOnce(string $queue): void
    {
        $this->app[Kernel::class]->call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--once' => true,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Actions\CancelUnpaidOrder;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\PaymentTransactionType;
use App\Modules\Payments\Enums\ProviderEventStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PlatformRevenueEntry;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * A verified payment, and everything it is allowed to change.
 *
 * The order of these tests is the order of the guarantees. First that the
 * provider's own answer is what decides anything; then that the amount is
 * checked before a single row moves; then that everything downstream
 * happens exactly once however many times the event is delivered.
 */
final class PaymentFinalizationTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    #[Test]
    public function a_verified_success_marks_the_attempt_and_the_order_paid(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_500, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->payFor($order);

        $attempt = PaymentAttempt::query()->firstOrFail();

        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNotNull($attempt->succeeded_at);
        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(
            [SellerOrderStatus::Paid],
            SellerOrder::query()->withoutGlobalScopes()->get()->pluck('status')->all(),
        );
    }

    #[Test]
    public function the_provider_is_told_the_orders_amount_and_currency_exactly(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 3_333, stock: 10);
        $order = $this->placeOrder([[$offer, 3]]);

        ['reference' => $reference] = $this->prepare($order);

        $providerPayment = $this->provider()->retrievePayment($reference);

        // 9,999 — from the order's frozen total, not recomputed from a cart.
        $this->assertSame($order->grand_total_minor, $providerPayment->amountMinor);
        $this->assertSame($order->currency, $providerPayment->currency);
        $this->assertSame(9_999, $providerPayment->amountMinor);
    }

    #[Test]
    public function the_provider_is_given_references_and_no_customer_details(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $order = $this->placeOrder([$offer], $user->id, 'ada@example.test');

        ['reference' => $reference] = $this->prepare($order);
        $metadata = $this->provider()->retrievePayment($reference)->metadata;

        $this->assertSame($order->reference, $metadata['order_number'] ?? null);
        $this->assertSame((string) $order->id, $metadata['marketplace_order_id'] ?? null);

        // §4: a provider dashboard is not a second copy of the customer
        // database, and metadata is visible to everyone who can open it.
        $flattened = strtolower(implode('|', $metadata));
        $this->assertStringNotContainsString('ada@example.test', $flattened);
        $this->assertStringNotContainsString('lovelace', $flattened);
        $this->assertStringNotContainsString('analytical way', $flattened);
    }

    #[Test]
    public function an_amount_that_does_not_match_the_order_blocks_finalization(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([$offer]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        // Something between the platform and the provider is broken.
        $this->provider()->tamperAmount($reference, 100);

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        // Nothing moved, and the discrepancy is visible rather than logged
        // and forgotten.
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(ProviderEventStatus::Failed, $event->refresh()->status);
        $this->assertStringContainsString('amount_mismatch', (string) $event->refresh()->error);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_currency_that_does_not_match_blocks_finalization(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([$offer]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);
        $this->provider()->tamperCurrency($reference, 'EUR');

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertStringContainsString('currency_mismatch', (string) $event->refresh()->error);
    }

    #[Test]
    public function a_reference_the_platform_never_prepared_is_handled_safely(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([$offer]);

        // A payment that exists at the provider but belongs to nothing here.
        $stray = $this->provider()->preparePayment(5_000, 'USD', 'not-ours');
        $this->provider()->settle($stray->reference, PaymentAttemptStatus::Succeeded);

        $event = $this->deliverEvent('payment_intent.succeeded', $stray->reference);

        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        // Recognised, handled, nothing transitioned — not an error to retry.
        $this->assertSame(ProviderEventStatus::Processed, $event->refresh()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    #[Test]
    public function payment_commits_the_reservations_exactly_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $order = $this->placeOrder([[$offer, 3]]);

        $this->assertSame(3, $this->reserved($offer->id));
        $this->assertSame(10, $this->onHand($offer->id));

        $this->payFor($order);

        // Both quantities fall together, in one movement, once.
        $this->assertSame(7, $this->onHand($offer->id));
        $this->assertSame(0, $this->reserved($offer->id));
        $this->assertSame(7, $this->available($offer->id));
        $this->assertSame(
            ReservationStatus::Consumed,
            InventoryReservation::query()->firstOrFail()->status,
        );
        $this->assertSame(1, InventoryMovement::query()
            ->where('reason', InventoryMovementReason::SaleCompleted->value)->count());
    }

    #[Test]
    public function a_payment_transaction_is_written_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 6_000);
        $order = $this->placeOrder([$offer]);

        $this->payFor($order);

        /** @var PaymentTransaction $transaction */
        $transaction = PaymentTransaction::query()->firstOrFail();

        $this->assertSame(PaymentTransactionType::Capture, $transaction->type);
        // Signed positive: an order's net position is a sum.
        $this->assertSame(6_000, $transaction->amount_minor);
        $this->assertSame(1, PaymentTransaction::query()->count());
    }

    #[Test]
    public function seller_earnings_are_recorded_from_the_snapshot_and_are_not_available(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000);
        $order = $this->placeOrder([$offer]);

        $this->payFor($order);

        /** @var SellerLedgerEntry $entry */
        $entry = SellerLedgerEntry::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(LedgerEntryType::SaleEarning, $entry->type);
        $this->assertSame(8_800, $entry->amount_minor, 'The item snapshot, not a recomputation.');

        /*
         * §30, and the reason it matters: a seller who could withdraw
         * against an order that has not shipped leaves the platform funding
         * the float on every refund. Clearing starts at delivery, which M6
         * owns.
         */
        $this->assertSame(LedgerEntryStatus::Pending, $entry->status);
        $this->assertNull($entry->available_at, 'Payment does not make money withdrawable.');
    }

    #[Test]
    public function platform_commission_is_recorded_from_the_snapshot(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000);
        $order = $this->placeOrder([$offer]);

        $this->payFor($order);

        /** @var PlatformRevenueEntry $entry */
        $entry = PlatformRevenueEntry::query()->firstOrFail();

        $this->assertSame(PlatformRevenueEntry::TYPE_COMMISSION, $entry->type);
        $this->assertSame(1_200, $entry->amount_minor);
        $this->assertSame('12.00', $entry->rate_percent_snapshot);
    }

    #[Test]
    public function a_commission_rate_change_before_payment_does_not_alter_the_recording(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000);
        $order = $this->placeOrder([$offer]);

        // The platform raises its rate after the order, before payment.
        DB::table('commission_rules')->update(['rate_percent' => '30.00']);

        $this->payFor($order);

        // §32: the order was taken at 12% and that is what is recorded.
        $this->assertSame(1_200, (int) PlatformRevenueEntry::query()->value('amount_minor'));
        $this->assertSame(8_800, (int) SellerLedgerEntry::query()->withoutGlobalScopes()->value('amount_minor'));
    }

    #[Test]
    public function the_recorded_obligations_reconcile_with_the_order(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle', priceMinor: 9_973);
        ['offer' => $b] = $this->sellableOffer('Lamp', priceMinor: 3_333);
        $order = $this->placeOrder([[$a, 3], [$b, 1]]);

        $this->payFor($order);

        $sellerTotal = (int) SellerLedgerEntry::query()->withoutGlobalScopes()->sum('amount_minor');
        $commissionTotal = (int) PlatformRevenueEntry::query()->sum('amount_minor');
        $captured = (int) PaymentTransaction::query()->sum('amount_minor');

        // §33, in minor units and with no float anywhere.
        $this->assertSame($order->grand_total_minor, $captured);
        $this->assertSame($order->items_total_minor, $sellerTotal + $commissionTotal);
    }

    #[Test]
    public function the_same_event_delivered_twice_changes_nothing_the_second_time(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $signed = $this->provider()->signedEvent(
            'payment_intent.succeeded',
            $this->provider()->paymentObject($reference),
            'evt_replay',
        );

        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/webhooks/payments',
                server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
                content: $signed['payload'])->assertOk();
        }

        $this->assertOneOfEverything($order->refresh());
    }

    #[Test]
    public function the_same_event_delivered_ten_times_changes_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $signed = $this->provider()->signedEvent(
            'payment_intent.succeeded',
            $this->provider()->paymentObject($reference),
            'evt_ten',
        );

        for ($i = 0; $i < 10; $i++) {
            $this->call('POST', '/webhooks/payments',
                server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
                content: $signed['payload'])->assertOk();
        }

        $this->assertOneOfEverything($order->refresh());
    }

    #[Test]
    public function reprocessing_a_stored_event_changes_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        $reference = $this->payFor($order);
        $event = ProviderWebhookEvent::query()->firstOrFail();

        // The job itself retried, which is what a queue does.
        $this->reprocess($event);
        $this->reprocess($event);

        $this->assertOneOfEverything($order->refresh());
        $this->assertNotSame('', $reference);
    }

    #[Test]
    public function a_stale_processing_event_cannot_un_pay_an_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);
        $this->deliverEvent('payment_intent.succeeded', $reference, 'evt_success');

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);

        // A `processing` notification that was in flight before the success
        // one, delivered after it. §14.
        $this->provider()->settle($reference, PaymentAttemptStatus::Processing);
        $this->deliverEvent('payment_intent.payment_failed', $reference, 'evt_stale', time() - 600);

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(PaymentAttemptStatus::Succeeded, PaymentAttempt::query()->firstOrFail()->status);
        $this->assertSame(0, $this->reserved($offer->id), 'The sale stands.');
    }

    #[Test]
    public function an_unsigned_event_is_refused_and_stored_nowhere(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([$offer]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $payload = (string) json_encode([
            'id' => 'evt_forged',
            'type' => 'payment_intent.succeeded',
            'created' => time(),
            'data' => ['object' => $this->provider()->paymentObject($reference)],
        ]);

        $this->call('POST', '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => 'not-a-signature', 'CONTENT_TYPE' => 'application/json'],
            content: $payload)->assertStatus(400);

        // Nothing recorded, nothing queued, nothing paid.
        $this->assertSame(0, ProviderWebhookEvent::query()->count());
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
    }

    #[Test]
    public function an_event_with_no_signature_at_all_is_refused(): void
    {
        $this->call('POST', '/webhooks/payments',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"id":"evt_x","type":"payment_intent.succeeded","data":{"object":{"id":"pi_x"}}}')
            ->assertStatus(400);

        $this->assertSame(0, ProviderWebhookEvent::query()->count());
    }

    #[Test]
    public function payment_after_the_order_was_cancelled_becomes_an_exception(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $order = $this->placeOrder([[$offer, 2]]);

        ['reference' => $reference] = $this->prepare($order);

        // The expiry sweep gets there first and puts the stock back.
        app(CancelUnpaidOrder::class)($order);
        $this->assertSame(5, $this->available($offer->id));

        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);
        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        /*
         * §23: money arrived for an order the platform has closed and whose
         * stock may already have been sold to somebody else. Reviving it
         * silently is the one thing that must not happen.
         */
        $this->assertSame(MarketplaceOrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(ProviderEventStatus::Failed, $event->refresh()->status);
        $this->assertStringContainsString('order_not_open', (string) $event->refresh()->error);
        $this->assertSame(0, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame(5, $this->available($offer->id), 'The stock stays where the sweep left it.');
    }

    #[Test]
    public function a_provider_verification_failure_is_not_retried_into_the_ground(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000);
        $order = $this->placeOrder([$offer]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);
        $this->provider()->tamperAmount($reference, 1);

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        // Marked failed once and left for a person: retrying cannot make
        // the amount correct, so burning eight attempts on it only delays
        // somebody noticing.
        $this->assertSame(1, $event->refresh()->attempts);
        $this->assertSame(ProviderEventStatus::Failed, $event->status);
    }

    /** Everything that must exist exactly once after a payment. */
    private function assertOneOfEverything(MarketplaceOrder $order): void
    {
        $this->assertSame(MarketplaceOrderStatus::Paid, $order->status);
        $this->assertSame(1, Payment::query()->count(), 'one capture record');
        $this->assertSame(1, PaymentTransaction::query()->count(), 'one transaction');
        $this->assertSame(1, PlatformRevenueEntry::query()->count(), 'one commission entry');
        $this->assertSame(
            1,
            SellerLedgerEntry::query()->withoutGlobalScopes()->count(),
            'one seller earning entry',
        );
        $this->assertSame(1, InventoryMovement::query()
            ->where('reason', InventoryMovementReason::SaleCompleted->value)->count(), 'one sale movement');
        $this->assertSame(1, PaymentAttempt::query()
            ->where('status', PaymentAttemptStatus::Succeeded->value)->count(), 'one successful attempt');
        $this->assertSame(
            1,
            DB::table('order_status_history')
                ->whereNotNull('marketplace_order_id')
                ->where('to_status', 'paid')
                ->count(),
            'one paid history row',
        );
    }

    private function reserved(int $offerId): int
    {
        return (int) DB::table('inventory_balances')->where('offer_id', $offerId)->value('reserved');
    }

    private function onHand(int $offerId): int
    {
        return (int) DB::table('inventory_balances')->where('offer_id', $offerId)->value('on_hand');
    }

    private function available(int $offerId): int
    {
        return (int) DB::table('inventory_balances')->where('offer_id', $offerId)->value('available');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Offers\Models\Offer;
use App\Modules\Orders\Actions\CancelUnpaidOrder;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Actions\PreparePayment;
use App\Modules\Payments\Adapters\StripePaymentProvider;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ProviderEventStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PlatformRevenueEntry;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionParameter;
use Stripe\StripeClient;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * M9 property 2 — financial success cannot be fabricated.
 *
 * Seventeen attacks against the one boundary that decides an order was
 * paid. Every one of them is something an attacker can actually do: type a
 * query string, post a body, replay a webhook, deliver a real event about
 * somebody else's payment.
 *
 * The property being proved is deliberately two claims, not one:
 *
 *      THE ATTACK IS REJECTED
 *      AND FINANCIAL TRUTH IS UNCHANGED.
 *
 * Asserting only the first is how a suite reports green while a 400
 * response quietly committed inventory. So each attack brackets itself
 * with a snapshot of every column that a successful payment moves — order
 * and seller-order state, attempt state, payments, transactions, ledger
 * entries, commission, stock, reservations, refunds, payouts — and
 * compares it afterwards.
 *
 * What that snapshot deliberately excludes is provider_webhook_events. A
 * forged or unmatched event that gets recorded as received/failed is
 * operational evidence, not a financial mutation, and the architecture
 * intends to keep it. Requiring "zero rows anywhere" would be testing for
 * a design this platform does not have, and would push the next person to
 * delete the audit trail to make the suite pass.
 *
 * The structural half lives in tests/Invariants/PaymentAuthorityTest: no
 * route, no controller and no second caller can reach MarkOrderPaid. This
 * file is the behavioural half — what happens when somebody tries anyway.
 */
final class FinancialAuthorityAttackTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use ObservesFinancialTruth;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    /**
     * One payment-pending order, prepared for payment, owned by a customer.
     *
     * Built through the real cart, checkout and PreparePayment path: a
     * fixture that inserted an attempt row directly could satisfy every
     * assertion below while the real path produced something an attacker
     * could reach differently.
     *
     * @return array{order: MarketplaceOrder, customer: User, reference: string, attempt: PaymentAttempt, offer: Offer}
     */
    private function payable(string $label = 'a', int $priceMinor = 12_500, int $stock = 5): array
    {
        ['offer' => $offer] = $this->sellableOffer(
            title: "Kettle {$label}",
            priceMinor: $priceMinor,
            stock: $stock,
        );

        $customer = User::factory()->create(['email' => "buyer-{$label}@example.test"]);
        $order = $this->placeOrder([[$offer, 1]], $customer->id, (string) $customer->email);

        ['attempt' => $attempt, 'reference' => $reference] = $this->prepare($order);

        return [
            'order' => $order->refresh(),
            'customer' => $customer,
            'reference' => $reference,
            'attempt' => $attempt,
            'offer' => $offer,
        ];
    }

    /**
     * Deliver a provider event whose object this test wrote by hand.
     *
     * @param  array<string, mixed>  $object
     */
    private function deliverForgedObject(string $type, array $object, ?int $occurredAt = null): ProviderWebhookEvent
    {
        $signed = $this->provider()->signedEvent($type, $object, null, $occurredAt);

        $this->call(
            'POST',
            '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
            content: $signed['payload'],
        );

        /** @var ProviderWebhookEvent $event */
        $event = ProviderWebhookEvent::query()->where('event_id', $signed['event']['id'])->firstOrFail();

        return $event;
    }

    private function assertNotPaid(MarketplaceOrder $order, string $attack): void
    {
        $this->assertSame(
            MarketplaceOrderStatus::PendingPayment,
            $order->refresh()->status,
            "{$attack}: the order became paid.",
        );

        $sellerOrders = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->get();

        foreach ($sellerOrders as $sellerOrder) {
            $this->assertSame(
                SellerOrderStatus::PendingPayment,
                $sellerOrder->status,
                "{$attack}: a seller order became payable work.",
            );
        }
    }

    // ── Attack 1 ──────────────────────────────────────────────────────

    #[Test]
    public function a_browser_claiming_success_in_the_url_changes_nothing(): void
    {
        ['order' => $order, 'customer' => $customer, 'reference' => $reference] = $this->payable();

        $truth = $this->financialTruth();
        Notification::fake();

        // Everything a return URL can carry, including the shapes Stripe
        // itself uses. All of it is the browser talking, and the browser
        // is a party to the transaction with an interest in the answer.
        $claims = http_build_query([
            'paid' => 'true',
            'success' => 'true',
            'status' => 'succeeded',
            'payment_status' => 'paid',
            'redirect_status' => 'succeeded',
            'payment_intent' => $reference,
            'payment_intent_client_secret' => $reference.'_secret_forged',
        ]);

        $this->actingAs($customer)->get("/checkout/{$order->reference}/payment?{$claims}")->assertOk();
        $this->actingAs($customer)->getJson("/checkout/{$order->reference}/payment/status?{$claims}")->assertOk();
        $this->actingAs($customer)->postJson("/checkout/{$order->reference}/payment/prepare?{$claims}", [
            'paid' => true,
            'status' => 'succeeded',
            'redirect_status' => 'succeeded',
        ])->assertOk();

        $this->assertNotPaid($order, 'attack 1');
        /** @var PaymentAttempt $attempt */
        $attempt = PaymentAttempt::query()->where('marketplace_order_id', $order->id)->firstOrFail();
        $this->assertNotSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($truth, 'attack 1 (browser return)');
    }

    // ── Attack 2 ──────────────────────────────────────────────────────

    #[Test]
    public function a_customer_cannot_choose_what_they_are_charged(): void
    {
        ['order' => $order, 'customer' => $customer] = $this->payable(priceMinor: 40_000);

        $truth = $this->financialTruth();

        $response = $this->actingAs($customer)->postJson("/checkout/{$order->reference}/payment/prepare", [
            'amount' => 1,
            'amount_minor' => 1,
            'grand_total' => 1,
            'grand_total_minor' => 1,
            'items_total_minor' => 1,
            'shipping_total_minor' => 0,
            'currency' => 'XXX',
            'seller_totals' => [['seller_order_id' => 1, 'order_total_minor' => 1]],
        ])->assertOk();

        // The amount quoted back is the order's, not the one submitted.
        $response->assertJsonPath('amount.minor', $order->grand_total_minor);
        $response->assertJsonPath('amount.currency', $order->currency);

        /** @var PaymentAttempt $attempt */
        $attempt = PaymentAttempt::query()->where('marketplace_order_id', $order->id)->firstOrFail();

        $this->assertSame($order->grand_total_minor, $attempt->amount_minor);
        $this->assertSame($order->currency, $attempt->currency);
        $this->assertNotSame('XXX', $attempt->currency);

        $this->assertNotPaid($order, 'attack 2');
        $this->assertFinancialTruthUnchanged($truth, 'attack 2 (client-supplied amount)');
    }

    // ── Attack 4 ──────────────────────────────────────────────────────

    #[Test]
    public function an_unsigned_webhook_is_refused_and_leaves_no_evidence(): void
    {
        ['order' => $order, 'reference' => $reference] = $this->payable();
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $truth = $this->financialTruth();
        Notification::fake();

        $payload = (string) json_encode([
            'id' => 'evt_forged_unsigned',
            'type' => 'payment_intent.succeeded',
            'created' => time(),
            'data' => ['object' => $this->provider()->paymentObject($reference)],
        ]);

        $this->call('POST', '/webhooks/payments', server: ['CONTENT_TYPE' => 'application/json'], content: $payload)
            ->assertStatus(400);

        // Unsigned is the one case that is not even evidence: the
        // controller refuses before anything is stored, so there is no row
        // an attacker can create by shouting at the endpoint.
        $this->assertSame(0, ProviderWebhookEvent::query()->count());

        $this->assertNotPaid($order, 'attack 4');
        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($truth, 'attack 4 (unsigned webhook)');
    }

    // ── Attack 5 ──────────────────────────────────────────────────────

    #[Test]
    public function a_correct_body_with_a_wrong_signature_is_refused(): void
    {
        ['order' => $order, 'reference' => $reference] = $this->payable();
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $signed = $this->provider()->signedEvent(
            'payment_intent.succeeded',
            $this->provider()->paymentObject($reference),
        );

        $truth = $this->financialTruth();
        Notification::fake();

        foreach ([
            'wrong secret' => hash_hmac('sha256', $signed['payload'], 'not-the-signing-secret'),
            'truncated' => substr($signed['signature'], 0, 32),
            'empty' => '',
            'not hex' => str_repeat('z', 64),
        ] as $label => $signature) {
            $response = $this->call(
                'POST',
                '/webhooks/payments',
                server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
                content: $signed['payload'],
            );

            $this->assertSame(400, $response->getStatusCode(), "A {$label} signature was accepted.");
        }

        $this->assertSame(0, ProviderWebhookEvent::query()->count());
        $this->assertNotPaid($order, 'attack 5');
        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($truth, 'attack 5 (invalid signature)');
    }

    // ── Attack 6 ──────────────────────────────────────────────────────

    #[Test]
    public function a_stripe_signature_outside_the_tolerance_window_is_refused_at_the_endpoint(): void
    {
        // StripeAdapterTest proves the adapter refuses a stale signature.
        // This proves the endpoint does — the tolerance is only a replay
        // guard if it is on the path a request actually takes.
        ['order' => $order, 'reference' => $reference] = $this->payable();
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $secret = 'whsec_test_secret_for_signing';

        $this->app->instance(PaymentProvider::class, new StripePaymentProvider(
            // Offline: nothing here reaches Stripe, and a placeholder key
            // is more honest than a stub that pretends otherwise.
            new StripeClient(['api_key' => 'sk_test_offline']),
            $secret,
        ));

        $truth = $this->financialTruth();
        Notification::fake();

        $payload = (string) json_encode([
            'id' => 'evt_stale_but_correctly_signed',
            'type' => 'payment_intent.succeeded',
            'created' => time() - 86_400,
            'data' => ['object' => ['id' => $reference, 'object' => 'payment_intent', 'amount' => $order->grand_total_minor]],
        ], JSON_THROW_ON_ERROR);

        foreach ([time() - 86_400, time() - 3_600, time() + 86_400] as $timestamp) {
            $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

            $response = $this->call(
                'POST',
                '/webhooks/payments',
                server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
                content: $payload,
            );

            $this->assertSame(
                400,
                $response->getStatusCode(),
                "A signature timestamped {$timestamp} was accepted.",
            );
        }

        // The control: a correct signature inside the window gets past the
        // check, so the three refusals above are the tolerance doing its
        // job rather than the signature check failing for some unrelated
        // reason.
        //
        // Proven by the recorded row, not by the status code. The
        // controller verifies, then records, then queues — so the row
        // existing means verification passed. What happens after that is
        // the job trying to ask Stripe what really happened, which offline
        // is a connection error and is exactly right: the platform refuses
        // to finalize on the payload alone even when the payload is
        // perfectly signed.
        $fresh = time();
        $signature = 't='.$fresh.',v1='.hash_hmac('sha256', $fresh.'.'.$payload, $secret);

        $this->call(
            'POST',
            '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );

        $this->assertSame(
            1,
            ProviderWebhookEvent::query()->count(),
            'The in-window delivery should have passed signature verification and been recorded; '
                .'the three stale ones should not.',
        );

        $this->assertNotPaid($order, 'attack 6');
        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($truth, 'attack 6 (expired signature timestamp)');
    }

    // ── Attack 7 ──────────────────────────────────────────────────────

    #[Test]
    public function a_signed_event_for_a_payment_we_never_prepared_finalizes_nothing(): void
    {
        ['order' => $order] = $this->payable();

        $truth = $this->financialTruth();
        Notification::fake();

        // Delivered over HTTP rather than by calling the job directly: an
        // unknown reference makes the provider lookup throw, and the job
        // rethrows it on purpose so the queue retries and Horizon shows it.
        // Through the endpoint that becomes a 500 the provider will retry,
        // which is the behaviour we want to observe rather than suppress.
        $event = $this->deliverForgedObject('payment_intent.succeeded', [
            'id' => 'fake_pi_never_prepared',
            'status' => 'succeeded',
            'amount' => $order->grand_total_minor,
            'amount_received' => $order->grand_total_minor,
            'currency' => strtolower($order->currency),
            'metadata' => [],
        ]);

        // Persisted, because a signed event arriving for a payment we have
        // no record of is exactly the thing operations needs to see. It is
        // evidence, and evidence is not a mutation.
        $this->assertSame(ProviderEventStatus::Failed, $event->refresh()->status);
        $this->assertNotNull($event->error);

        $this->assertNotPaid($order, 'attack 7');
        $this->assertSame(0, Payment::query()->count());
        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($truth, 'attack 7 (unknown reference)');
    }

    // ── Attack 9 ──────────────────────────────────────────────────────

    #[Test]
    public function a_provider_amount_below_the_order_total_is_refused(): void
    {
        ['order' => $order, 'reference' => $reference] = $this->payable(priceMinor: 40_000);

        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);
        // The £400 order paid for with £4 — the failure this check exists
        // for, and the one nobody notices until month end.
        $this->provider()->tamperAmount($reference, 400);

        $truth = $this->financialTruth();
        Notification::fake();

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        $this->assertSame(ProviderEventStatus::Failed, $event->refresh()->status);
        $this->assertStringContainsString('amount', (string) $event->error);

        $this->assertNotPaid($order, 'attack 9');
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PlatformRevenueEntry::query()->count());
        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($truth, 'attack 9 (amount mismatch)');
    }

    // ── Attack 10 ─────────────────────────────────────────────────────

    #[Test]
    public function a_provider_currency_that_is_not_the_orders_is_refused(): void
    {
        ['order' => $order, 'reference' => $reference] = $this->payable();

        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);
        // Same number, different money. 12500 JPY is not 12500 USD, and a
        // platform that compares only the integer has just been robbed.
        $this->provider()->tamperCurrency($reference, 'JPY');

        $truth = $this->financialTruth();
        Notification::fake();

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        $this->assertSame(ProviderEventStatus::Failed, $event->refresh()->status);
        $this->assertStringContainsString('currency', (string) $event->error);

        $this->assertNotPaid($order, 'attack 10');
        $this->assertSame(0, Payment::query()->count());
        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($truth, 'attack 10 (currency mismatch)');
    }

    // ── Attack 12 ─────────────────────────────────────────────────────

    #[Test]
    public function a_stale_earlier_event_arriving_late_never_un_pays_an_order(): void
    {
        ['order' => $order, 'reference' => $reference] = $this->payable();

        $this->payFor($order);

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);

        $paid = $this->financialTruth();
        Notification::fake();

        // Providers deliver out of order. An event created before the
        // success, arriving after it, must not walk the payment backwards.
        foreach (['payment_intent.processing', 'payment_intent.requires_action', 'payment_intent.created'] as $type) {
            $event = $this->deliverForgedObject($type, [
                'id' => $reference,
                'status' => str_replace('payment_intent.', '', $type),
                'amount' => $order->grand_total_minor,
                'amount_received' => 0,
                'currency' => strtolower($order->currency),
                'metadata' => [],
            ], occurredAt: time() - 3_600);

            $this->reprocess($event);
        }

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);

        /** @var PaymentAttempt $attempt */
        $attempt = PaymentAttempt::query()->where('provider_reference', $reference)->firstOrFail();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        foreach (SellerOrder::query()->withoutGlobalScopes()->get() as $sellerOrder) {
            $this->assertNotSame(SellerOrderStatus::PendingPayment, $sellerOrder->status);
        }

        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($paid, 'attack 12 (stale event regression)');
    }

    // ── Attack 13 ─────────────────────────────────────────────────────

    #[Test]
    public function replaying_one_success_ten_times_finalizes_it_once(): void
    {
        ['order' => $order, 'reference' => $reference] = $this->payable();

        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $signed = $this->provider()->signedEvent(
            'payment_intent.succeeded',
            $this->provider()->paymentObject($reference),
            'fake_evt_replayed',
        );

        $deliver = function () use ($signed): void {
            $this->call(
                'POST',
                '/webhooks/payments',
                server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
                content: $signed['payload'],
            )->assertOk();
        };

        $deliver();

        /** @var ProviderWebhookEvent $event */
        $event = ProviderWebhookEvent::query()->where('event_id', 'fake_evt_replayed')->firstOrFail();
        $this->reprocess($event);

        $afterOne = $this->financialTruth();

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);

        Notification::fake();

        // Twice, then ten times: both the endpoint and the job.
        for ($i = 0; $i < 10; $i++) {
            $deliver();
            $this->reprocess($event->refresh());
        }

        $this->assertSame(1, ProviderWebhookEvent::query()->count(), 'One delivery, one row.');
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(1, PlatformRevenueEntry::query()->count());

        // Nothing was announced a second time. The guarantee is durable —
        // the attempt is Succeeded under a row lock, so PaymentSucceeded
        // is never dispatched again — not an in-memory "already sent" flag.
        Notification::assertNothingSent();

        $this->assertFinancialTruthUnchanged($afterOne, 'attack 13 (duplicate success)');
    }

    // ── Attack 15 ─────────────────────────────────────────────────────

    #[Test]
    public function a_success_arriving_after_expiry_does_not_resurrect_the_order(): void
    {
        ['order' => $order, 'reference' => $reference, 'offer' => $offer] = $this->payable(stock: 3);

        // The legitimate M4 expiry path: the order is cancelled and the
        // stock goes back on the shelf, where somebody else may buy it.
        app(CancelUnpaidOrder::class)($order->refresh(), 'payment_window_elapsed');

        $this->assertSame(MarketplaceOrderStatus::Cancelled, $order->refresh()->status);

        $cancelled = $this->financialTruth();
        Notification::fake();

        // And then the money turns up.
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);
        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        // M5's rule, unchanged: this is an operational exception and a
        // probable refund, never a silent resurrection. The stock has
        // gone; reviving the order is how a marketplace oversells.
        $this->assertSame(ProviderEventStatus::Failed, $event->refresh()->status);
        $this->assertStringContainsString('order_not_open', (string) $event->error);

        $this->assertSame(MarketplaceOrderStatus::Cancelled, $order->refresh()->status);

        foreach (SellerOrder::query()->withoutGlobalScopes()->get() as $sellerOrder) {
            $this->assertSame(SellerOrderStatus::Cancelled, $sellerOrder->status);
        }

        $this->assertSame(0, Payment::query()->count(), 'No capture was recorded for a cancelled order.');
        $this->assertSame(0, PlatformRevenueEntry::query()->count(), 'No commission for goods never sold.');

        // No oversell: the released stock stayed released.
        $balance = InventoryBalance::query()
            ->where('offer_id', $offer->id)
            ->firstOrFail();
        $this->assertSame(3, (int) $balance->on_hand);
        $this->assertSame(0, (int) $balance->reserved);

        Notification::assertNothingSent();

        // The evidence stays visible. An operator has to be able to find
        // this: money arrived for something the platform had cancelled.
        $this->assertSame(1, ProviderWebhookEvent::query()->where('status', ProviderEventStatus::Failed->value)->count());

        $this->assertFinancialTruthUnchanged($cancelled, 'attack 15 (late success after expiry)');
    }

    // ── Attack 7b ─────────────────────────────────────────────────────

    #[Test]
    public function money_for_a_payment_this_platform_never_prepared_is_an_exception(): void
    {
        // The other shape of "unknown reference": the provider does know
        // the payment, and Veritas has no attempt for it. Reached by
        // preparing one and then losing the local record, which is what a
        // restore-from-backup gap or a botched migration looks like.
        ['order' => $order, 'reference' => $reference] = $this->payable();

        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);
        PaymentAttempt::query()->where('provider_reference', $reference)->delete();

        $truth = $this->financialTruth();
        Notification::fake();

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);

        // No attempt to match, so nothing is transitioned and nothing is
        // guessed at. The event is processed — the platform handled it
        // correctly by refusing to invent an order for it.
        $this->assertContains(
            $event->refresh()->status,
            [ProviderEventStatus::Processed, ProviderEventStatus::Failed],
        );

        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(0, Payment::query()->count());
        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($truth, 'attack 7b (no local attempt)');
    }

    // ── Attack 8 ──────────────────────────────────────────────────────

    #[Test]
    public function a_real_payment_for_one_order_cannot_finalize_another(): void
    {
        ['order' => $orderA, 'reference' => $referenceA] = $this->payable('a');
        ['order' => $orderB, 'offer' => $offerB] = $this->payable('b');

        $this->provider()->settle($referenceA, PaymentAttemptStatus::Succeeded);

        Notification::fake();

        // A genuine, correctly signed success for A — with metadata that
        // claims it settles B. Metadata is attacker-influenced in every
        // integration that lets a client set it, so it is never the thing
        // that decides which order was paid.
        $event = $this->deliverForgedObject('payment_intent.succeeded', [
            'id' => $referenceA,
            'status' => 'succeeded',
            'amount' => $orderA->grand_total_minor,
            'amount_received' => $orderA->grand_total_minor,
            'currency' => strtolower($orderA->currency),
            'metadata' => [
                'marketplace_order_id' => (string) $orderB->id,
                'marketplace_order_reference' => $orderB->reference,
                'order_reference' => $orderB->reference,
            ],
        ]);

        $this->assertSame(ProviderEventStatus::Processed, $event->refresh()->status);

        // A is paid, because A is what the provider reference resolves to.
        $this->assertSame(MarketplaceOrderStatus::Paid, $orderA->refresh()->status);

        // B is untouched, which is the whole assertion.
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $orderB->refresh()->status);

        foreach (SellerOrder::query()->withoutGlobalScopes()->where('marketplace_order_id', $orderB->id)->get() as $sellerOrder) {
            $this->assertSame(SellerOrderStatus::PendingPayment, $sellerOrder->status);
        }

        $this->assertSame(0, Payment::query()->where('marketplace_order_id', $orderB->id)->count());
        $this->assertSame(0, PlatformRevenueEntry::query()->where('marketplace_order_id', $orderB->id)->count());

        // And B's stock is still merely held, never committed as sold.
        $balanceB = InventoryBalance::query()->where('offer_id', $offerB->id)->firstOrFail();
        $this->assertSame(5, (int) $balanceB->on_hand);
        $this->assertSame(1, (int) $balanceB->reserved);
    }

    // ── Attack 11 ─────────────────────────────────────────────────────

    #[Test]
    public function metadata_disagreeing_with_the_provider_reference_settles_nothing(): void
    {
        ['order' => $orderA, 'reference' => $referenceA] = $this->payable('a');
        ['order' => $orderB, 'reference' => $referenceB] = $this->payable('b');

        // A is genuinely paid at the provider; B is not.
        $this->provider()->settle($referenceA, PaymentAttemptStatus::Succeeded);

        $truth = $this->financialTruth();
        Notification::fake();

        // The three things now disagree: the object names B's payment, the
        // metadata claims A, and the provider's own record of B says no
        // money arrived. Believing any two of the three would pay an order
        // nobody paid for.
        $event = $this->deliverForgedObject('payment_intent.succeeded', [
            'id' => $referenceB,
            'status' => 'succeeded',
            'amount' => $orderA->grand_total_minor,
            'amount_received' => $orderA->grand_total_minor,
            'currency' => strtolower($orderA->currency),
            'metadata' => [
                'marketplace_order_reference' => $orderA->reference,
                'payment_reference' => $referenceA,
            ],
        ]);

        $this->assertNotSame(ProviderEventStatus::Failed, $event->refresh()->status);

        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $orderA->refresh()->status);
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $orderB->refresh()->status);
        $this->assertSame(0, Payment::query()->count());

        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($truth, 'attack 11 (metadata mismatch)');
    }

    // ── Attack 17 ─────────────────────────────────────────────────────

    #[Test]
    public function no_public_request_field_can_carry_a_provider_verdict(): void
    {
        ['order' => $order, 'customer' => $customer] = $this->payable();

        $truth = $this->financialTruth();
        Notification::fake();

        // Every field name a payment integration might plausibly read from
        // a request, posted at every customer-facing payment endpoint.
        $verdict = [
            'provider_status' => 'succeeded',
            'payment_status' => 'paid',
            'status' => 'paid',
            'succeeded' => true,
            'captured' => true,
            'is_paid' => true,
            'paid_at' => now()->toIso8601String(),
            'succeeded_at' => now()->toIso8601String(),
            'provider_reference' => 'fake_pi_attacker_chosen',
            'provider_charge_id' => 'fake_ch_attacker_chosen',
            'amount_minor' => 1,
            'captured_amount_minor' => 1,
        ];

        $this->actingAs($customer)
            ->postJson("/checkout/{$order->reference}/payment/prepare", $verdict)
            ->assertOk();
        $this->actingAs($customer)
            ->getJson("/checkout/{$order->reference}/payment/status?".http_build_query($verdict))
            ->assertOk();

        /** @var PaymentAttempt $attempt */
        $attempt = PaymentAttempt::query()->where('marketplace_order_id', $order->id)->firstOrFail();

        $this->assertNotSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNull($attempt->succeeded_at);
        $this->assertNotSame('fake_pi_attacker_chosen', $attempt->provider_reference);
        $this->assertSame($order->grand_total_minor, $attempt->amount_minor);

        $this->assertNotPaid($order, 'attack 17');
        Notification::assertNothingSent();
        $this->assertFinancialTruthUnchanged($truth, 'attack 17 (fabricated provider status)');
    }

    #[Test]
    public function payment_preparation_accepts_nothing_but_an_order(): void
    {
        // The structural companion to the test above. A controller can only
        // pass through what the action agrees to receive, so the narrow
        // place to hold the line is the action's signature: PreparePayment
        // reads the amount, the currency and the provider from the order
        // it is handed. The day it grows a second parameter, whoever adds
        // it has to come here and say why.
        $parameters = array_map(
            static fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(PreparePayment::class, '__invoke'))->getParameters(),
        );

        $this->assertSame(['order'], $parameters);
    }

    // ── Attack 16 ─────────────────────────────────────────────────────

    #[Test]
    public function knowing_another_customers_order_number_buys_nothing(): void
    {
        ['order' => $order, 'reference' => $reference] = $this->payable('a');
        $stranger = User::factory()->create(['email' => 'stranger@example.test']);

        $truth = $this->financialTruth();

        // Everything customer B could know: the order number off an email,
        // the attempt reference, the payment URL itself. References are
        // short and sequential, which is exactly why they are not
        // authorisation.
        $this->actingAs($stranger)->get("/checkout/{$order->reference}/payment")->assertNotFound();
        $this->actingAs($stranger)->getJson("/checkout/{$order->reference}/payment/status")->assertNotFound();
        $this->actingAs($stranger)->postJson("/checkout/{$order->reference}/payment/prepare")->assertNotFound();
        $this->actingAs($stranger)->get("/account/orders/{$order->reference}")->assertNotFound();

        // And as a guest with no session link to the order.
        $this->get("/checkout/{$order->reference}/payment")->assertNotFound();
        $this->getJson("/checkout/{$order->reference}/payment/status")->assertNotFound();

        $this->assertNotPaid($order, 'attack 16');
        $this->assertFinancialTruthUnchanged($truth, 'attack 16 (foreign customer)');
        $this->assertNotSame('', $reference);
    }
}

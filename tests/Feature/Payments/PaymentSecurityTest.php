<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use App\Modules\Payments\Models\Refund;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * §77 — the ten things a payment system must refuse.
 *
 * Every one of these is a way a marketplace ships goods for free, leaks
 * one customer's purchase to another, or lets somebody move money they
 * have no authority over. They are written as attacks rather than as
 * happy paths on purpose: a test that only proves the intended flow works
 * proves nothing about the unintended one.
 */
final class PaymentSecurityTest extends TestCase
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
    public function a_forged_signature_is_refused_and_nothing_is_stored(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $provider = $this->provider();
        $signed = $provider->signedEvent('payment_intent.succeeded', $provider->paymentObject($reference));

        // The right body, the wrong signature: an attacker who has watched
        // a real event and is replaying it with their own numbers.
        $this->call(
            'POST',
            '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
            content: $signed['payload'],
        )->assertStatus(400);

        $this->assertSame(0, ProviderWebhookEvent::query()->count(), 'An unverified body is not stored.');
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    #[Test]
    public function a_signature_from_a_different_secret_is_refused(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);

        $provider = $this->provider();
        $payload = (string) json_encode(
            ['id' => 'evt_forged', 'type' => 'payment_intent.succeeded', 'data' => ['object' => $provider->paymentObject($reference)]],
            JSON_THROW_ON_ERROR,
        );

        // Correctly formed HMAC — of the wrong secret. hash_equals is what
        // makes guessing it a matter of the key, not the timing.
        $forged = 'v1='.hash_hmac('sha256', $payload, 'not-the-signing-secret');

        $this->call(
            'POST',
            '/webhooks/payments',
            server: ['HTTP_STRIPE_SIGNATURE' => $forged, 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        )->assertStatus(400);

        $this->assertSame(0, ProviderWebhookEvent::query()->count());
    }

    #[Test]
    public function a_customer_cannot_mark_their_own_order_paid_by_any_route(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        ['reference' => $reference] = $this->prepare($order);

        // Everything a hopeful client could try: claiming the status on
        // the prepare call, claiming it on the status call, and posting
        // the provider's own success shape to the webhook unsigned.
        $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare", [
            'status' => 'succeeded',
            'paid' => true,
            'payment_intent' => $reference,
        ])->assertOk();

        $this->asUser($user)
            ->getJson("/checkout/{$order->reference}/payment/status?redirect_status=succeeded")
            ->assertOk()
            ->assertJsonPath('payment.isPaid', false);

        $this->postJson('/webhooks/payments', [
            'id' => 'evt_customer',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => $reference, 'status' => 'succeeded']],
        ])->assertStatus(400);

        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    #[Test]
    public function a_customer_cannot_choose_what_they_are_charged(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 12_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare", [
            'amount_minor' => 1,
            'amount' => 1,
            'grand_total_minor' => 1,
            'currency' => 'ZWL',
        ])->assertOk()->assertJsonPath('amount.minor', 12_000);

        $attempt = PaymentAttempt::query()->firstOrFail();

        $this->assertSame(12_000, $attempt->amount_minor);
        $this->assertSame('USD', $attempt->currency);
        $this->assertSame(12_000, $this->provider()->retrievePayment((string) $attempt->provider_reference)->amountMinor);
    }

    #[Test]
    public function a_customer_cannot_pay_for_or_read_another_customers_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 20);

        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $theirOrder = $this->placeOrder([[$offer, 1]], userId: $theirs->id);

        $this->asUser($mine)->get("/checkout/{$theirOrder->reference}/payment")->assertNotFound();
        $this->asUser($mine)->postJson("/checkout/{$theirOrder->reference}/payment/prepare")->assertNotFound();
        $this->asUser($mine)->getJson("/checkout/{$theirOrder->reference}/payment/status")->assertNotFound();
        $this->asUser($mine)->get("/account/orders/{$theirOrder->reference}")->assertNotFound();

        $this->assertSame(0, PaymentAttempt::query()->count());
    }

    #[Test]
    public function a_seller_cannot_reach_the_customers_payment_or_another_sellers(): void
    {
        ['offer' => $offerA, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 5);
        ['offer' => $offerB, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 9_000, stock: 5);

        $memberA = User::factory()->create();
        SellerMembership::factory()->create([
            'seller_account_id' => $sellerA->id,
            'user_id' => $memberA->id,
            'role' => SellerRole::Owner->value,
        ]);

        $order = $this->placeOrder([[$offerA, 1], [$offerB, 1]]);
        $this->payFor($order);

        $theirOrder = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('seller_account_id', $sellerB->id)
            ->firstOrFail();

        // The other seller's part of the same customer purchase.
        $this->asUser($memberA)->get("/seller/orders/{$theirOrder->reference}")->assertNotFound();

        // And no seller route reaches the payment surfaces at all.
        $this->asUser($memberA)->get('/admin/payments')->assertRedirect('/admin/login');
        $this->asUser($memberA)->get("/admin/payments/{$order->reference}")->assertRedirect('/admin/login');
    }

    #[Test]
    public function a_seller_cannot_trigger_a_refund(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 5);

        $member = User::factory()->create();
        SellerMembership::factory()->create([
            'seller_account_id' => $seller->id,
            'user_id' => $member->id,
            'role' => SellerRole::Owner->value,
        ]);

        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        $this->asUser($member)->post("/admin/payments/{$order->reference}/refunds", [
            'reason' => 'I would like my money back please.',
            'lines' => [['order_item_id' => $item->id, 'amount_minor' => 4_000]],
        ])->assertRedirect('/admin/login');

        $this->assertSame(0, Refund::query()->count());
    }

    #[Test]
    public function an_admin_without_the_refund_permission_cannot_refund(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();
        $body = [
            'reason' => 'Refunding on somebody else’s authority.',
            'lines' => [['order_item_id' => $item->id, 'amount_minor' => 4_000]],
        ];

        // An analyst reads the marketplace and moves none of its money;
        // support answers questions about an order and moves none either.
        foreach ([AdminRole::Analyst, AdminRole::Support, AdminRole::CatalogModerator] as $role) {
            $this->asAdmin($this->makeAdmin($role))
                ->post("/admin/payments/{$order->reference}/refunds", $body)
                ->assertForbidden();
        }

        $this->assertSame(0, Refund::query()->count());
    }

    #[Test]
    public function support_is_kept_out_of_the_provider_event_trail(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $support = $this->asAdmin($this->makeAdmin(AdminRole::Support))
            ->get("/admin/payments/{$order->reference}")
            ->assertOk();

        // Not hidden by CSS: the payload is never built for them.
        $support->assertInertia(fn ($page) => $page
            ->where('can.viewEvents', false)
            ->where('providerEvents', []));

        $finance = $this->asAdmin($this->makeAdmin(AdminRole::FinanceAdmin))
            ->get("/admin/payments/{$order->reference}")
            ->assertOk();

        $finance->assertInertia(fn ($page) => $page->where('can.viewEvents', true)->has('providerEvents.0'));
    }

    #[Test]
    public function a_replayed_verified_event_is_harmless(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $provider = $this->provider();
        $signed = $provider->signedEvent('payment_intent.succeeded', $provider->paymentObject($reference), 'evt_replay');

        // Byte-for-byte the same delivery, four times, with a valid
        // signature every time — which is what a provider retry is.
        foreach (range(1, 4) as $_) {
            $this->call(
                'POST',
                '/webhooks/payments',
                server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
                content: $signed['payload'],
            )->assertOk();
        }

        $this->assertSame(1, ProviderWebhookEvent::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(4_000, (int) Payment::query()->firstOrFail()->amount_minor);
    }

    #[Test]
    public function a_provider_reference_that_belongs_to_another_order_is_rejected(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 20);

        $mine = $this->placeOrder([[$offer, 1]]);
        $theirs = $this->placeOrder([[$offer, 2]]);

        ['reference' => $mineReference] = $this->prepare($mine);
        ['reference' => $theirReference] = $this->prepare($theirs);

        // Their payment succeeds, for their amount. If the platform
        // matched on the order rather than the reference, this would pay
        // my order with their money.
        $this->provider()->settle($theirReference, PaymentAttemptStatus::Succeeded);
        $this->deliverEvent('payment_intent.succeeded', $theirReference);

        $this->assertSame(MarketplaceOrderStatus::Paid, $theirs->refresh()->status);
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $mine->refresh()->status);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame($theirs->id, $payment->marketplace_order_id);
        $this->assertSame(8_000, $payment->amount_minor);
        $this->assertNotSame($mineReference, $theirReference);
    }

    #[Test]
    public function no_page_ever_serializes_a_provider_credential(): void
    {
        config([
            'veritas.payments.stripe.secret' => 'sk_test_never_leaves',
            'veritas.payments.stripe.webhook_secret' => 'whsec_never_leaves',
        ]);

        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $this->payFor($order);

        $pages = [
            $this->asUser($user)->get("/checkout/{$order->reference}/payment"),
            $this->asUser($user)->get("/account/orders/{$order->reference}"),
            $this->asAdmin($this->makeAdmin(AdminRole::FinanceAdmin))->get("/admin/payments/{$order->reference}"),
            $this->asAdmin($this->makeAdmin(AdminRole::FinanceAdmin))->get('/admin/payments'),
        ];

        foreach ($pages as $page) {
            $body = (string) $page->assertOk()->getContent();

            $this->assertStringNotContainsString('sk_test_never_leaves', $body);
            $this->assertStringNotContainsString('whsec_never_leaves', $body);
            $this->assertStringNotContainsString('sk_', $body);
            $this->assertStringNotContainsString('whsec_', $body);
            $this->assertStringNotContainsString('signature_fingerprint', $body);
        }
    }

    #[Test]
    public function every_transactional_payment_page_stays_out_of_search(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $this->asUser($user)->get("/checkout/{$order->reference}/payment")
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->asUser($user)->get("/account/orders/{$order->reference}")
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->asAdmin($this->makeAdmin(AdminRole::FinanceAdmin))->get('/admin/payments')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}

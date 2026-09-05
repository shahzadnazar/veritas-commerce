<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\CancelUnpaidOrder;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Payments\Data\ProviderFailure;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * The two endpoints a customer's browser is allowed to call.
 *
 * What they can do is narrow on purpose: start a payment for an amount the
 * browser never supplies, and ask what the platform believes. There is
 * deliberately no endpoint that accepts an outcome, so the tests that
 * matter most here are the ones about what these routes refuse.
 */
final class PaymentEndpointTest extends TestCase
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
    public function preparing_returns_a_client_secret_for_the_orders_own_total(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 3_450, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 2]], userId: $user->id);

        $response = $this->asUser($user)
            ->postJson("/checkout/{$order->reference}/payment/prepare")
            ->assertOk();

        $response->assertJsonPath('amount.minor', $order->grand_total_minor);
        $response->assertJsonPath('amount.minor', 6_900);
        $response->assertJsonPath('amount.currency', 'USD');
        $response->assertJsonPath('payment.state', 'awaiting_payment');

        $this->assertNotEmpty($response->json('clientSecret'));

        $attempt = PaymentAttempt::query()->firstOrFail();

        $this->assertSame($order->grand_total_minor, $attempt->amount_minor);
        $this->assertSame($order->id, $attempt->marketplace_order_id);
    }

    #[Test]
    public function a_client_supplied_amount_is_ignored_entirely(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 8_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        // Every field a hopeful client might send. None of them is read:
        // PreparePayment takes no amount parameter at all (§4).
        $this->asUser($user)
            ->postJson("/checkout/{$order->reference}/payment/prepare", [
                'amount' => 1,
                'amount_minor' => 1,
                'grand_total_minor' => 1,
                'currency' => 'ZWL',
                'status' => 'succeeded',
            ])
            ->assertOk()
            ->assertJsonPath('amount.minor', 8_000)
            ->assertJsonPath('amount.currency', 'USD');

        $attempt = PaymentAttempt::query()->firstOrFail();

        $this->assertSame(8_000, $attempt->amount_minor);
        $this->assertSame('USD', $attempt->currency);
        $this->assertSame(PaymentAttemptStatus::RequiresPaymentMethod, $attempt->status);
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
    }

    #[Test]
    public function preparing_twice_re_joins_the_same_payment(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $first = $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare")->assertOk();
        $second = $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare")->assertOk();

        // A refresh of the payment page must land on the same provider
        // payment; a second one would hold a second authorisation.
        $this->assertSame($first->json('attemptPublicId'), $second->json('attemptPublicId'));
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    #[Test]
    public function a_declined_payment_leaves_the_order_payable_and_a_retry_starts_a_fresh_attempt(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $first = $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare")->assertOk();

        $reference = (string) PaymentAttempt::query()->firstOrFail()->provider_reference;

        $this->provider()->settle(
            $reference,
            PaymentAttemptStatus::Failed,
            failure: new ProviderFailure('card_declined', 'generic_decline', 'Your card was declined.'),
        );

        $this->deliverEvent('payment_intent.payment_failed', $reference);

        // §20: a decline is a customer reaching for another card, not an
        // abandoned purchase. The order and its held stock survive it.
        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);

        $status = $this->asUser($user)->getJson("/checkout/{$order->reference}/payment/status")->assertOk();

        $status->assertJsonPath('payment.state', 'failed');
        $status->assertJsonPath('payment.canRetry', true);
        $status->assertJsonPath('payment.isPaid', false);

        // The customer's words, not the provider's: no decline code.
        $this->assertStringNotContainsStringIgnoringCase('card_declined', (string) $status->json('payment.detail'));
        $this->assertStringNotContainsStringIgnoringCase('declined', (string) $status->json('payment.detail'));

        $retry = $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare")->assertOk();

        $this->assertNotSame($first->json('attemptPublicId'), $retry->json('attemptPublicId'));
        $this->assertSame(2, PaymentAttempt::query()->count(), 'Two tries, two rows — the failure is kept.');
    }

    #[Test]
    public function the_status_endpoint_never_believes_the_url(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare")->assertOk();

        // Exactly what Stripe appends to a return URL, and exactly what a
        // customer could type themselves.
        $this->asUser($user)
            ->getJson("/checkout/{$order->reference}/payment/status?redirect_status=succeeded&payment_intent=pi_forged")
            ->assertOk()
            ->assertJsonPath('orderStatus', 'pending_payment')
            ->assertJsonPath('payment.isPaid', false)
            ->assertJsonPath('payment.state', 'awaiting_payment');

        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
    }

    #[Test]
    public function a_paid_order_reports_paid_and_cannot_be_prepared_again(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $this->payFor($order);

        $this->asUser($user)->getJson("/checkout/{$order->reference}/payment/status")
            ->assertOk()
            ->assertJsonPath('payment.isPaid', true)
            ->assertJsonPath('payment.state', 'paid')
            ->assertJsonPath('payment.canPay', false);

        $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'already_paid');

        $this->assertSame(1, PaymentAttempt::query()->count(), 'No second attempt against a paid order.');
    }

    #[Test]
    public function a_cancelled_order_cannot_be_prepared(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        app(CancelUnpaidOrder::class)($order->refresh(), 'expired');

        $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'order_not_payable')
            ->assertJsonPath('payment.state', 'cancelled');

        $this->assertSame(0, PaymentAttempt::query()->count());
    }

    #[Test]
    public function a_provider_outage_leaves_the_order_payable(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $this->provider()->goOffline();

        $response = $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare")
            ->assertStatus(503)
            ->assertJsonPath('reason', 'provider_unavailable');

        // §66: an outage is not a decline. Nothing is said about a card.
        $this->assertStringNotContainsStringIgnoringCase('declined', (string) $response->json('message'));

        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(
            'awaiting_payment',
            $this->asUser($user)->getJson("/checkout/{$order->reference}/payment/status")->json('payment.state'),
        );
    }

    #[Test]
    public function neither_endpoint_is_reachable_for_someone_elses_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 20);
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $theirOrder = $this->placeOrder([[$offer, 1]], userId: $theirs->id);

        // 404 rather than 403 throughout: a reference is printed on
        // emails and packing slips, and confirming one exists is itself
        // the leak.
        $this->asUser($mine)->postJson("/checkout/{$theirOrder->reference}/payment/prepare")->assertNotFound();
        $this->asUser($mine)->getJson("/checkout/{$theirOrder->reference}/payment/status")->assertNotFound();

        $this->postJson("/checkout/{$theirOrder->reference}/payment/prepare")->assertNotFound();
        $this->getJson("/checkout/{$theirOrder->reference}/payment/status")->assertNotFound();

        $this->assertSame(0, PaymentAttempt::query()->count());
    }

    #[Test]
    public function no_response_carries_a_provider_secret(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        config([
            'veritas.payments.stripe.secret' => 'sk_test_never_leaves',
            'veritas.payments.stripe.webhook_secret' => 'whsec_never_leaves',
            'veritas.payments.stripe.key' => 'pk_test_may_leave',
        ]);

        $prepare = $this->asUser($user)->postJson("/checkout/{$order->reference}/payment/prepare")->assertOk();

        /*
         * The client secret is the one provider value that must leave —
         * Elements cannot mount without it — and it authorises confirming
         * one payment for one amount. The secret key and the webhook
         * signing secret authorise everything, and never appear.
         */
        $body = (string) $prepare->getContent();

        $this->assertStringNotContainsString('sk_test_never_leaves', $body);
        $this->assertStringNotContainsString('whsec_never_leaves', $body);

        /*
         * Matched on the real key prefixes rather than on `sk_` alone: a
         * provider reference is a ULID, and one of those will eventually
         * contain those two letters followed by an underscore by pure
         * chance — which is a test that fails on a coin flip rather than
         * on a leak.
         */
        $this->assertDoesNotMatchRegularExpression('/sk_(test|live)_/', $body);
        $this->assertStringNotContainsString('whsec_', $body);

        $status = (string) $this->asUser($user)
            ->getJson("/checkout/{$order->reference}/payment/status")
            ->getContent();

        // The polling endpoint has no reason to carry one at all.
        $this->assertStringNotContainsString('clientSecret', $status);
        $this->assertStringNotContainsString('secret', $status);
    }

    #[Test]
    public function the_payment_page_carries_no_client_secret(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $page = $this->asUser($user)->get("/checkout/{$order->reference}/payment")->assertOk();

        // The page asks for a secret when the customer is ready to pay, so
        // one is never baked into HTML a back button or a screenshot holds.
        $this->assertStringNotContainsString('clientSecret', (string) $page->getContent());
        $this->assertSame(0, PaymentAttempt::query()->count(), 'Loading the page prepares nothing.');
    }
}

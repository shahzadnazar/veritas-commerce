<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Events\Models\InteractionEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Data\ProviderFailure;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * Who sees what about a payment, and what the stream records.
 *
 * Three audiences and three different truths: the customer reads the
 * platform's own words, the seller learns two facts and no more, and the
 * behavioural stream gets the numbers. The provider's vocabulary —
 * references, decline codes, payload — reaches none of them.
 */
final class PaymentVisibilityTest extends TestCase
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
    public function a_customers_order_page_says_what_happened_to_their_money(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $this->asUser($user)->get("/account/orders/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('payment.isPaid', false)
                ->where('payment.canPay', true)
                ->where('payment.headline', 'Your order is ready to pay for.'));

        $this->payFor($order);

        $this->asUser($user)->get("/account/orders/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('payment.isPaid', true)
                ->where('payment.state', 'paid')
                ->where('payment.canPay', false));
    }

    #[Test]
    public function a_declined_customer_reads_the_platforms_words_and_never_the_providers(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        ['reference' => $reference] = $this->prepare($order);

        $this->provider()->settle(
            $reference,
            PaymentAttemptStatus::Failed,
            failure: new ProviderFailure('expired_card', 'expired_card', 'Your card has expired.'),
        );

        $this->deliverEvent('payment_intent.payment_failed', $reference);

        $response = $this->asUser($user)->get("/account/orders/{$order->reference}")->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('payment.state', 'failed')
            ->where('payment.canRetry', true)
            ->where(
                'payment.detail',
                'That card has expired. Please try another payment method. Your items are still held for you.',
            ));

        // The code is on the attempt, for the operator. It is not on the
        // page, and neither is the provider's own sentence.
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('expired_card', $body);
        $this->assertStringNotContainsString('Your card has expired.', $body);
        $this->assertStringNotContainsString((string) PaymentAttempt::query()->firstOrFail()->provider_reference, $body);

        $this->assertSame('expired_card', PaymentAttempt::query()->firstOrFail()->failure_code);
    }

    #[Test]
    public function a_seller_learns_that_the_money_cleared_and_nothing_about_the_card(): void
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

        $sellerOrder = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->firstOrFail();

        $response = $this->asUser($member)
            ->get("/seller/orders/{$sellerOrder->reference}")
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('payment.isPaid', true)
            ->where('fulfilment.actionable', true)
            ->has('payment.paidAt'));

        $body = (string) $response->getContent();
        $attempt = PaymentAttempt::query()->firstOrFail();

        // Not the provider's reference, not the method, not the customer's
        // email: a seller sees that they were paid, not how.
        $this->assertStringNotContainsString((string) $attempt->provider_reference, $body);
        $this->assertStringNotContainsString('Visa ending', $body);
        $this->assertStringNotContainsString($order->email, $body);
    }

    #[Test]
    public function a_purchase_is_recorded_once_per_seller_with_that_sellers_own_value(): void
    {
        ['offer' => $offerA, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 10_000, stock: 5);
        ['offer' => $offerB, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 6_000, stock: 5);

        $user = User::factory()->create();
        $order = $this->placeOrder([[$offerA, 1], [$offerB, 1]], userId: $user->id);

        $this->payFor($order);

        $purchases = InteractionEvent::query()
            ->where('event_type', InteractionEventType::PurchaseCompleted->value)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $purchases, 'Two sellers made two sales, not one.');

        $byValue = $purchases->pluck('value_minor', 'seller_account_id');

        $this->assertSame(10_000, (int) $byValue[$sellerA->id]);
        $this->assertSame(6_000, (int) $byValue[$sellerB->id]);

        // Attribution survives the webhook: the payment is decided in a
        // job with no session, so the order carries who bought it.
        $this->assertSame($user->id, $purchases[0]?->user_id);
    }

    #[Test]
    public function a_replayed_success_does_not_record_a_second_purchase(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        $event = $this->deliverEvent('payment_intent.succeeded', $reference);
        $this->deliverEvent('payment_intent.succeeded', $reference, eventId: 'evt_replay');
        $this->reprocess($event);

        $this->assertSame(
            1,
            InteractionEvent::query()->where('event_type', InteractionEventType::PurchaseCompleted->value)->count(),
        );
    }

    #[Test]
    public function a_decline_is_recorded_with_its_code_for_analysis_only(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        ['reference' => $reference] = $this->prepare($order);

        $this->provider()->settle(
            $reference,
            PaymentAttemptStatus::Failed,
            failure: new ProviderFailure('insufficient_funds', null, 'Insufficient funds.'),
        );

        $this->deliverEvent('payment_intent.payment_failed', $reference);

        $recorded = InteractionEvent::query()
            ->where('event_type', InteractionEventType::PaymentFailed->value)
            ->firstOrFail();

        $this->assertSame(4_000, (int) $recorded->value_minor);
        $this->assertSame('insufficient_funds', $recorded->metadata['failure_code'] ?? null);
        $this->assertSame($order->reference, $recorded->metadata['order_reference'] ?? null);

        // Nothing was purchased, and the stream says so.
        $this->assertSame(
            0,
            InteractionEvent::query()->where('event_type', InteractionEventType::PurchaseCompleted->value)->count(),
        );
    }
}

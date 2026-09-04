<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Notifications\OrderPaidNotification;
use App\Modules\Payments\Notifications\SellerOrderPaidNotification;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\TestCase;

/**
 * Who is told that money arrived, and how many times.
 *
 * §26 and §27. Both halves are commercial promises before they are
 * technical ones: a customer who gets four receipts for one order stops
 * trusting the receipts, and a seller told to pack an unpaid order either
 * ships for nothing or learns to ignore the mail.
 */
final class PaymentNotificationTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
        Notification::fake();
    }

    #[Test]
    public function nothing_is_sent_before_the_payment_is_verified(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $this->member($seller->id);

        $order = $this->placeOrder([[$offer, 1]]);

        // Placed, prepared, and the customer is staring at a card form.
        // Not one word to anybody: no money has moved.
        $this->prepare($order);

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_seller_is_not_told_about_an_order_that_only_reached_processing(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        $this->member($seller->id);

        $order = $this->placeOrder([[$offer, 1]]);
        ['reference' => $reference] = $this->prepare($order);

        $this->provider()->settle($reference, PaymentAttemptStatus::Processing);
        $this->deliverEvent('payment_intent.processing', $reference);

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_customer_is_confirmed_once_when_the_payment_verifies(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_500, stock: 5);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 2]], userId: $user->id);

        $this->payFor($order);

        Notification::assertSentToTimes($user, OrderPaidNotification::class, 1);

        Notification::assertSentTo(
            $user,
            OrderPaidNotification::class,
            function (OrderPaidNotification $notification) use ($order): bool {
                $this->assertSame($order->reference, $notification->orderReference);
                $this->assertSame($order->grand_total_minor, $notification->total->minor);
                $this->assertSame(1, $notification->sellerCount);

                return true;
            },
        );
    }

    #[Test]
    public function a_redelivered_event_does_not_send_a_second_receipt(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_500, stock: 5);
        ['user' => $member] = $this->member($seller->id);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        ['reference' => $reference] = $this->prepare($order);
        $this->provider()->settle($reference, PaymentAttemptStatus::Succeeded);

        // The same success, five times: a provider retry, a resent event,
        // an operator replaying the webhook. One receipt.
        $first = $this->deliverEvent('payment_intent.succeeded', $reference);
        $this->deliverEvent('payment_intent.succeeded', $reference, eventId: 'evt_again_1');
        $this->deliverEvent('payment_intent.succeeded', $reference, eventId: 'evt_again_2');
        $this->reprocess($first);
        $this->reprocess($first);

        Notification::assertSentToTimes($user, OrderPaidNotification::class, 1);
        Notification::assertSentToTimes($member, SellerOrderPaidNotification::class, 1);
    }

    #[Test]
    public function a_guest_receives_the_receipt_at_the_address_they_gave(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 2_500, stock: 5);
        $order = $this->placeOrder([[$offer, 1]], email: 'guest@example.test');

        $this->payFor($order);

        Notification::assertSentTo(
            new AnonymousNotifiable,
            OrderPaidNotification::class,
            static fn (OrderPaidNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'guest@example.test',
        );
    }

    #[Test]
    public function each_seller_is_told_about_their_own_order_and_no_one_elses(): void
    {
        ['offer' => $offerA, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 5);
        ['offer' => $offerB, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 9_000, stock: 5);

        ['user' => $memberA] = $this->member($sellerA->id);
        ['user' => $memberB] = $this->member($sellerB->id);

        $order = $this->placeOrder([[$offerA, 1], [$offerB, 1]]);

        $this->payFor($order);

        $ordersBySeller = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->get()
            ->keyBy('seller_account_id');

        $this->assertCount(2, $ordersBySeller);

        $this->assertSentSellerOrder($memberA, (string) $ordersBySeller[$sellerA->id]?->reference, 4_000);
        $this->assertSentSellerOrder($memberB, (string) $ordersBySeller[$sellerB->id]?->reference, 9_000);

        // The other half of §27: neither seller learns what the customer
        // bought from the other, not even the reference of that order.
        Notification::assertSentToTimes($memberA, SellerOrderPaidNotification::class, 1);
        Notification::assertSentToTimes($memberB, SellerOrderPaidNotification::class, 1);
    }

    #[Test]
    public function only_members_who_can_see_orders_are_mailed_about_one(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 5);

        ['user' => $owner] = $this->member($seller->id, SellerRole::Owner);
        ['user' => $fulfilment] = $this->member($seller->id, SellerRole::FulfillmentManager);
        ['user' => $catalogue] = $this->member($seller->id, SellerRole::CatalogManager);
        ['user' => $invited] = $this->member($seller->id, SellerRole::Owner, accepted: false);

        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        Notification::assertSentToTimes($owner, SellerOrderPaidNotification::class, 1);
        Notification::assertSentToTimes($fulfilment, SellerOrderPaidNotification::class, 1);

        // A catalogue manager does not pack boxes and cannot open the
        // order; mailing them about it is noise they cannot act on.
        Notification::assertNotSentTo($catalogue, SellerOrderPaidNotification::class);

        // Invited but never accepted: not yet part of the store.
        Notification::assertNotSentTo($invited, SellerOrderPaidNotification::class);
    }

    #[Test]
    public function both_confirmations_are_queued_rather_than_sent_in_the_request(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 5);
        ['user' => $member] = $this->member($seller->id);
        $user = User::factory()->create();

        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $this->payFor($order);

        /*
         * §65: mail never runs inline in the webhook. A mail provider
         * timing out must not fail the request that told us money arrived,
         * and it must not sit on the payments queue either.
         */
        Notification::assertSentTo($user, OrderPaidNotification::class, static fn (OrderPaidNotification $n): bool => $n->queue === Queues::EMAILS);

        Notification::assertSentTo($member, SellerOrderPaidNotification::class, static fn (SellerOrderPaidNotification $n): bool => $n->queue === Queues::EMAILS);
    }

    private function assertSentSellerOrder(User $member, string $reference, int $totalMinor): void
    {
        Notification::assertSentTo(
            $member,
            SellerOrderPaidNotification::class,
            function (SellerOrderPaidNotification $notification) use ($reference, $totalMinor): bool {
                $this->assertSame($reference, $notification->sellerOrderReference);
                $this->assertSame($totalMinor, $notification->orderTotal->minor);

                return true;
            },
        );
    }

    /** @return array{user: User} */
    private function member(int $sellerId, SellerRole $role = SellerRole::Owner, bool $accepted = true): array
    {
        $user = User::factory()->create();

        SellerMembership::factory()->create([
            'seller_account_id' => $sellerId,
            'user_id' => $user->id,
            'role' => $role->value,
            'accepted_at' => $accepted ? now() : null,
        ]);

        return ['user' => $user];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Support\StatusRegistry;
use App\Support\StatusTransitions;
use BackedEnum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Workflow states are declared, not scattered as strings.
 *
 * Each enum owns its own allowed transitions, so there is exactly one
 * answer to "can this move there", and these tests walk every case rather
 * than trusting that the tables were written carefully.
 */
final class StateMachineTest extends TestCase
{
    #[Test]
    public function every_state_machine_declares_reachable_transitions(): void
    {
        foreach (StatusRegistry::stateMachines() as $domain => $enum) {
            foreach ($enum::cases() as $case) {
                /** @var StatusTransitions&BackedEnum $case */
                $targets = $case->allowedTransitions();

                foreach ($targets as $target) {
                    $this->assertInstanceOf(
                        $enum,
                        $target,
                        "{$domain}.{$case->value} transitions to a value outside its own enum.",
                    );
                }

                if ($case->isTerminal()) {
                    $this->assertSame(
                        [],
                        $targets,
                        "{$domain}.{$case->value} is terminal but declares transitions out of it.",
                    );
                }
            }
        }
    }

    #[Test]
    public function no_state_transitions_to_itself(): void
    {
        foreach (StatusRegistry::stateMachines() as $domain => $enum) {
            foreach ($enum::cases() as $case) {
                /** @var StatusTransitions&BackedEnum $case */
                $this->assertNotContains(
                    $case,
                    $case->allowedTransitions(),
                    "{$domain}.{$case->value} lists itself as a transition.",
                );
            }
        }
    }

    #[Test]
    public function every_non_initial_state_is_reachable_from_somewhere(): void
    {
        foreach (StatusRegistry::stateMachines() as $domain => $enum) {
            $reachable = [];

            foreach ($enum::cases() as $case) {
                foreach ($case->allowedTransitions() as $target) {
                    $reachable[$target->value] = true;
                }
            }

            $cases = $enum::cases();
            // The first case is the entry state and needs no inbound edge.
            $shouldBeReachable = array_slice($cases, 1);

            foreach ($shouldBeReachable as $case) {
                $this->assertArrayHasKey(
                    $case->value,
                    $reachable,
                    "{$domain}.{$case->value} can never be reached from any other state.",
                );
            }
        }
    }

    #[Test]
    public function a_seller_order_cannot_skip_from_paid_to_delivered(): void
    {
        $this->assertNotContains(
            SellerOrderStatus::Delivered,
            SellerOrderStatus::Paid->allowedTransitions(),
            'Fulfilment cannot jump straight to delivered.',
        );

        $this->assertContains(SellerOrderStatus::Confirmed, SellerOrderStatus::Paid->allowedTransitions());
    }

    #[Test]
    public function a_customer_can_cancel_until_the_order_is_packed(): void
    {
        $this->assertTrue(SellerOrderStatus::Processing->isCustomerCancellable());
        $this->assertFalse(SellerOrderStatus::Packed->isCustomerCancellable());
        $this->assertFalse(SellerOrderStatus::Shipped->isCustomerCancellable());
    }

    #[Test]
    public function shipping_declares_that_it_requires_tracking(): void
    {
        /*
         * Tracking belongs to the parcel, not the seller order: an order
         * can go out in two boxes with two carriers, and a single tracking
         * requirement on the order could only describe one of them.
         */
        $this->assertTrue(ShipmentStatus::Shipped->hasLeft());
        $this->assertFalse(ShipmentStatus::Ready->hasLeft());
        $this->assertTrue(ShipmentStatus::Draft->contentsAreMutable());
        $this->assertFalse(ShipmentStatus::Shipped->contentsAreMutable());
    }

    #[Test]
    public function earning_clears_at_delivered_not_at_capture(): void
    {
        /*
         * M0 assumed the earning would be *posted* on delivery. M5
         * settled it differently and better: the earning is recorded the
         * moment payment is verified, from the purchase snapshot, and sits
         * PENDING — so the money is on the books from the start and
         * nothing has to be recomputed later. What delivery starts is the
         * clearing clock, which is what this asserts.
         */
        $clearing = array_filter(
            SellerOrderStatus::cases(),
            static fn (SellerOrderStatus $s): bool => $s->startsEarningsClearing(),
        );

        $this->assertSame(
            [SellerOrderStatus::Delivered],
            array_values($clearing),
            'Delivery starts clearing; payment recorded the money, and a partial delivery starts nothing.',
        );

        $this->assertFalse(SellerOrderStatus::Paid->startsEarningsClearing());
        $this->assertFalse(SellerOrderStatus::PartiallyDelivered->startsEarningsClearing());
    }

    #[Test]
    public function fulfilment_is_impossible_before_payment(): void
    {
        $this->assertFalse(SellerOrderStatus::PendingPayment->isActionable());
        $this->assertTrue(SellerOrderStatus::Paid->isActionable());

        // Nothing leaves a cancelled or fully refunded order either.
        $this->assertFalse(SellerOrderStatus::Cancelled->isActionable());
        $this->assertFalse(SellerOrderStatus::Refunded->isActionable());
    }

    #[Test]
    public function a_shipment_cannot_leave_a_terminal_state(): void
    {
        $this->assertSame([], ShipmentStatus::Delivered->allowedTransitions());
        $this->assertSame([], ShipmentStatus::Cancelled->allowedTransitions());
        $this->assertTrue(ShipmentStatus::Delivered->isTerminal());

        // And a parcel cannot be delivered without having been sent.
        $this->assertNotContains(ShipmentStatus::Delivered, ShipmentStatus::Draft->allowedTransitions());
        $this->assertNotContains(ShipmentStatus::Delivered, ShipmentStatus::Ready->allowedTransitions());
    }

    #[Test]
    public function a_rejected_or_failed_payout_must_record_a_reason(): void
    {
        $this->assertTrue(PayoutStatus::Rejected->requiresReason());
        $this->assertTrue(PayoutStatus::Failed->requiresReason());
        $this->assertFalse(PayoutStatus::Approved->requiresReason());
    }

    #[Test]
    public function an_open_payout_holds_the_balance_and_a_decided_one_does_not(): void
    {
        $this->assertTrue(PayoutStatus::Requested->holdsBalance());
        $this->assertTrue(PayoutStatus::Approved->holdsBalance());
        $this->assertFalse(PayoutStatus::Rejected->holdsBalance());
        $this->assertFalse(PayoutStatus::Cancelled->holdsBalance());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Orders\Enums\SellerOrderStatus;
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
        $this->assertTrue(SellerOrderStatus::Shipped->requiresTracking());
        $this->assertFalse(SellerOrderStatus::Packed->requiresTracking());
    }

    #[Test]
    public function earning_posts_at_delivered_not_at_capture(): void
    {
        $posting = array_filter(
            SellerOrderStatus::cases(),
            static fn (SellerOrderStatus $s): bool => $s->postsEarning(),
        );

        $this->assertSame(
            [SellerOrderStatus::Delivered],
            array_values($posting),
            'Decision 5: the earning posts on delivery, then clears.',
        );
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

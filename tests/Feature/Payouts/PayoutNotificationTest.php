<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Identity\Models\User;
use App\Modules\Payouts\Actions\ApprovePayout;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Actions\RejectPayout;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Notifications\PayoutStatusNotification;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerMembership;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §61 and §113 — what the seller is told, once.
 *
 * Two things are proved throughout: the wording distinguishes approval
 * from payment, and a repeated action produces one message rather than
 * two. The second is not a check inside the listener — the actions refuse
 * a request that has already moved, so the event never fires twice.
 */
final class PayoutNotificationTest extends TestCase
{
    use BuildsSellerFinance;
    use RefreshDatabase;

    /** @return array{seller: SellerAccount, owner: User, payout: PayoutRequest} */
    private function requested(): array
    {
        ['seller' => $seller, 'user' => $owner] = $this->makeSeller();

        $this->availableEarning($seller, 100_000);
        $this->destination($seller);

        return [
            'seller' => $seller,
            'owner' => $owner,
            'payout' => $this->requestPayout($seller, 60_000),
        ];
    }

    #[Test]
    public function the_owner_is_told_when_a_payout_is_requested(): void
    {
        Notification::fake();

        ['owner' => $owner] = $this->requested();

        Notification::assertSentTo(
            $owner,
            PayoutStatusNotification::class,
            static function (PayoutStatusNotification $notification): bool {
                return $notification->kind === PayoutStatusNotification::REQUESTED
                    && $notification->amountMinor === 60_000;
            },
        );
    }

    #[Test]
    public function approval_says_approved_and_not_sent(): void
    {
        Notification::fake();

        ['owner' => $owner, 'payout' => $payout] = $this->requested();

        app(ApprovePayout::class)($payout, PayoutActor::admin(null));

        Notification::assertSentTo(
            $owner,
            PayoutStatusNotification::class,
            static fn (PayoutStatusNotification $n): bool => $n->kind === PayoutStatusNotification::APPROVED,
        );

        // The wording is the point: a seller told their money is on the
        // way when nobody has sent it will chase support for a week.
        $mail = (new PayoutStatusNotification(
            PayoutStatusNotification::APPROVED, 'PO-1', 60_000, 'USD',
        ))->toMail($owner);

        $rendered = implode(' ', array_map(
            static fn (mixed $line): string => is_string($line) ? $line : '',
            $mail->introLines,
        ));

        $this->assertStringContainsString('queued for settlement', $rendered);
        $this->assertStringContainsString('once the transfer has actually been made', $rendered);
    }

    #[Test]
    public function approving_twice_sends_one_message(): void
    {
        Notification::fake();

        ['owner' => $owner, 'payout' => $payout] = $this->requested();

        app(ApprovePayout::class)($payout, PayoutActor::admin(null, 'Ada'));
        app(ApprovePayout::class)($payout->refresh(), PayoutActor::admin(null, 'Bo'));

        Notification::assertSentToTimes($owner, PayoutStatusNotification::class, 2);

        // Two in total — the request and one approval — never three.
        $approvals = 0;

        Notification::assertSentTo(
            $owner,
            PayoutStatusNotification::class,
            static function (PayoutStatusNotification $n) use (&$approvals): bool {
                if ($n->kind === PayoutStatusNotification::APPROVED) {
                    $approvals++;
                }

                return true;
            },
        );

        $this->assertSame(1, $approvals);
    }

    #[Test]
    public function rejection_carries_the_reason_the_seller_reads(): void
    {
        Notification::fake();

        ['owner' => $owner, 'payout' => $payout] = $this->requested();

        app(RejectPayout::class)(
            $payout,
            PayoutActor::admin(null),
            'The destination name does not match your store.',
        );

        Notification::assertSentTo(
            $owner,
            PayoutStatusNotification::class,
            static fn (PayoutStatusNotification $n): bool => $n->kind === PayoutStatusNotification::REJECTED
                && $n->reason === 'The destination name does not match your store.',
        );
    }

    #[Test]
    public function settlement_carries_the_reference_and_no_credential(): void
    {
        Notification::fake();

        ['owner' => $owner, 'payout' => $payout] = $this->requested();

        app(ApprovePayout::class)($payout, PayoutActor::admin(null));
        app(RecordPayoutSettlement::class)($payout->refresh(), PayoutActor::admin(null), 'wire', 'FT-2026-9');

        Notification::assertSentTo(
            $owner,
            PayoutStatusNotification::class,
            static fn (PayoutStatusNotification $n): bool => $n->kind === PayoutStatusNotification::PAID
                && $n->settlementReference === 'FT-2026-9',
        );

        $mail = (new PayoutStatusNotification(
            PayoutStatusNotification::PAID, 'PO-1', 60_000, 'USD',
            settlementReference: 'FT-2026-9',
        ))->toMail($owner);

        $body = implode(' ', array_map(
            static fn (mixed $line): string => is_string($line) ? $line : '',
            $mail->introLines,
        ));

        $this->assertStringContainsString('$600.00', $body);
        $this->assertStringContainsString('FT-2026-9', $body);
        // Nothing that could move money, because the platform holds none.
        $this->assertDoesNotMatchRegularExpression('/account number|sort code|iban|routing/i', $body);
    }

    #[Test]
    public function only_owners_are_told(): void
    {
        Notification::fake();

        ['seller' => $seller, 'owner' => $owner] = $this->requested();

        $finance = User::factory()->create();

        SellerMembership::factory()->create([
            'seller_account_id' => $seller->id,
            'user_id' => $finance->id,
            'role' => SellerRole::FinanceManager->value,
        ]);

        app(ApprovePayout::class)(
            PayoutRequest::query()->withoutGlobalScopes()->firstOrFail(),
            PayoutActor::admin(null),
        );

        Notification::assertSentTo($owner, PayoutStatusNotification::class);
        Notification::assertNotSentTo($finance, PayoutStatusNotification::class);
    }

    #[Test]
    public function payout_notifications_go_to_the_emails_queue(): void
    {
        $notification = new PayoutStatusNotification(
            PayoutStatusNotification::PAID, 'PO-1', 1_000, 'USD',
        );

        // Queued rather than sent inline: a mail provider timing out must
        // not roll back a settlement that has posted a ledger debit.
        $this->assertSame(Queues::EMAILS, $notification->queue);
    }
}

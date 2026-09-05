<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The seller's finance screens over HTTP.
 *
 * What is proved here is not that the forms work but that the server
 * refuses everything the screen would never offer: an amount larger than
 * the balance, a role whose job is not to move money, a store that has
 * been suspended, and a destination change without the password.
 */
final class SellerPayoutHttpTest extends TestCase
{
    use BuildsSellerFinance;
    use RefreshDatabase;

    /** @return array{seller: SellerAccount, user: User} */
    private function store(SellerRole $role = SellerRole::Owner, int $availableMinor = 100_000): array
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller($role);

        if ($availableMinor > 0) {
            $this->availableEarning($seller, $availableMinor);
        }

        $this->destination($seller);

        return ['seller' => $seller, 'user' => $user];
    }

    #[Test]
    public function an_owner_sees_their_position_and_can_request_a_payout(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->store();

        $page = $this->actingAs($user)->get('/seller/earnings');
        $page->assertOk();

        $props = $page->viewData('page')['props'];

        $this->assertSame(100_000, $props['position']['availableMinor']);
        $this->assertSame(100_000, $props['position']['withdrawableMinor']);
        $this->assertTrue($props['eligibility']['canRequest']);

        $this->actingAs($user)
            ->post('/seller/payouts', ['amount_minor' => 60_000])
            ->assertRedirect();

        $payout = PayoutRequest::query()->withoutGlobalScopes()
            ->where('seller_account_id', $seller->id)
            ->firstOrFail();

        $this->assertSame(60_000, $payout->amount_minor);
        $this->assertSame(PayoutStatus::Requested, $payout->status);

        // And the screen now shows the reservation, not a bigger balance.
        $after = $this->actingAs($user)->get('/seller/payouts')->viewData('page')['props'];

        $this->assertSame(60_000, $after['position']['reservedMinor']);
        $this->assertSame(40_000, $after['position']['withdrawableMinor']);
        $this->assertFalse($after['eligibility']['canRequest']);
        $this->assertSame('open_payout_exists', $after['eligibility']['reason']);
    }

    #[Test]
    public function the_maximum_is_enforced_by_the_server_not_by_the_form(): void
    {
        ['user' => $user] = $this->store(availableMinor: 50_000);

        // The browser sends whatever it likes; the balance is read under a
        // lock on the server and this is refused there.
        $this->actingAs($user)
            ->post('/seller/payouts', ['amount_minor' => 500_000])
            ->assertSessionHasErrors('amount_minor');

        $this->assertSame(0, PayoutRequest::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function zero_and_negative_requests_are_refused(): void
    {
        ['user' => $user] = $this->store();

        foreach ([0, -1_000] as $amount) {
            $this->actingAs($user)
                ->post('/seller/payouts', ['amount_minor' => $amount])
                ->assertSessionHasErrors('amount_minor');
        }

        $this->assertSame(0, PayoutRequest::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_request_below_the_minimum_is_refused(): void
    {
        ['user' => $user] = $this->store();

        $this->actingAs($user)
            ->post('/seller/payouts', ['amount_minor' => 100])
            ->assertSessionHasErrors('amount_minor');
    }

    #[Test]
    public function a_suspended_store_cannot_request_but_keeps_its_history(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->store();

        $seller->forceFill(['status' => 'suspended'])->save();

        // §19: the finance history is not hidden.
        $page = $this->actingAs($user)->get('/seller/earnings');
        $page->assertOk();

        $props = $page->viewData('page')['props'];
        $this->assertSame(100_000, $props['position']['availableMinor']);
        $this->assertFalse($props['eligibility']['canRequest']);
        $this->assertSame('seller_not_eligible', $props['eligibility']['reason']);

        // The write is refused by the permission gate, which withholds
        // every seller write from a suspended store.
        $this->actingAs($user)
            ->post('/seller/payouts', ['amount_minor' => 10_000])
            ->assertForbidden();
    }

    #[Test]
    public function only_the_owner_may_request_a_payout(): void
    {
        ['seller' => $seller] = $this->store();

        foreach ([SellerRole::Administrator, SellerRole::FinanceManager, SellerRole::Viewer] as $role) {
            $user = User::factory()->create();

            SellerMembership::factory()->create([
                'seller_account_id' => $seller->id,
                'user_id' => $user->id,
                'role' => $role->value,
            ]);

            $this->actingAs($user)
                ->post('/seller/payouts', ['amount_minor' => 10_000])
                ->assertForbidden();

            $this->assertSame(
                0,
                PayoutRequest::query()->withoutGlobalScopes()->count(),
                "A {$role->value} must not be able to move money.",
            );
        }
    }

    #[Test]
    public function a_finance_manager_reads_the_payouts_they_cannot_request(): void
    {
        ['seller' => $seller] = $this->store();

        $user = User::factory()->create();

        SellerMembership::factory()->create([
            'seller_account_id' => $seller->id,
            'user_id' => $user->id,
            'role' => SellerRole::FinanceManager->value,
        ]);

        $page = $this->actingAs($user)->get('/seller/payouts');
        $page->assertOk();

        $props = $page->viewData('page')['props'];

        $this->assertTrue($props['can']['viewPayouts']);
        $this->assertFalse($props['can']['requestPayout']);
        $this->assertFalse($props['eligibility']['canRequest']);
        $this->assertSame('permission_required', $props['eligibility']['reason']);
    }

    #[Test]
    public function a_catalogue_manager_cannot_even_read_the_payouts(): void
    {
        ['seller' => $seller] = $this->store();

        $user = User::factory()->create();

        SellerMembership::factory()->create([
            'seller_account_id' => $seller->id,
            'user_id' => $user->id,
            'role' => SellerRole::CatalogManager->value,
        ]);

        $this->actingAs($user)->get('/seller/payouts')->assertForbidden();
        $this->actingAs($user)->get('/seller/earnings')->assertForbidden();
    }

    #[Test]
    public function the_seller_can_cancel_before_review_and_gets_the_money_back(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->store();

        $payout = $this->requestPayout($seller, 60_000);

        $this->actingAs($user)
            ->post("/seller/payouts/{$payout->reference}/cancel")
            ->assertRedirect();

        $this->assertSame(PayoutStatus::Cancelled, $payout->refresh()->status);
        $this->assertSame(100_000, $this->positionOf($seller)->withdrawableMinor());

        // §26: the request is still there, with its reference and history.
        $this->assertSame(1, PayoutRequest::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function changing_the_payout_destination_requires_the_password(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->store();

        $user->forceFill(['password' => Hash::make('correct-horse')])->save();

        $this->actingAs($user)
            ->post('/seller/payouts/destination', [
                'display_label' => 'Somewhere else',
                'current_password' => 'not-the-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame(
            'Business account',
            PayoutAccount::query()->withoutGlobalScopes()
                ->where('seller_account_id', $seller->id)
                ->where('status', PayoutAccount::STATUS_ACTIVE)
                ->firstOrFail()
                ->display_label,
        );

        $this->actingAs($user)
            ->post('/seller/payouts/destination', [
                'display_label' => 'Somewhere else',
                'current_password' => 'correct-horse',
            ])
            ->assertRedirect();

        $this->assertSame(
            'Somewhere else',
            PayoutAccount::query()->withoutGlobalScopes()
                ->where('seller_account_id', $seller->id)
                ->where('status', PayoutAccount::STATUS_ACTIVE)
                ->firstOrFail()
                ->display_label,
        );

        // The old one is disabled, not deleted: a past payout must still
        // be able to say where it went.
        $this->assertSame(
            2,
            PayoutAccount::query()->withoutGlobalScopes()
                ->where('seller_account_id', $seller->id)->count(),
        );

        $this->assertTrue(
            AuditLog::query()->where('action', 'payouts.destination.changed')->exists(),
        );
    }

    #[Test]
    public function a_payout_snapshots_its_destination_and_a_later_change_does_not_rewrite_it(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->store();

        $user->forceFill(['password' => Hash::make('correct-horse')])->save();

        $payout = $this->requestPayout($seller, 60_000);
        $original = $payout->destination_label;

        $this->actingAs($user)->post('/seller/payouts/destination', [
            'display_label' => 'A different bank entirely',
            'current_password' => 'correct-horse',
        ]);

        // §58: the request still says where it was going when it was made.
        $this->assertSame($original, $payout->refresh()->destination_label);
        $this->assertStringContainsString('Business account', (string) $original);
    }

    #[Test]
    public function requesting_a_payout_is_audited(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->store();

        $this->actingAs($user)->post('/seller/payouts', ['amount_minor' => 60_000]);

        $log = AuditLog::query()->where('action', 'payouts.requested')->firstOrFail();

        /** @var array<string, mixed> $changes */
        $changes = $log->changes ?? [];

        $this->assertSame('seller_user', $log->actor_type);
        $this->assertSame((int) $user->id, (int) $log->actor_id);
        $this->assertSame(60_000, $changes['amount_minor'] ?? null);
        $this->assertSame((int) $seller->id, (int) PayoutRequest::query()
            ->withoutGlobalScopes()->firstOrFail()->seller_account_id);
    }
}

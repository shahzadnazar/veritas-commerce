<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Payouts\Actions\RequestPayout;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Payouts\Models\PayoutAllocation;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §32 and §76 — one seller's money is invisible to everybody else.
 *
 * Tested by manipulating the things an attacker actually has: the payout
 * reference in the URL, another store's payout-account id in a form, and
 * a session belonging to a customer or a different seller. Every one is
 * refused server-side; not one of these depends on a menu item being
 * hidden.
 *
 * The refusal is a 404 rather than a 403 throughout the seller portal.
 * "That payout exists but is not yours" is itself information.
 */
final class PayoutIsolationTest extends TestCase
{
    use BuildsSellerFinance;
    use RefreshDatabase;

    /** @return array{seller: SellerAccount, user: User, payout: PayoutRequest} */
    private function storeWithPayout(int $availableMinor = 100_000, int $requestMinor = 60_000): array
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();

        $this->availableEarning($seller, $availableMinor);
        $this->destination($seller);

        return [
            'seller' => $seller,
            'user' => $user,
            'payout' => $this->requestPayout($seller, $requestMinor),
        ];
    }

    #[Test]
    public function seller_a_cannot_open_seller_b_payout(): void
    {
        ['payout' => $theirs] = $this->storeWithPayout();
        ['user' => $mine] = $this->storeWithPayout();

        $this->actingAs($mine)
            ->get("/seller/payouts/{$theirs->reference}")
            ->assertNotFound();
    }

    #[Test]
    public function seller_a_cannot_cancel_seller_b_payout(): void
    {
        ['payout' => $theirs] = $this->storeWithPayout();
        ['user' => $mine] = $this->storeWithPayout();

        $this->actingAs($mine)
            ->post("/seller/payouts/{$theirs->reference}/cancel")
            ->assertNotFound();

        $this->assertSame('requested', $theirs->refresh()->status->value);
    }

    #[Test]
    public function a_payout_list_shows_only_the_sellers_own(): void
    {
        ['payout' => $theirs] = $this->storeWithPayout();
        ['user' => $mine, 'payout' => $ours] = $this->storeWithPayout();

        $props = $this->actingAs($mine)->get('/seller/payouts')->viewData('page')['props'];

        $references = array_column($props['payouts'], 'reference');

        $this->assertContains($ours->reference, $references);
        $this->assertNotContains($theirs->reference, $references);
    }

    #[Test]
    public function a_statement_shows_only_the_sellers_own_ledger(): void
    {
        ['seller' => $theirSeller] = $this->storeWithPayout();
        ['seller' => $mySeller, 'user' => $mine] = $this->storeWithPayout(availableMinor: 12_345, requestMinor: 6_000);

        $props = $this->actingAs($mine)->get('/seller/earnings')->viewData('page')['props'];

        $this->assertSame(12_345, $props['position']['availableMinor']);
        $this->assertNotSame(
            ($this->positionOf($theirSeller))->availableMinor,
            $props['position']['availableMinor'],
            'The two stores must not be reading one balance.',
        );

        // And the projection itself is scoped by the seller it is asked
        // about, not by whoever happens to be signed in.
        $this->assertSame(
            100_000,
            app(GetSellerFinancialPosition::class)($theirSeller->id)->availableMinor,
        );
        $this->assertSame(
            12_345,
            app(GetSellerFinancialPosition::class)($mySeller->id)->availableMinor,
        );
    }

    #[Test]
    public function a_seller_cannot_request_a_payout_to_another_stores_destination(): void
    {
        ['seller' => $theirSeller] = $this->storeWithPayout();
        ['seller' => $mySeller] = $this->storeWithPayout(requestMinor: 60_000);

        $theirDestination = PayoutAccount::query()->withoutGlobalScopes()
            ->where('seller_account_id', $theirSeller->id)
            ->firstOrFail();

        // Cancel the open one so the request is not refused for the wrong
        // reason, then ask for a payout naming somebody else's account.
        PayoutRequest::query()->withoutGlobalScopes()
            ->where('seller_account_id', $mySeller->id)
            ->update(['status' => 'cancelled']);

        PayoutAllocation::query()->withoutGlobalScopes()
            ->where('seller_account_id', $mySeller->id)
            ->update(['status' => 'released', 'released_at' => now()]);

        $payout = app(RequestPayout::class)(
            seller: $mySeller,
            amountMinor: 20_000,
            payoutAccountId: (int) $theirDestination->id,
        );

        // The id resolved to nothing, because the lookup is scoped to the
        // requesting seller — so no destination was attached, and in
        // particular NOT theirs.
        $this->assertNotSame((int) $theirDestination->id, (int) $payout->payout_account_id);
    }

    #[Test]
    public function a_customer_cannot_reach_any_seller_finance_page(): void
    {
        ['payout' => $payout] = $this->storeWithPayout();

        $customer = User::factory()->create();

        // A customer is a member of no store, so the membership gate turns
        // them away before any payout is resolved.
        $this->actingAs($customer)->get('/seller/earnings')->assertNotFound();
        $this->actingAs($customer)->get('/seller/payouts')->assertNotFound();
        $this->actingAs($customer)->get("/seller/payouts/{$payout->reference}")->assertNotFound();
        $this->actingAs($customer)->post('/seller/payouts', ['amount_minor' => 1_000])->assertNotFound();
    }

    #[Test]
    public function a_seller_cannot_reach_the_admin_payout_queue(): void
    {
        ['user' => $seller, 'payout' => $payout] = $this->storeWithPayout();

        $this->actingAs($seller)->get('/admin/payouts')->assertRedirect();
        $this->actingAs($seller)->get("/admin/payouts/{$payout->reference}")->assertRedirect();
        $this->actingAs($seller)
            ->post("/admin/payouts/{$payout->reference}/approve")
            ->assertRedirect();

        $this->assertSame('requested', $payout->refresh()->status->value);
    }

    #[Test]
    public function a_guessed_payout_reference_is_not_authorisation(): void
    {
        ['payout' => $payout] = $this->storeWithPayout();
        ['user' => $other] = $this->storeWithPayout();

        // §116: the reference is a human-readable sequence, so it is
        // guessable by design. It grants nothing.
        $this->assertStringStartsWith('PO-', $payout->reference);

        // A guest first, before any actingAs is in play for this test.
        $this->get("/seller/payouts/{$payout->reference}")->assertRedirect('/login');

        // And another store's owner, who is signed in and still gets
        // nothing — knowing the reference is not knowing whose it is.
        $this->actingAs($other)->get("/seller/payouts/{$payout->reference}")->assertNotFound();
    }

    #[Test]
    public function support_sees_a_payout_without_its_destination(): void
    {
        ['payout' => $payout] = $this->storeWithPayout();

        $support = $this->makeAdmin(AdminRole::Support);

        $props = $this->actingAs($support, 'admin')
            ->get("/admin/payouts/{$payout->reference}")
            ->viewData('page')['props'];

        // Support can answer "has it gone out yet"...
        $this->assertSame($payout->reference, $props['payout']['reference']);
        $this->assertFalse($props['can']['viewSensitive']);
        $this->assertFalse($props['can']['settle']);

        // ...and cannot see which account it goes to.
        $this->assertArrayNotHasKey('destination', $props['payout']);
    }

    #[Test]
    public function finance_pages_are_never_indexed(): void
    {
        ['user' => $user, 'payout' => $payout] = $this->storeWithPayout();
        $admin = $this->makeAdmin();

        foreach (['/seller/earnings', '/seller/payouts', "/seller/payouts/{$payout->reference}"] as $path) {
            $this->actingAs($user)->get($path)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        foreach (['/admin/payouts', "/admin/payouts/{$payout->reference}", '/admin/finance'] as $path) {
            $this->actingAs($admin, 'admin')->get($path)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}

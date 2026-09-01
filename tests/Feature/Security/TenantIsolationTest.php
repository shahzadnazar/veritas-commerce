<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerInvitation;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What happens when the request is made anyway.
 *
 * Every case here uses a real id belonging to somebody else and calls the
 * route directly. Navigation visibility is not security: the only proof is
 * that the server refuses.
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    #[Test]
    public function seller_a_cannot_read_seller_b_team(): void
    {
        ['user' => $a] = $this->makeSeller();
        ['seller' => $sellerB] = $this->makeSeller();

        $memberB = SellerMembership::factory()->create([
            'seller_account_id' => $sellerB->id,
            'role' => SellerRole::Viewer->value,
        ]);

        $response = $this->actingAs($a, 'web')->get('/seller/team');
        $response->assertOk();

        // A's own team page must not contain B's member, whatever the ids.
        $response->assertInertia(fn ($page) => $page
            ->component('Team/Index')
            ->where('members', function (mixed $members) use ($memberB): bool {
                $rows = $members instanceof Collection ? $members->all() : (array) $members;
                $ids = array_column($rows, 'id');

                // A's own owner is there; B's member is not.
                return $ids !== [] && ! in_array($memberB->id, $ids, true);
            }));
    }

    #[Test]
    public function seller_a_cannot_remove_a_member_of_seller_b(): void
    {
        ['user' => $a] = $this->makeSeller();
        ['seller' => $sellerB] = $this->makeSeller();

        $memberB = SellerMembership::factory()->create([
            'seller_account_id' => $sellerB->id,
            'role' => SellerRole::Viewer->value,
        ]);

        // 404 rather than 403: a 403 would confirm the id is real.
        $this->actingAs($a, 'web')
            ->delete("/seller/team/{$memberB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('seller_memberships', ['id' => $memberB->id]);
    }

    #[Test]
    public function seller_a_cannot_withdraw_an_invitation_of_seller_b(): void
    {
        ['user' => $a] = $this->makeSeller();
        ['seller' => $sellerB, 'user' => $b] = $this->makeSeller();

        $this->actingAs($b, 'web')->post('/seller/team/invitations', [
            'email' => 'b-colleague@example.com',
            'role' => SellerRole::Viewer->value,
        ]);

        $invitation = SellerInvitation::query()
            ->where('seller_account_id', $sellerB->id)
            ->firstOrFail();

        $this->actingAs($a, 'web')
            ->delete("/seller/team/invitations/{$invitation->public_id}")
            ->assertNotFound();

        $this->assertDatabaseHas('seller_invitations', [
            'id' => $invitation->id,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function seller_a_editing_the_store_never_touches_seller_b(): void
    {
        ['store' => $storeA, 'user' => $a] = $this->makeSeller();
        ['store' => $storeB] = $this->makeSeller();

        $originalB = $storeB->name;

        // There is no store id to tamper with, so the attempt is to plant
        // one in the payload and see whether it is believed.
        $this->actingAs($a, 'web')->post('/seller/store', [
            'id' => $storeB->id,
            'store_id' => $storeB->id,
            'seller_account_id' => $storeB->seller_account_id,
            'name' => 'Renamed by A',
            'slug' => 'renamed-by-a',
        ])->assertRedirect();

        $this->assertSame('Renamed by A', $storeA->fresh()?->name);
        $this->assertSame($originalB, $storeB->fresh()?->name);
    }

    #[Test]
    public function a_seller_cannot_see_another_sellers_store_through_their_own_portal(): void
    {
        ['user' => $a, 'store' => $storeA] = $this->makeSeller();
        ['store' => $storeB] = $this->makeSeller();

        $this->actingAs($a, 'web')
            ->get('/seller/store')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Store/Edit')
                ->where('store.slug', $storeA->slug));

        $this->assertNotSame($storeA->slug, $storeB->slug);
    }

    #[Test]
    public function a_customer_with_no_membership_cannot_reach_any_seller_screen(): void
    {
        $customer = User::factory()->create();

        foreach (['/seller', '/seller/store', '/seller/team'] as $route) {
            $this->actingAs($customer, 'web')->get($route)->assertNotFound();
        }

        $this->actingAs($customer, 'web')
            ->post('/seller/team/invitations', ['email' => 'x@example.com', 'role' => 'viewer'])
            ->assertNotFound();
    }

    #[Test]
    public function a_seller_session_does_not_open_the_admin_area(): void
    {
        ['user' => $seller] = $this->makeSeller();
        $application = SellerApplication::factory()->create();

        $this->actingAs($seller, 'web')->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs($seller, 'web')->get('/admin/applications')->assertRedirect('/admin/login');
        $this->actingAs($seller, 'web')
            ->post("/admin/applications/{$application->public_id}/approve")
            ->assertRedirect('/admin/login');

        $this->assertDatabaseMissing('seller_accounts', ['application_id' => $application->id]);
    }

    #[Test]
    public function an_admin_without_the_capability_is_refused_at_the_route(): void
    {
        $analyst = $this->makeAdmin(AdminRole::Analyst);
        $application = SellerApplication::factory()->create();

        $this->actingAs($analyst, 'admin')
            ->post("/admin/applications/{$application->public_id}/approve")
            ->assertForbidden();

        $this->actingAs($analyst, 'admin')
            ->post("/admin/applications/{$application->public_id}/reject", ['reason' => 'A written reason.'])
            ->assertForbidden();

        $this->assertDatabaseMissing('seller_accounts', ['application_id' => $application->id]);
    }

    #[Test]
    public function a_suspended_seller_keeps_reads_and_loses_every_write(): void
    {
        ['user' => $owner, 'store' => $store] = $this->makeSeller(sellerAttributes: [
            'status' => SellerStatus::Suspended->value,
            'suspended_at' => now(),
            'suspension_reason' => 'Under investigation',
        ]);

        // They still owe their customers fulfilment, so the portal opens.
        $this->actingAs($owner, 'web')->get('/seller')->assertOk();

        // But nothing can be changed, whichever route is called directly.
        $this->actingAs($owner, 'web')->get('/seller/store')->assertForbidden();
        $this->actingAs($owner, 'web')->post('/seller/store', [
            'name' => 'Changed while suspended',
            'slug' => 'changed-while-suspended',
        ])->assertForbidden();

        $this->actingAs($owner, 'web')->post('/seller/team/invitations', [
            'email' => 'during@example.com',
            'role' => SellerRole::Viewer->value,
        ])->assertForbidden();

        $this->assertNotSame('Changed while suspended', $store->fresh()?->name);
        $this->assertSame(0, SellerInvitation::query()->count());
    }

    #[Test]
    public function suspension_deletes_nothing(): void
    {
        ['seller' => $seller, 'store' => $store, 'membership' => $membership] = $this->makeSeller();

        $admin = $this->makeAdmin(AdminRole::SellerOperations);

        $this->actingAs($admin, 'admin')
            ->post("/admin/sellers/{$seller->public_id}/suspend", ['reason' => 'Repeated late dispatch.'])
            ->assertRedirect();

        // The account, its store, its team and its history all survive.
        $this->assertDatabaseHas('seller_accounts', [
            'id' => $seller->id,
            'status' => SellerStatus::Suspended->value,
        ]);
        $this->assertDatabaseHas('stores', ['id' => $store->id]);
        $this->assertDatabaseHas('seller_memberships', ['id' => $membership->id]);
        $this->assertDatabaseHas('seller_account_events', [
            'seller_account_id' => $seller->id,
            'event' => 'suspended',
        ]);
    }

    #[Test]
    public function a_seller_cannot_accept_an_invitation_addressed_to_another_seller_account(): void
    {
        ['user' => $a] = $this->makeSeller();
        ['user' => $b] = $this->makeSeller();

        $this->actingAs($b, 'web')->post('/seller/team/invitations', [
            'email' => 'someone-else@example.com',
            'role' => SellerRole::Owner->value,
        ]);

        $invitation = SellerInvitation::query()->firstOrFail();

        $this->actingAs($a, 'web')
            ->post("/seller/invitations/{$invitation->public_id}", ['token' => 'anything'])
            ->assertSessionHasErrors('token');

        $this->assertDatabaseMissing('seller_memberships', [
            'seller_account_id' => $invitation->seller_account_id,
            'user_id' => $a->id,
        ]);
    }

    #[Test]
    public function a_manipulated_seller_id_does_not_switch_tenants(): void
    {
        ['user' => $a, 'seller' => $sellerA, 'store' => $storeA] = $this->makeSeller();
        ['seller' => $sellerB, 'store' => $storeB] = $this->makeSeller();

        // Every shape a request could use to nominate a different tenant.
        foreach ([
            "/seller?seller={$sellerB->public_id}",
            "/seller?seller_account_id={$sellerB->id}",
            "/seller?seller_id={$sellerB->id}",
            "/seller/store?store={$storeB->id}",
            "/seller/store?seller_account_id={$sellerB->id}",
        ] as $url) {
            $response = $this->actingAs($a, 'web')->get($url);
            $response->assertOk();

            $response->assertInertia(fn ($page) => $page
                ->where('auth.seller.publicId', $sellerA->public_id));
        }

        $this->assertNotSame($sellerA->public_id, $sellerB->public_id);
        $this->assertNotSame($storeA->slug, $storeB->slug);
    }

    #[Test]
    public function a_viewer_is_refused_every_write_route_it_is_shown_or_not(): void
    {
        ['user' => $viewer, 'store' => $store] = $this->makeSeller(SellerRole::Viewer);

        $this->actingAs($viewer, 'web')->get('/seller/store')->assertForbidden();
        $this->actingAs($viewer, 'web')->post('/seller/store', [
            'name' => 'Renamed by a viewer',
            'slug' => 'renamed-by-a-viewer',
        ])->assertForbidden();
        $this->actingAs($viewer, 'web')->post('/seller/team/invitations', [
            'email' => 'nope@example.com',
            'role' => SellerRole::Viewer->value,
        ])->assertForbidden();

        $this->assertNotSame('Renamed by a viewer', $store->fresh()?->name);
    }

    #[Test]
    public function an_applicant_awaiting_approval_cannot_configure_a_store(): void
    {
        // A pending seller account exists, but nobody has been approved
        // into it, so there is no membership and no portal.
        $applicant = User::factory()->create();
        SellerApplication::factory()->create(['user_id' => $applicant->id]);

        $this->actingAs($applicant, 'web')->get('/seller/store')->assertNotFound();
        $this->actingAs($applicant, 'web')->post('/seller/store', [
            'name' => 'Jumping the queue',
            'slug' => 'jumping-the-queue',
        ])->assertNotFound();

        $this->assertDatabaseMissing('stores', ['slug' => 'jumping-the-queue']);
    }

    #[Test]
    public function reactivation_restores_exactly_the_access_suspension_removed(): void
    {
        ['seller' => $seller, 'user' => $owner, 'store' => $store] = $this->makeSeller();
        $admin = $this->makeAdmin(AdminRole::SellerOperations);

        $this->actingAs($admin, 'admin')
            ->post("/admin/sellers/{$seller->public_id}/suspend", ['reason' => 'Repeated late dispatch.']);

        $this->actingAs($owner, 'web')->post('/seller/store', [
            'name' => 'Blocked while suspended',
            'slug' => 'blocked-while-suspended',
        ])->assertForbidden();
        $this->get('/stores/'.$store->slug)->assertNotFound();

        $this->actingAs($admin, 'admin')
            ->post("/admin/sellers/{$seller->public_id}/reactivate")
            ->assertRedirect();

        $this->actingAs($owner, 'web')->get('/seller/store')->assertOk();
        $this->actingAs($owner, 'web')->post('/seller/store', [
            'name' => 'Allowed again',
            'slug' => 'allowed-again',
        ])->assertRedirect();

        $this->assertSame('Allowed again', $store->fresh()?->name);
        $this->get('/stores/allowed-again')->assertOk();
    }

    #[Test]
    public function an_applicant_only_ever_sees_their_own_application(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        SellerApplication::factory()->create(['user_id' => $theirs->id, 'trading_name' => 'Not Mine']);

        // There is no id in the route at all: the application is resolved
        // from the session, so there is nothing to substitute.
        $this->actingAs($mine, 'web')
            ->get('/seller/apply')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Apply')->where('application', null));
    }
}

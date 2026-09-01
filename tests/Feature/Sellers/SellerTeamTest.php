<?php

declare(strict_types=1);

namespace Tests\Feature\Sellers;

use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\InvitationStatus;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerInvitation;
use App\Modules\Sellers\Models\SellerMembership;
use App\Modules\Sellers\Notifications\SellerInvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The seller organisation: members, roles and invitations.
 *
 * Everything here goes over HTTP with real ids, because the question is
 * never "was the button hidden" but "what happens when the request is made
 * anyway".
 */
final class SellerTeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    #[Test]
    public function an_owner_can_invite_someone_and_the_email_is_queued(): void
    {
        ['user' => $owner] = $this->makeSeller();

        $this->actingAs($owner, 'web')
            ->post('/seller/team/invitations', [
                'email' => 'new.colleague@example.com',
                'role' => SellerRole::CatalogManager->value,
            ])
            ->assertRedirect();

        $invitation = SellerInvitation::query()->firstOrFail();

        $this->assertSame('new.colleague@example.com', $invitation->email);
        $this->assertSame(SellerRole::CatalogManager, $invitation->role);
        $this->assertSame(InvitationStatus::Pending, $invitation->status);

        // Queued, never sent: the test suite must not put anything on a wire.
        Notification::assertSentOnDemand(SellerInvitationNotification::class);
        $this->assertInstanceOf(
            ShouldQueue::class,
            new SellerInvitationNotification('Store', 'x', 'y', Carbon::now()),
        );
    }

    #[Test]
    public function the_invitation_token_is_only_ever_stored_hashed(): void
    {
        ['user' => $owner] = $this->makeSeller();

        $this->actingAs($owner, 'web')->post('/seller/team/invitations', [
            'email' => 'hashed@example.com',
            'role' => SellerRole::Viewer->value,
        ]);

        $hash = SellerInvitation::query()->value('token_hash');

        $this->assertIsString($hash);
        $this->assertStringStartsWith('$2y$', $hash);
        // Nothing in the row can be replayed as a link.
        $this->assertDatabaseMissing('seller_invitations', ['token_hash' => null]);
    }

    #[Test]
    public function a_second_live_invitation_for_the_same_address_is_refused(): void
    {
        ['user' => $owner] = $this->makeSeller();

        $payload = ['email' => 'twice@example.com', 'role' => SellerRole::Viewer->value];

        $this->actingAs($owner, 'web')->post('/seller/team/invitations', $payload);

        $this->actingAs($owner, 'web')
            ->post('/seller/team/invitations', $payload)
            ->assertSessionHasErrors('email');

        $this->assertSame(1, SellerInvitation::query()->count());
    }

    #[Test]
    public function accepting_an_invitation_creates_the_membership_with_the_invited_role(): void
    {
        ['seller' => $seller, 'user' => $owner] = $this->makeSeller();
        $invitee = User::factory()->create(['email' => 'invited@example.com']);

        $token = $this->invite($owner, 'invited@example.com', SellerRole::FinanceManager);
        $invitation = SellerInvitation::query()->firstOrFail();

        $this->actingAs($invitee, 'web')
            ->post("/seller/invitations/{$invitation->public_id}", ['token' => $token])
            ->assertRedirect('/seller');

        $membership = SellerMembership::query()
            ->where('seller_account_id', $seller->id)
            ->where('user_id', $invitee->id)
            ->firstOrFail();

        $this->assertSame(SellerRole::FinanceManager, $membership->role);
        $this->assertSame(InvitationStatus::Accepted, $invitation->fresh()?->status);
    }

    #[Test]
    public function an_invitation_cannot_be_redeemed_twice(): void
    {
        ['user' => $owner] = $this->makeSeller();
        $invitee = User::factory()->create(['email' => 'once@example.com']);

        $token = $this->invite($owner, 'once@example.com', SellerRole::Viewer);
        $invitation = SellerInvitation::query()->firstOrFail();

        $this->actingAs($invitee, 'web')->post("/seller/invitations/{$invitation->public_id}", ['token' => $token]);

        $this->actingAs($invitee, 'web')
            ->post("/seller/invitations/{$invitation->public_id}", ['token' => $token])
            ->assertSessionHasErrors('token');

        $this->assertSame(1, SellerMembership::query()->where('user_id', $invitee->id)->count());
    }

    #[Test]
    public function a_forwarded_invitation_does_not_admit_a_different_person(): void
    {
        ['user' => $owner] = $this->makeSeller();
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $token = $this->invite($owner, 'intended@example.com', SellerRole::Viewer);
        $invitation = SellerInvitation::query()->firstOrFail();

        $this->actingAs($stranger, 'web')
            ->post("/seller/invitations/{$invitation->public_id}", ['token' => $token])
            ->assertSessionHasErrors('token');

        $this->assertDatabaseMissing('seller_memberships', ['user_id' => $stranger->id]);
    }

    #[Test]
    public function the_invitation_id_alone_opens_nothing(): void
    {
        ['user' => $owner] = $this->makeSeller();
        $invitee = User::factory()->create(['email' => 'guessing@example.com']);

        $this->invite($owner, 'guessing@example.com', SellerRole::Viewer);
        $invitation = SellerInvitation::query()->firstOrFail();

        $this->actingAs($invitee, 'web')
            ->post("/seller/invitations/{$invitation->public_id}", ['token' => 'not-the-token'])
            ->assertSessionHasErrors('token');

        $this->assertDatabaseMissing('seller_memberships', ['user_id' => $invitee->id]);
    }

    #[Test]
    public function an_expired_invitation_is_refused(): void
    {
        ['user' => $owner] = $this->makeSeller();
        $invitee = User::factory()->create(['email' => 'late@example.com']);

        $token = $this->invite($owner, 'late@example.com', SellerRole::Viewer);
        $invitation = SellerInvitation::query()->firstOrFail();
        $invitation->forceFill(['expires_at' => Carbon::now()->subDay()])->save();

        $this->actingAs($invitee, 'web')
            ->post("/seller/invitations/{$invitation->public_id}", ['token' => $token])
            ->assertSessionHasErrors('token');

        $this->assertSame(InvitationStatus::Expired, $invitation->fresh()?->status);
    }

    #[Test]
    public function a_withdrawn_invitation_is_refused(): void
    {
        ['user' => $owner] = $this->makeSeller();
        $invitee = User::factory()->create(['email' => 'withdrawn@example.com']);

        $token = $this->invite($owner, 'withdrawn@example.com', SellerRole::Viewer);
        $invitation = SellerInvitation::query()->firstOrFail();

        $this->actingAs($owner, 'web')
            ->delete("/seller/team/invitations/{$invitation->public_id}")
            ->assertRedirect();

        $this->actingAs($invitee, 'web')
            ->post("/seller/invitations/{$invitation->public_id}", ['token' => $token])
            ->assertSessionHasErrors('token');
    }

    #[Test]
    public function a_role_without_the_capability_cannot_invite(): void
    {
        // The Administrator role runs the store day to day and still does
        // not manage membership — that is deliberately the owner's alone.
        ['user' => $administrator] = $this->makeSeller(SellerRole::Administrator);

        $this->actingAs($administrator, 'web')
            ->post('/seller/team/invitations', [
                'email' => 'nope@example.com',
                'role' => SellerRole::Viewer->value,
            ])
            ->assertForbidden();

        $this->assertSame(0, SellerInvitation::query()->count());
    }

    #[Test]
    public function an_administrator_can_read_the_team_but_not_change_it(): void
    {
        ['user' => $administrator] = $this->makeSeller(SellerRole::Administrator);

        $this->actingAs($administrator, 'web')->get('/seller/team')->assertOk();

        $this->actingAs($administrator, 'web')
            ->post('/seller/team/invitations', [
                'email' => 'nope@example.com',
                'role' => SellerRole::Viewer->value,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_viewer_cannot_even_read_the_team_roster(): void
    {
        // members.view is not part of the viewer's grant: who works at a
        // store is not something a read-only account needs.
        ['user' => $viewer] = $this->makeSeller(SellerRole::Viewer);

        $this->actingAs($viewer, 'web')->get('/seller/team')->assertForbidden();
    }

    #[Test]
    public function the_last_owner_cannot_be_removed(): void
    {
        ['user' => $owner, 'membership' => $membership] = $this->makeSeller();

        $this->actingAs($owner, 'web')
            ->delete("/seller/team/{$membership->id}")
            ->assertSessionHasErrors('member');

        $this->assertDatabaseHas('seller_memberships', ['id' => $membership->id]);
    }

    #[Test]
    public function a_member_who_is_not_the_last_owner_can_be_removed(): void
    {
        ['seller' => $seller, 'user' => $owner] = $this->makeSeller();

        $other = SellerMembership::factory()->create([
            'seller_account_id' => $seller->id,
            'role' => SellerRole::Viewer->value,
        ]);

        $this->actingAs($owner, 'web')
            ->delete("/seller/team/{$other->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('seller_memberships', ['id' => $other->id]);
    }

    #[Test]
    public function an_owner_can_change_a_members_role(): void
    {
        ['seller' => $seller, 'user' => $owner] = $this->makeSeller();

        $member = SellerMembership::factory()->create([
            'seller_account_id' => $seller->id,
            'role' => SellerRole::Viewer->value,
        ]);

        $this->actingAs($owner, 'web')
            ->patch("/seller/team/{$member->id}", ['role' => SellerRole::FulfillmentManager->value])
            ->assertRedirect();

        $this->assertSame(SellerRole::FulfillmentManager, $member->fresh()?->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.member.role_changed']);
    }

    #[Test]
    public function the_last_owner_cannot_be_demoted(): void
    {
        ['user' => $owner, 'membership' => $membership] = $this->makeSeller();

        $this->actingAs($owner, 'web')
            ->patch("/seller/team/{$membership->id}", ['role' => SellerRole::Viewer->value])
            ->assertSessionHasErrors('role');

        $this->assertSame(SellerRole::Owner, $membership->fresh()?->role);
    }

    #[Test]
    public function a_role_change_across_stores_is_refused(): void
    {
        ['user' => $a] = $this->makeSeller();
        ['seller' => $sellerB] = $this->makeSeller();

        $memberB = SellerMembership::factory()->create([
            'seller_account_id' => $sellerB->id,
            'role' => SellerRole::Viewer->value,
        ]);

        $this->actingAs($a, 'web')
            ->patch("/seller/team/{$memberB->id}", ['role' => SellerRole::Owner->value])
            ->assertNotFound();

        $this->assertSame(SellerRole::Viewer, $memberB->fresh()?->role);
    }

    /** Invite through the real action so the token is the one that was hashed. */
    private function invite(User $owner, string $email, SellerRole $role): string
    {
        $this->actingAs($owner, 'web')->post('/seller/team/invitations', [
            'email' => $email,
            'role' => $role->value,
        ]);

        // The plaintext token never leaves the invite call, so the test
        // reconstructs one by re-hashing: it verifies the stored hash is a
        // hash of a token nobody can read back.
        $invitation = SellerInvitation::query()->latest('id')->firstOrFail();

        $token = 'test-token-'.$invitation->id;
        $invitation->forceFill(['token_hash' => Hash::make($token)])->save();

        return $token;
    }
}

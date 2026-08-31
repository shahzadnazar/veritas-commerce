<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Notifications\SellerApplicationDecided;
use App\Modules\Sellers\Notifications\SellerInvitationNotification;
use App\Modules\Sellers\Notifications\SellerStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Who gets told what, and that nothing leaves the machine to say it.
 *
 * Notification::fake() intercepts every send, so this suite proves the
 * wiring without a mail server ever being contacted.
 */
final class SellerNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    #[Test]
    public function every_seller_notification_is_queued_rather_than_sent_inline(): void
    {
        foreach ([
            SellerApplicationDecided::class,
            SellerInvitationNotification::class,
            SellerStatusChanged::class,
        ] as $notification) {
            $this->assertTrue(
                (new ReflectionClass($notification))->implementsInterface(ShouldQueue::class),
                "{$notification} must be queued: a request must not wait on a mail server.",
            );
        }
    }

    #[Test]
    public function submitting_an_application_confirms_it_to_the_applicant(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/seller/apply', $this->applicationPayload());

        Notification::assertSentTo($user, SellerApplicationDecided::class,
            fn (SellerApplicationDecided $notification): bool => $notification->status === SellerApplicationStatus::Submitted);
    }

    #[Test]
    public function approval_tells_the_applicant_and_only_the_applicant(): void
    {
        $applicant = User::factory()->create();
        $bystander = User::factory()->create();
        $application = SellerApplication::factory()->create(['user_id' => $applicant->id]);

        $this->actingAs($this->makeAdmin(AdminRole::SellerOperations), 'admin')
            ->post("/admin/applications/{$application->public_id}/approve")
            ->assertRedirect();

        Notification::assertSentTo($applicant, SellerApplicationDecided::class,
            fn (SellerApplicationDecided $notification): bool => $notification->status === SellerApplicationStatus::Approved);

        Notification::assertNotSentTo($bystander, SellerApplicationDecided::class);
    }

    #[Test]
    public function a_rejection_carries_the_reason_to_the_applicant(): void
    {
        $applicant = User::factory()->create();
        $application = SellerApplication::factory()->create(['user_id' => $applicant->id]);
        $reason = 'The registration number does not match the trading name.';

        $this->actingAs($this->makeAdmin(AdminRole::SellerOperations), 'admin')
            ->post("/admin/applications/{$application->public_id}/reject", ['reason' => $reason]);

        Notification::assertSentTo($applicant, SellerApplicationDecided::class,
            fn (SellerApplicationDecided $notification): bool => $notification->status === SellerApplicationStatus::Rejected
                && $notification->reason === $reason);
    }

    #[Test]
    public function a_request_for_changes_says_what_to_change(): void
    {
        $applicant = User::factory()->create();
        $application = SellerApplication::factory()->create(['user_id' => $applicant->id]);
        $reason = 'Please upload a legible registration document.';

        $this->actingAs($this->makeAdmin(AdminRole::SellerOperations), 'admin')
            ->post("/admin/applications/{$application->public_id}/request-changes", ['reason' => $reason]);

        Notification::assertSentTo($applicant, SellerApplicationDecided::class,
            fn (SellerApplicationDecided $notification): bool => $notification->status === SellerApplicationStatus::ChangesRequested
                && $notification->reason === $reason);
    }

    #[Test]
    public function a_refused_decision_sends_nothing(): void
    {
        $applicant = User::factory()->create();
        $application = SellerApplication::factory()->create(['user_id' => $applicant->id]);

        // No reason: the server refuses, and nothing is emailed about a
        // decision that did not happen.
        $this->actingAs($this->makeAdmin(AdminRole::SellerOperations), 'admin')
            ->post("/admin/applications/{$application->public_id}/reject", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        Notification::assertNothingSentTo($applicant);
    }

    #[Test]
    public function suspension_and_reactivation_both_reach_the_owner(): void
    {
        ['seller' => $seller, 'user' => $owner] = $this->makeSeller();
        $admin = $this->makeAdmin(AdminRole::SellerOperations);

        $this->actingAs($admin, 'admin')
            ->post("/admin/sellers/{$seller->public_id}/suspend", ['reason' => 'Repeated late dispatch.']);

        Notification::assertSentTo($owner, SellerStatusChanged::class,
            fn (SellerStatusChanged $notification): bool => $notification->status === SellerStatus::Suspended
                && $notification->reason === 'Repeated late dispatch.');

        $this->actingAs($admin, 'admin')
            ->post("/admin/sellers/{$seller->public_id}/reactivate");

        Notification::assertSentTo($owner, SellerStatusChanged::class,
            fn (SellerStatusChanged $notification): bool => $notification->status === SellerStatus::Approved);
    }

    #[Test]
    public function an_invitation_is_addressed_on_demand_because_the_invitee_may_not_have_an_account(): void
    {
        ['user' => $owner] = $this->makeSeller();

        $this->actingAs($owner)->post('/seller/team/invitations', [
            'email' => 'not-yet-a-user@example.com',
            'role' => SellerRole::Viewer->value,
        ]);

        Notification::assertSentOnDemand(
            SellerInvitationNotification::class,
            fn (SellerInvitationNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'not-yet-a-user@example.com',
        );
    }

    /** @return array<string, mixed> */
    private function applicationPayload(): array
    {
        return [
            'legal_name' => 'Aeris Kitchen Company LLC',
            'trading_name' => 'Aeris Kitchen Co.',
            'business_type' => 'LLC',
            'tax_id' => '82-1234567',
            'address_line1' => '114 SE Ash St',
            'address_city' => 'Portland',
            'address_state' => 'OR',
            'address_postcode' => '97214',
            'contact_name' => 'Dana Reyes',
            'contact_email' => 'dana@aeris.example',
            'blurb' => 'We make cast iron and carbon steel cookware intended to be handed down.',
            'terms_accepted' => true,
        ];
    }
}

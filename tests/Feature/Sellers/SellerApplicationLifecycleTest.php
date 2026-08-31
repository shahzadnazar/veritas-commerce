<?php

declare(strict_types=1);

namespace Tests\Feature\Sellers;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Actions\ApproveSellerApplication;
use App\Modules\Sellers\Actions\ChangeSellerStatus;
use App\Modules\Sellers\Actions\RejectSellerApplication;
use App\Modules\Sellers\Actions\RequestApplicationChanges;
use App\Modules\Sellers\Actions\SubmitSellerApplication;
use App\Modules\Sellers\Actions\TransitionSellerApplication;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Events\SellerApproved;
use App\Modules\Sellers\Exceptions\InvalidApplicationTransition;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationEvent;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The seller application, from submission to a working seller account.
 */
final class SellerApplicationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /** @return array<string, mixed> */
    private function validApplication(): array
    {
        return [
            'legal_name' => 'Aeris Kitchen Company LLC',
            'trading_name' => 'Aeris Kitchen Co.',
            'business_type' => 'LLC',
            'tax_id' => '87-2214905',
            'address_line1' => '1841 NE Wasco St',
            'address_city' => 'Portland',
            'address_state' => 'OR',
            'address_postcode' => '97232',
            'contact_name' => 'Nadia Fischer',
            'contact_email' => 'nadia@aeriskitchen.test',
            'intended_categories' => ['home-kitchen'],
            'expected_catalogue_type' => 'own_brand',
            'blurb' => 'Small-batch kitchen tools.',
            'terms_accepted_at' => now(),
        ];
    }

    #[Test]
    public function an_authenticated_user_can_submit_an_application(): void
    {
        $user = User::factory()->create();

        $application = app(SubmitSellerApplication::class)($user, $this->validApplication());

        $this->assertSame(SellerApplicationStatus::Submitted, $application->status);
        $this->assertStringStartsWith('APP-', $application->reference);
        $this->assertNotNull($application->submitted_at);

        $this->assertDatabaseHas('seller_application_events', [
            'seller_application_id' => $application->id,
            'to_status' => 'submitted',
            'actor_type' => 'customer',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.application.submitted']);
    }

    #[Test]
    public function resubmitting_after_changes_edits_the_same_record(): void
    {
        $user = User::factory()->create();
        $admin = AdminUser::factory()->create();

        $first = app(SubmitSellerApplication::class)($user, $this->validApplication());
        app(RequestApplicationChanges::class)($first, $admin->id, 'The tax ID does not match the legal name.');

        $second = app(SubmitSellerApplication::class)($user->fresh(), [
            ...$this->validApplication(),
            'legal_name' => 'Aeris Kitchen Company, LLC',
        ]);

        $this->assertSame($first->id, $second->id, 'A re-application edits the record rather than duplicating it.');
        $this->assertSame($first->reference, $second->reference, 'The reference is stable across attempts.');
        $this->assertSame(1, SellerApplication::query()->count());
        $this->assertSame(SellerApplicationStatus::Submitted, $second->status);
    }

    #[Test]
    public function changes_requested_is_not_the_same_outcome_as_rejection(): void
    {
        $admin = AdminUser::factory()->create();
        $application = SellerApplication::factory()->create();

        app(RequestApplicationChanges::class)($application, $admin->id, 'Upload a clearer registration document.');

        $this->assertSame(SellerApplicationStatus::ChangesRequested, $application->fresh()?->status);
        $this->assertTrue($application->fresh()?->status->isOpen(), 'The application is still live.');
        $this->assertTrue($application->fresh()?->status->isEditableByApplicant());
    }

    #[Test]
    public function an_invalid_transition_is_refused(): void
    {
        $application = SellerApplication::factory()
            ->status(SellerApplicationStatus::Approved)
            ->create();

        $this->expectException(InvalidApplicationTransition::class);
        $this->expectExceptionMessage('cannot move from approved to submitted');

        app(TransitionSellerApplication::class)(
            $application,
            SellerApplicationStatus::Submitted,
            'customer',
        );
    }

    #[Test]
    public function rejection_requires_a_reason(): void
    {
        $admin = AdminUser::factory()->create();
        $application = SellerApplication::factory()->create();

        $this->expectException(InvalidApplicationTransition::class);
        $this->expectExceptionMessage('requires a written reason');

        app(TransitionSellerApplication::class)(
            $application,
            SellerApplicationStatus::Rejected,
            'admin',
            $admin->id,
            '   ',
        );
    }

    #[Test]
    public function rejection_records_the_reason_and_an_audit_event(): void
    {
        $admin = AdminUser::factory()->create();
        $application = SellerApplication::factory()->create();

        app(RejectSellerApplication::class)($application, $admin->id, 'Tax ID does not match the registered name.');

        $fresh = $application->fresh();
        $this->assertSame(SellerApplicationStatus::Rejected, $fresh?->status);
        $this->assertSame('Tax ID does not match the registered name.', $fresh?->decision_reason);
        $this->assertNotNull($fresh?->decided_at);

        $event = AuditLog::query()->where('action', 'seller.rejected')->firstOrFail();
        $this->assertSame('Tax ID does not match the registered name.', $event->reason);
        $this->assertNull(SellerAccount::query()->first(), 'A rejection creates no seller account.');
    }

    #[Test]
    public function approval_creates_exactly_one_account_and_one_owner(): void
    {
        Event::fake([SellerApproved::class]);

        $user = User::factory()->create();
        $admin = AdminUser::factory()->role(AdminRole::SellerOperations)->create();
        $application = SellerApplication::factory()->create(['user_id' => $user->id]);

        $seller = app(ApproveSellerApplication::class)($application, $admin->id);

        $this->assertSame(1, SellerAccount::query()->count());
        $this->assertSame(SellerStatus::Approved, $seller->status);
        $this->assertNotNull($seller->approved_at);

        $memberships = SellerMembership::query()->where('seller_account_id', $seller->id)->get();
        $this->assertCount(1, $memberships);
        $this->assertSame(SellerRole::Owner, $memberships->first()?->role);
        $this->assertSame($user->id, $memberships->first()?->user_id);

        $this->assertSame(SellerApplicationStatus::Approved, $application->fresh()?->status);
        $this->assertSame($seller->id, $application->fresh()?->seller_account_id);

        Event::assertDispatched(SellerApproved::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.approved']);
    }

    #[Test]
    public function approving_twice_does_not_duplicate_the_account_or_the_membership(): void
    {
        $user = User::factory()->create();
        $admin = AdminUser::factory()->create();
        $application = SellerApplication::factory()->create(['user_id' => $user->id]);

        // The double-click. Both calls must land on the same seller.
        $first = app(ApproveSellerApplication::class)($application, $admin->id);
        $second = app(ApproveSellerApplication::class)($application->fresh(), $admin->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SellerAccount::query()->count(), 'One account, however many times Approve is pressed.');
        $this->assertSame(1, SellerMembership::query()->count(), 'And one owner.');
    }

    #[Test]
    public function approving_three_times_is_still_one_account(): void
    {
        $user = User::factory()->create();
        $admin = AdminUser::factory()->create();
        $application = SellerApplication::factory()->create(['user_id' => $user->id]);

        foreach (range(1, 3) as $ignored) {
            app(ApproveSellerApplication::class)($application->fresh(), $admin->id);
        }

        $this->assertSame(1, SellerAccount::query()->count());
        $this->assertSame(1, SellerMembership::query()->count());
        $this->assertSame(
            1,
            SellerApplicationEvent::query()->where('to_status', 'approved')->count(),
            'And exactly one approval in the history.',
        );
    }

    #[Test]
    public function the_application_history_records_every_step(): void
    {
        $user = User::factory()->create();
        $admin = AdminUser::factory()->create();

        $application = app(SubmitSellerApplication::class)($user, $this->validApplication());
        app(TransitionSellerApplication::class)($application, SellerApplicationStatus::UnderReview, 'admin', $admin->id);
        app(ApproveSellerApplication::class)($application->fresh(), $admin->id);

        $history = SellerApplicationEvent::query()
            ->where('seller_application_id', $application->id)
            ->orderBy('id')
            ->pluck('to_status')
            ->all();

        $this->assertSame(['submitted', 'under_review', 'approved'], $history);
    }

    #[Test]
    public function application_history_cannot_be_rewritten(): void
    {
        $event = SellerApplicationEvent::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $event->update(['to_status' => 'approved']);
    }

    #[Test]
    public function suspension_requires_a_reason_and_keeps_the_record(): void
    {
        $admin = AdminUser::factory()->create();
        $seller = SellerAccount::factory()->create();

        try {
            app(ChangeSellerStatus::class)($seller, SellerStatus::Suspended, $admin->id, '');
            $this->fail('Suspending without a reason must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires a written reason', $exception->getMessage());
        }

        app(ChangeSellerStatus::class)($seller, SellerStatus::Suspended, $admin->id, 'Chargeback rate above threshold.');

        $fresh = $seller->fresh();
        $this->assertNotNull($fresh, 'Suspension never deletes the seller.');
        $this->assertSame(SellerStatus::Suspended, $fresh->status);
        $this->assertSame('Chargeback rate above threshold.', $fresh->suspension_reason);

        $event = AuditLog::query()->where('action', 'seller.suspended')->firstOrFail();
        $this->assertSame('Chargeback rate above threshold.', $event->reason);
    }

    #[Test]
    public function reactivation_clears_the_suspension(): void
    {
        $admin = AdminUser::factory()->create();
        $seller = SellerAccount::factory()->suspended()->create();

        app(ChangeSellerStatus::class)($seller, SellerStatus::Approved, $admin->id);

        $fresh = $seller->fresh();
        $this->assertSame(SellerStatus::Approved, $fresh?->status);
        $this->assertNull($fresh?->suspended_at);
        $this->assertNull($fresh?->suspension_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.reactivated']);
    }
}

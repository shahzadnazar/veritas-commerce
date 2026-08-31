<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The audit trail: what it records, and what it must never record.
 */
final class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    #[Test]
    public function an_approval_is_recorded_with_its_actor_and_subject(): void
    {
        $admin = $this->makeAdmin(AdminRole::SellerOperations);
        $application = SellerApplication::factory()->create();

        $this->actingAs($admin, 'admin')
            ->post("/admin/applications/{$application->public_id}/approve")
            ->assertRedirect();

        $entry = AuditLog::query()->where('action', 'seller.approved')->firstOrFail();

        $this->assertSame('admin', $entry->actor_type);
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertNotNull($entry->subject_id);
        $this->assertNotNull($entry->created_at);
    }

    #[Test]
    public function a_rejection_records_the_reason_verbatim(): void
    {
        $admin = $this->makeAdmin(AdminRole::SellerOperations);
        $application = SellerApplication::factory()->create();
        $reason = 'The registration number does not match the trading name.';

        $this->actingAs($admin, 'admin')
            ->post("/admin/applications/{$application->public_id}/reject", ['reason' => $reason]);

        $this->assertSame(
            $reason,
            AuditLog::query()->where('action', 'seller.rejected')->value('reason'),
        );
    }

    #[Test]
    public function a_request_for_changes_is_recorded_separately_from_a_rejection(): void
    {
        $admin = $this->makeAdmin(AdminRole::SellerOperations);
        $application = SellerApplication::factory()->create();

        $this->actingAs($admin, 'admin')->post(
            "/admin/applications/{$application->public_id}/request-changes",
            ['reason' => 'Please upload a legible registration document.'],
        );

        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.application.changes_requested']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'seller.rejected']);
    }

    #[Test]
    public function a_suspension_and_a_reactivation_are_both_recorded(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $admin = $this->makeAdmin(AdminRole::SellerOperations);

        $this->actingAs($admin, 'admin')
            ->post("/admin/sellers/{$seller->public_id}/suspend", ['reason' => 'Repeated late dispatch.']);
        $this->actingAs($admin, 'admin')
            ->post("/admin/sellers/{$seller->public_id}/reactivate");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'seller.suspended',
            'actor_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'seller.reactivated',
            'actor_id' => $admin->id,
        ]);
    }

    #[Test]
    public function seller_side_changes_are_attributed_to_the_seller(): void
    {
        ['seller' => $seller, 'user' => $owner] = $this->makeSeller();

        $this->actingAs($owner)->post('/seller/store', [
            'name' => 'Aeris Kitchen Co.',
            'slug' => 'aeris-kitchen-audit',
        ]);

        $this->actingAs($owner)->post('/seller/team/invitations', [
            'email' => 'colleague@example.com',
            'role' => SellerRole::Viewer->value,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'seller.store.updated',
            'actor_type' => 'seller',
            'actor_id' => $seller->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'seller.member.invited',
            'actor_type' => 'seller',
            'actor_id' => $owner->id,
        ]);
    }

    #[Test]
    public function an_invitation_record_holds_the_address_but_never_the_token(): void
    {
        ['user' => $owner] = $this->makeSeller();

        $this->actingAs($owner)->post('/seller/team/invitations', [
            'email' => 'colleague@example.com',
            'role' => SellerRole::Viewer->value,
        ]);

        $changes = AuditLog::query()->where('action', 'seller.member.invited')->value('changes');

        $this->assertIsArray($changes);
        $this->assertSame('colleague@example.com', $changes['email']);
        $this->assertArrayNotHasKey('token', $changes);
    }

    #[Test]
    public function secrets_are_scrubbed_however_they_are_passed(): void
    {
        $recorded = app(RecordAuditEvent::class)(
            action: 'test.everything',
            actorType: 'system',
            changes: [
                'password' => 'hunter2',
                'password_confirmation' => 'hunter2',
                'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => ['aaaa-bbbb'],
                'api_key' => 'sk_live_1234',
                'Authorization' => 'Bearer abc',
                'remember_token' => 'zzz',
                'nested' => ['token_hash' => '$2y$abc', 'email' => 'kept@example.com'],
                'email' => 'kept@example.com',
            ],
        );

        $changes = $recorded->changes;
        $this->assertIsArray($changes);

        foreach ([
            'password', 'password_confirmation', 'two_factor_secret',
            'recovery_codes', 'api_key', 'Authorization', 'remember_token',
        ] as $key) {
            $this->assertSame('[redacted]', $changes[$key], "{$key} was not redacted");
        }

        $this->assertSame('[redacted]', $changes['nested']['token_hash']);
        // Ordinary fields survive: a log that redacts everything logs nothing.
        $this->assertSame('kept@example.com', $changes['email']);
        $this->assertSame('kept@example.com', $changes['nested']['email']);
    }

    #[Test]
    public function no_audit_record_anywhere_contains_a_credential(): void
    {
        $admin = $this->makeAdmin(AdminRole::SellerOperations);
        $application = SellerApplication::factory()->create();
        ['user' => $owner] = $this->makeSeller();

        $this->actingAs($admin, 'admin')->post("/admin/applications/{$application->public_id}/approve");
        $this->actingAs($owner)->post('/seller/team/invitations', [
            'email' => 'sweep@example.com',
            'role' => SellerRole::Viewer->value,
        ]);

        $serialised = AuditLog::query()->get()
            ->map(fn (AuditLog $log): string => json_encode($log->changes).' '.(string) $log->reason)
            ->implode(' ');

        foreach (['$2y$', 'password', 'secret', 'Bearer '] as $needle) {
            $this->assertStringNotContainsString($needle, $serialised);
        }
    }

    #[Test]
    public function the_audit_trail_cannot_be_rewritten(): void
    {
        $entry = app(RecordAuditEvent::class)(
            action: 'test.immutable',
            actorType: 'system',
        );

        $this->expectException(RuntimeException::class);

        $entry->update(['action' => 'test.rewritten']);
    }

    #[Test]
    public function the_audit_trail_cannot_be_deleted(): void
    {
        $entry = app(RecordAuditEvent::class)(
            action: 'test.undeletable',
            actorType: 'system',
        );

        // Refused at the model, and the row is still there afterwards.
        $this->assertThrows(fn () => $entry->delete(), RuntimeException::class);

        $this->assertSame(1, DB::table('audit_logs')->where('id', $entry->id)->count());
    }
}

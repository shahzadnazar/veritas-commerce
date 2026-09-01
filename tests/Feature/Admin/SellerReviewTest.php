<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin seller review, over HTTP.
 *
 * Every negative case here uses a direct route with a real id — the point
 * is that hiding a button proves nothing.
 */
final class SellerReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function admin(AdminRole $role = AdminRole::SellerOperations): AdminUser
    {
        return AdminUser::factory()->role($role)->withTwoFactor()->create();
    }

    #[Test]
    public function the_queue_lists_applications_waiting_on_the_team(): void
    {
        SellerApplication::factory()->count(3)->create();
        SellerApplication::factory()->status(SellerApplicationStatus::Approved)->create();

        $this->asAdmin($this->admin())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sellers/Applications')
                ->has('applications.data', 3, fn ($row) => $row
                    ->has('reference')
                    ->has('status')
                    ->etc()));
    }

    #[Test]
    public function the_queue_can_be_filtered_and_searched(): void
    {
        SellerApplication::factory()->create(['trading_name' => 'Aeris Kitchen Co.']);
        SellerApplication::factory()->create(['trading_name' => 'Northline Audio']);

        $this->asAdmin($this->admin())
            ->get('/admin/applications?search=aeris')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('applications.data', 1));

        $this->asAdmin($this->admin())
            ->get('/admin/applications?status=approved')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('applications.data', 0));
    }

    #[Test]
    public function a_reviewer_sees_the_full_record_and_its_history(): void
    {
        $application = SellerApplication::factory()->create();

        $this->asAdmin($this->admin())
            ->get("/admin/applications/{$application->public_id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sellers/ApplicationDetail')
                ->where('application.reference', $application->reference)
                ->has('history')
                ->where('can.approve', true));
    }

    #[Test]
    public function the_tax_id_is_withheld_without_the_sensitive_permission(): void
    {
        $application = SellerApplication::factory()->create();

        // Support can read an application to answer a question, but has no
        // business seeing the applicant's tax identifier.
        $this->asAdmin($this->admin(AdminRole::Support))
            ->get("/admin/applications/{$application->public_id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('application.taxId', null));

        $this->asAdmin($this->admin(AdminRole::SellerOperations))
            ->get("/admin/applications/{$application->public_id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('application.taxId', $application->tax_id));
    }

    #[Test]
    public function an_admin_with_the_permission_can_approve(): void
    {
        $application = SellerApplication::factory()->create();

        $this->asAdmin($this->admin())
            ->post("/admin/applications/{$application->public_id}/approve")
            ->assertRedirect();

        $this->assertSame(SellerApplicationStatus::Approved, $application->fresh()?->status);
        $this->assertSame(1, SellerAccount::query()->count());
    }

    #[Test]
    public function an_admin_without_the_permission_cannot_approve(): void
    {
        $application = SellerApplication::factory()->create();

        foreach ([AdminRole::FinanceAdmin, AdminRole::CatalogModerator, AdminRole::Support, AdminRole::Analyst] as $role) {
            $this->asAdmin($this->admin($role))
                ->post("/admin/applications/{$application->public_id}/approve")
                ->assertForbidden();
        }

        $this->assertSame(0, SellerAccount::query()->count(), 'No account is created by a refused request.');
        $this->assertSame(SellerApplicationStatus::Submitted, $application->fresh()?->status);
    }

    #[Test]
    public function an_admin_without_the_permission_cannot_reject(): void
    {
        $application = SellerApplication::factory()->create();

        $this->asAdmin($this->admin(AdminRole::FinanceAdmin))
            ->post("/admin/applications/{$application->public_id}/reject", ['reason' => 'Not a real reason at all'])
            ->assertForbidden();

        $this->assertSame(SellerApplicationStatus::Submitted, $application->fresh()?->status);
    }

    #[Test]
    public function rejection_without_a_reason_is_refused_by_the_server(): void
    {
        $application = SellerApplication::factory()->create();

        $this->asAdmin($this->admin())
            ->post("/admin/applications/{$application->public_id}/reject", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->asAdmin($this->admin())
            ->post("/admin/applications/{$application->public_id}/reject", ['reason' => 'too short'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(SellerApplicationStatus::Submitted, $application->fresh()?->status);
    }

    #[Test]
    public function rejection_with_a_reason_succeeds(): void
    {
        $application = SellerApplication::factory()->create();

        $this->asAdmin($this->admin())
            ->post("/admin/applications/{$application->public_id}/reject", [
                'reason' => 'The tax ID does not match the registered business name.',
            ])
            ->assertRedirect();

        $fresh = $application->fresh();
        $this->assertSame(SellerApplicationStatus::Rejected, $fresh?->status);
        $this->assertSame('The tax ID does not match the registered business name.', $fresh?->decision_reason);
    }

    #[Test]
    public function beginning_a_review_assigns_the_reviewer(): void
    {
        $admin = $this->admin();
        $application = SellerApplication::factory()->create();

        $this->asAdmin($admin)
            ->post("/admin/applications/{$application->public_id}/review")
            ->assertRedirect();

        $fresh = $application->fresh();
        $this->assertSame(SellerApplicationStatus::UnderReview, $fresh?->status);
        $this->assertSame($admin->id, $fresh?->reviewer_admin_id);
    }

    #[Test]
    public function suspension_requires_both_the_permission_and_a_reason(): void
    {
        $seller = SellerAccount::factory()->create();

        $this->asAdmin($this->admin(AdminRole::Support))
            ->post("/admin/sellers/{$seller->public_id}/suspend", ['reason' => 'A perfectly good reason here'])
            ->assertForbidden();

        $this->asAdmin($this->admin())
            ->post("/admin/sellers/{$seller->public_id}/suspend", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame('approved', $seller->fresh()?->status->value);

        $this->asAdmin($this->admin())
            ->post("/admin/sellers/{$seller->public_id}/suspend", ['reason' => 'Chargeback rate above threshold.'])
            ->assertRedirect();

        $this->assertSame('suspended', $seller->fresh()?->status->value);
    }

    #[Test]
    public function a_customer_session_cannot_reach_the_review_queue(): void
    {
        $application = SellerApplication::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/applications')->assertRedirect(route('admin.login'));
        $this->actingAs($user)
            ->post("/admin/applications/{$application->public_id}/approve")
            ->assertRedirect(route('admin.login'));

        $this->assertSame(0, SellerAccount::query()->count());
    }

    #[Test]
    public function an_admin_without_mfa_cannot_reach_review_at_all(): void
    {
        $admin = AdminUser::factory()->role(AdminRole::SellerOperations)->create();
        $application = SellerApplication::factory()->create();

        $this->actingAs($admin, 'admin')
            ->post("/admin/applications/{$application->public_id}/approve")
            ->assertRedirect(route('admin.mfa.setup'));

        $this->assertSame(0, SellerAccount::query()->count());
    }
}

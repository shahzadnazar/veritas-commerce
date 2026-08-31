<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The staff permission matrix, asserted rather than assumed.
 *
 * Hiding a nav item is a courtesy to the user; this is the control. The
 * matrix below is the one in docs/architecture/08-identity-roles-stores.md,
 * so the documentation and the code cannot drift apart silently.
 */
final class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_owner_and_finance_can_change_the_commission_rate(): void
    {
        $this->assertTrue(AdminRole::Owner->can(AdminPermission::ManageCommission));
        $this->assertTrue(AdminRole::Finance->can(AdminPermission::ManageCommission));
        $this->assertFalse(AdminRole::Operations->can(AdminPermission::ManageCommission));
        $this->assertFalse(AdminRole::Support->can(AdminPermission::ManageCommission));
    }

    #[Test]
    public function only_owner_and_finance_can_decide_payouts(): void
    {
        $this->assertTrue(AdminRole::Owner->can(AdminPermission::DecidePayouts));
        $this->assertTrue(AdminRole::Finance->can(AdminPermission::DecidePayouts));
        $this->assertFalse(AdminRole::Operations->can(AdminPermission::DecidePayouts));
        $this->assertFalse(AdminRole::Support->can(AdminPermission::DecidePayouts));
    }

    #[Test]
    public function only_operations_and_owner_can_suspend_a_seller(): void
    {
        $this->assertTrue(AdminRole::Owner->can(AdminPermission::SuspendSellers));
        $this->assertTrue(AdminRole::Operations->can(AdminPermission::SuspendSellers));
        $this->assertFalse(AdminRole::Finance->can(AdminPermission::SuspendSellers));
        $this->assertFalse(AdminRole::Support->can(AdminPermission::SuspendSellers));
    }

    #[Test]
    public function only_the_owner_manages_staff_and_company_settings(): void
    {
        foreach ([AdminPermission::ManageStaff, AdminPermission::ManageCompanySettings] as $permission) {
            $this->assertTrue(AdminRole::Owner->can($permission));

            foreach ([AdminRole::Operations, AdminRole::Finance, AdminRole::Support] as $role) {
                $this->assertFalse($role->can($permission), "{$role->value} must not hold {$permission->value}.");
            }
        }
    }

    #[Test]
    public function support_is_read_only(): void
    {
        $writePermissions = [
            AdminPermission::ReviewSellerApplications,
            AdminPermission::SuspendSellers,
            AdminPermission::ReviewOffers,
            AdminPermission::ManageTaxonomy,
            AdminPermission::IssueRefunds,
            AdminPermission::ManageCommission,
            AdminPermission::DecidePayouts,
            AdminPermission::ManageStaff,
        ];

        foreach ($writePermissions as $permission) {
            $this->assertFalse(
                AdminRole::Support->can($permission),
                "Support must not hold the write permission {$permission->value}.",
            );
        }

        $this->assertTrue(AdminRole::Support->can(AdminPermission::ViewOrders));
    }

    #[Test]
    public function the_owner_holds_every_permission(): void
    {
        foreach (AdminPermission::cases() as $permission) {
            $this->assertTrue(AdminRole::Owner->can($permission));
        }
    }

    #[Test]
    public function the_admin_model_answers_permission_checks_by_role(): void
    {
        $finance = AdminUser::factory()->role(AdminRole::Finance)->create();
        $support = AdminUser::factory()->role(AdminRole::Support)->create();

        $this->assertTrue($finance->can(AdminPermission::DecidePayouts));
        $this->assertFalse($support->can(AdminPermission::DecidePayouts));
        $this->assertTrue($finance->can('payouts.decide'), 'String permissions resolve too.');
    }

    #[Test]
    public function seller_roles_gate_money_and_store_settings(): void
    {
        $this->assertTrue(SellerRole::Owner->canRequestPayout());
        $this->assertFalse(SellerRole::Manager->canRequestPayout());
        $this->assertFalse(SellerRole::Staff->canRequestPayout());

        $this->assertTrue(SellerRole::Owner->canManageStore());
        $this->assertFalse(SellerRole::Staff->canManageStore());

        $this->assertTrue(SellerRole::Manager->canViewEarnings());
        $this->assertFalse(SellerRole::Staff->canViewEarnings());

        // Everyone attached to a store can pack and ship — that is the job.
        foreach (SellerRole::cases() as $role) {
            $this->assertTrue($role->canFulfilOrders());
        }
    }

    #[Test]
    public function customers_and_admins_use_separate_guards(): void
    {
        $this->assertSame('web', config('auth.defaults.guard'));
        $this->assertSame(
            User::class,
            config('auth.providers.users.model'),
        );
        $this->assertSame(
            AdminUser::class,
            config('auth.providers.admins.model'),
            'Staff authenticate against their own table, so a customer session can never be escalated.',
        );
    }
}

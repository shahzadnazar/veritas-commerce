<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerPermission;
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
    public function only_finance_and_the_super_admin_can_move_money(): void
    {
        foreach ([AdminPermission::ManageCommission, AdminPermission::DecidePayouts, AdminPermission::IssueRefunds] as $permission) {
            $this->assertTrue(AdminRole::SuperAdmin->can($permission));
            $this->assertTrue(AdminRole::FinanceAdmin->can($permission));

            foreach ([AdminRole::SellerOperations, AdminRole::CatalogModerator, AdminRole::Support, AdminRole::Analyst] as $role) {
                $this->assertFalse($role->can($permission), "{$role->value} must not hold {$permission->value}.");
            }
        }
    }

    #[Test]
    public function seller_governance_belongs_to_seller_operations_not_to_finance(): void
    {
        $governance = [
            AdminPermission::SellerApprove,
            AdminPermission::SellerReject,
            AdminPermission::SellerSuspend,
            AdminPermission::SellerReactivate,
        ];

        foreach ($governance as $permission) {
            $this->assertTrue(AdminRole::SellerOperations->can($permission));
            $this->assertTrue(AdminRole::MarketplaceAdmin->can($permission));

            // Approving a seller and setting the commission rate are
            // different jobs; one account holding both is how a single
            // compromise becomes a revenue incident.
            $this->assertFalse(AdminRole::FinanceAdmin->can($permission));
            $this->assertFalse(AdminRole::CatalogModerator->can($permission));
            $this->assertFalse(AdminRole::Support->can($permission));
            $this->assertFalse(AdminRole::Analyst->can($permission));
        }
    }

    #[Test]
    public function seller_operations_holds_no_catalogue_or_finance_authority(): void
    {
        foreach ([AdminPermission::ReviewOffers, AdminPermission::ManageTaxonomy, AdminPermission::ManageCommission, AdminPermission::DecidePayouts] as $permission) {
            $this->assertFalse(AdminRole::SellerOperations->can($permission));
        }
    }

    #[Test]
    public function support_and_analyst_are_read_only(): void
    {
        $writes = [
            AdminPermission::SellerApprove,
            AdminPermission::SellerReject,
            AdminPermission::SellerSuspend,
            AdminPermission::ReviewOffers,
            AdminPermission::ManageTaxonomy,
            AdminPermission::IssueRefunds,
            AdminPermission::ManageCommission,
            AdminPermission::DecidePayouts,
            AdminPermission::ManageStaff,
        ];

        foreach ([AdminRole::Support, AdminRole::Analyst] as $role) {
            foreach ($writes as $permission) {
                $this->assertFalse($role->can($permission), "{$role->value} must not hold {$permission->value}.");
            }
        }

        $this->assertTrue(AdminRole::Support->can(AdminPermission::ViewOrders));
        $this->assertTrue(AdminRole::Analyst->can(AdminPermission::ViewOrders));
    }

    #[Test]
    public function only_the_super_admin_manages_staff_and_company_settings(): void
    {
        foreach ([AdminPermission::ManageStaff, AdminPermission::ManageCompanySettings, AdminPermission::ResetAdminMfa] as $permission) {
            $this->assertTrue(AdminRole::SuperAdmin->can($permission));

            foreach (AdminRole::cases() as $role) {
                if ($role === AdminRole::SuperAdmin) {
                    continue;
                }

                $this->assertFalse($role->can($permission), "{$role->value} must not hold {$permission->value}.");
            }
        }
    }

    #[Test]
    public function the_super_admin_holds_every_permission(): void
    {
        foreach (AdminPermission::cases() as $permission) {
            $this->assertTrue(AdminRole::SuperAdmin->can($permission));
        }
    }

    #[Test]
    public function the_admin_model_answers_permission_checks_by_role(): void
    {
        $finance = AdminUser::factory()->role(AdminRole::FinanceAdmin)->create();
        $support = AdminUser::factory()->role(AdminRole::Support)->create();

        $this->assertTrue($finance->can(AdminPermission::DecidePayouts));
        $this->assertFalse($support->can(AdminPermission::DecidePayouts));
        $this->assertTrue($finance->can('payouts.decide'), 'String permissions resolve too.');
    }

    #[Test]
    public function only_the_owner_holds_the_team_and_the_money(): void
    {
        // These two capabilities are how a compromised staff account turns
        // into a stolen business, so they stay with the owner.
        foreach ([SellerPermission::MembersManage, SellerPermission::PayoutsRequest] as $permission) {
            $this->assertTrue(SellerRole::Owner->can($permission));

            foreach (SellerRole::cases() as $role) {
                if ($role === SellerRole::Owner) {
                    continue;
                }

                $this->assertFalse($role->can($permission), "{$role->value} must not hold {$permission->value}.");
            }
        }
    }

    #[Test]
    public function an_administrator_runs_the_shop_but_does_not_own_it(): void
    {
        $administrator = SellerRole::Administrator;

        $this->assertTrue($administrator->can(SellerPermission::StoreManage));
        $this->assertTrue($administrator->can(SellerPermission::CatalogManage));
        $this->assertTrue($administrator->can(SellerPermission::OrdersManage));
        $this->assertTrue($administrator->can(SellerPermission::FinanceView));

        $this->assertFalse($administrator->can(SellerPermission::MembersManage));
        $this->assertFalse($administrator->can(SellerPermission::PayoutsRequest));
    }

    #[Test]
    public function a_viewer_holds_no_write_capability(): void
    {
        $writes = [
            SellerPermission::StoreManage,
            SellerPermission::MembersManage,
            SellerPermission::CatalogManage,
            SellerPermission::InventoryManage,
            SellerPermission::OrdersManage,
            SellerPermission::PayoutsRequest,
        ];

        foreach ($writes as $permission) {
            $this->assertFalse(SellerRole::Viewer->can($permission), "A viewer must not hold {$permission->value}.");
        }

        $this->assertTrue(SellerRole::Viewer->can(SellerPermission::CatalogView));
        $this->assertTrue(SellerRole::Viewer->can(SellerPermission::OrdersView));
    }

    #[Test]
    public function each_specialist_role_is_scoped_to_its_own_area(): void
    {
        $this->assertTrue(SellerRole::CatalogManager->can(SellerPermission::CatalogManage));
        $this->assertFalse(SellerRole::CatalogManager->can(SellerPermission::OrdersManage));
        $this->assertFalse(SellerRole::CatalogManager->can(SellerPermission::FinanceView));

        $this->assertTrue(SellerRole::InventoryManager->can(SellerPermission::InventoryManage));
        $this->assertFalse(SellerRole::InventoryManager->can(SellerPermission::CatalogManage));

        $this->assertTrue(SellerRole::FulfillmentManager->can(SellerPermission::OrdersManage));
        $this->assertFalse(SellerRole::FulfillmentManager->can(SellerPermission::InventoryManage));

        $this->assertTrue(SellerRole::FinanceManager->can(SellerPermission::FinanceView));
        $this->assertFalse(SellerRole::FinanceManager->can(SellerPermission::CatalogManage));
        $this->assertFalse(SellerRole::FinanceManager->can(SellerPermission::PayoutsRequest));
    }

    #[Test]
    public function the_owner_holds_every_seller_capability(): void
    {
        foreach (SellerPermission::cases() as $permission) {
            $this->assertTrue(SellerRole::Owner->can($permission));
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

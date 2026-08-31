<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * Platform staff roles.
 *
 * Permissions are enforced server-side on every request. Hiding a sidebar
 * item is a courtesy to the user, never the control.
 */
enum AdminRole: string
{
    case Owner = 'owner';
    case Operations = 'operations';
    case Finance = 'finance';
    case Support = 'support';

    /** @return array<int, AdminPermission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => AdminPermission::cases(),
            self::Operations => [
                AdminPermission::ViewDashboard,
                AdminPermission::ReviewSellerApplications,
                AdminPermission::SuspendSellers,
                AdminPermission::ReviewOffers,
                AdminPermission::ManageTaxonomy,
                AdminPermission::ViewOrders,
                AdminPermission::IssueRefunds,
                AdminPermission::ViewPayments,
                AdminPermission::ViewSellerEarnings,
                AdminPermission::ManageOperationalSettings,
            ],
            self::Finance => [
                AdminPermission::ViewDashboard,
                AdminPermission::ViewOrders,
                AdminPermission::IssueRefunds,
                AdminPermission::ViewPayments,
                AdminPermission::ManageCommission,
                AdminPermission::ViewSellerEarnings,
                AdminPermission::DecidePayouts,
            ],
            self::Support => [
                AdminPermission::ViewDashboard,
                AdminPermission::ViewOrders,
                AdminPermission::ViewPayments,
            ],
        };
    }

    public function can(AdminPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

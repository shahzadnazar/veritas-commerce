<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * Platform staff roles.
 *
 * Each role gets what its job needs and nothing adjacent: seller
 * operations governs sellers without touching finance, and finance moves
 * money without governing sellers. Permissions are enforced server-side on
 * every request — hiding a sidebar item is a courtesy, never the control.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case MarketplaceAdmin = 'marketplace_admin';
    case SellerOperations = 'seller_operations';
    case CatalogModerator = 'catalog_moderator';
    case FinanceAdmin = 'finance_admin';
    case Support = 'support';
    case Analyst = 'analyst';

    /** @return array<int, AdminPermission> */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => AdminPermission::cases(),

            self::MarketplaceAdmin => [
                AdminPermission::ViewDashboard,
                AdminPermission::SellerApplicationView,
                AdminPermission::SellerApplicationReview,
                AdminPermission::SellerApprove,
                AdminPermission::SellerReject,
                AdminPermission::SellerSuspend,
                AdminPermission::SellerReactivate,
                AdminPermission::ReviewOffers,
                AdminPermission::ManageTaxonomy,
                AdminPermission::ViewOrders,
                AdminPermission::ViewPayments,
                AdminPermission::ManageOperationalSettings,
            ],

            // Governs sellers. Deliberately holds no finance or catalogue
            // authority: approving a seller and setting the commission rate
            // are different jobs, and one person holding both is how a
            // single compromised account becomes a revenue incident.
            self::SellerOperations => [
                AdminPermission::ViewDashboard,
                AdminPermission::SellerApplicationView,
                AdminPermission::SellerApplicationReview,
                AdminPermission::SellerApprove,
                AdminPermission::SellerReject,
                AdminPermission::SellerSuspend,
                AdminPermission::SellerReactivate,
                AdminPermission::SellerViewSensitive,
                AdminPermission::ViewOrders,
            ],

            self::CatalogModerator => [
                AdminPermission::ViewDashboard,
                AdminPermission::ReviewOffers,
                AdminPermission::ManageTaxonomy,
            ],

            self::FinanceAdmin => [
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
                AdminPermission::SellerApplicationView,
                AdminPermission::ViewOrders,
                AdminPermission::ViewPayments,
            ],

            self::Analyst => [
                AdminPermission::ViewDashboard,
                AdminPermission::ViewOrders,
            ],
        };
    }

    public function can(AdminPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::MarketplaceAdmin => 'Marketplace admin',
            self::SellerOperations => 'Seller operations',
            self::CatalogModerator => 'Catalog moderator',
            self::FinanceAdmin => 'Finance admin',
            self::Support => 'Support',
            self::Analyst => 'Analyst',
        };
    }
}

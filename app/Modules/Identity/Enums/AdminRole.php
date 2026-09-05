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
                AdminPermission::ViewOrdersSensitive,
                AdminPermission::ViewPayments,
                AdminPermission::ViewProviderEvents,
                AdminPermission::ViewFulfilment,
                AdminPermission::OverrideFulfilment,
                AdminPermission::CorrectTracking,
                AdminPermission::ManageOperationalSettings,
                AdminPermission::ViewQueues,
                // Can see the catalogue and move a proposal through
                // review, but not restructure the taxonomy: that belongs
                // to the people who moderate it every day.
                AdminPermission::CatalogueView,
                AdminPermission::CatalogueProductReview,
                AdminPermission::CatalogueProductApprove,
                AdminPermission::CatalogueProductReject,
                AdminPermission::CatalogueProductSuspend,
                AdminPermission::InventoryView,
                AdminPermission::InventoryAdjust,
                AdminPermission::InventoryAudit,
                // Sees the payout queue and can pick a request up for
                // review. Deciding one is finance's, and settling one —
                // the only payout action that writes to a seller's ledger
                // — is finance's alone.
                AdminPermission::ViewPayouts,
                AdminPermission::ReviewPayouts,
                // Reviews are catalogue reputation, and the marketplace
                // admin already decides what stays listed.
                AdminPermission::ViewProductReviews,
                AdminPermission::ModerateProductReviews,
                AdminPermission::ViewMarketplaceAnalytics,
                AdminPermission::ViewSellerAnalytics,
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
                AdminPermission::ViewFulfilment,
                // Reads stock to answer a seller's question; correcting it
                // is the marketplace admin's call, not theirs.
                AdminPermission::InventoryView,
            ],

            // Moderates the catalogue: reviews proposals, decides them,
            // and shapes the taxonomy everyone lists against. Holds no
            // seller-governance or finance authority — approving a product
            // and approving the business selling it are different jobs.
            self::CatalogModerator => [
                AdminPermission::ViewDashboard,
                AdminPermission::ReviewOffers,
                AdminPermission::ManageTaxonomy,
                AdminPermission::CatalogueView,
                AdminPermission::CatalogueProductReview,
                AdminPermission::CatalogueProductApprove,
                AdminPermission::CatalogueProductReject,
                AdminPermission::CatalogueProductSuspend,
                AdminPermission::CatalogueCategoryManage,
                AdminPermission::CatalogueAttributeManage,
                AdminPermission::CatalogueBrandManage,
                AdminPermission::InventoryView,
                // Moderating what is said about a product is the same job
                // as moderating the product.
                AdminPermission::ViewProductReviews,
                AdminPermission::ModerateProductReviews,
                AdminPermission::ViewMarketplaceAnalytics,
            ],

            self::FinanceAdmin => [
                AdminPermission::ViewDashboard,
                AdminPermission::ViewOrders,
                AdminPermission::ViewOrdersSensitive,
                AdminPermission::IssueRefunds,
                AdminPermission::ViewPayments,
                AdminPermission::ViewPaymentsSensitive,
                AdminPermission::ViewProviderEvents,
                AdminPermission::ManageCommission,
                AdminPermission::ViewSellerEarnings,
                AdminPermission::ViewEarningsClearing,
                AdminPermission::ViewFulfilment,
                // Finance runs the payout queue end to end. Phase 1 lets
                // one finance admin review, approve and settle: inventing
                // mandatory dual control nobody asked for teaches people
                // to share a login. The four actor columns are recorded on
                // every request, so a policy requiring different people is
                // a check to add rather than a schema to change.
                AdminPermission::DecidePayouts,
                AdminPermission::ViewPayouts,
                AdminPermission::ReviewPayouts,
                AdminPermission::ApprovePayouts,
                AdminPermission::RejectPayouts,
                AdminPermission::SettlePayouts,
                AdminPermission::ViewPayoutsSensitive,
                AdminPermission::AdjustSellerFinance,
                // Finance reads the trading figures; the reputation side
                // of the catalogue is not theirs to touch.
                AdminPermission::ViewMarketplaceAnalytics,
                AdminPermission::ViewSellerAnalytics,
            ],

            self::Support => [
                AdminPermission::ViewDashboard,
                AdminPermission::SellerApplicationView,
                AdminPermission::ViewOrders,
                AdminPermission::ViewPayments,
                // "Where is my parcel" is the question support is asked
                // most; deciding that it arrived is not their call.
                AdminPermission::ViewFulfilment,
                // Reading a product to answer a customer's question is
                // support's job; deciding one is not.
                AdminPermission::CatalogueView,
                // Likewise stock: "is it actually out of stock" is a
                // support question, "make it not be" is not.
                AdminPermission::InventoryView,
                // "Has my payout gone out yet" is asked of support every
                // day. Approving it, settling it, and seeing which
                // account it goes to are all withheld.
                AdminPermission::ViewPayouts,
                // "Why has my review disappeared" is a support question.
                // Putting it back is not.
                AdminPermission::ViewProductReviews,
            ],

            /*
             * Aggregates, and nothing that names a seller's bank details.
             * An analyst reporting on payout volume needs the totals; the
             * destination behind each one is not part of the question.
             */
            self::Analyst => [
                AdminPermission::ViewDashboard,
                AdminPermission::ViewOrders,
                AdminPermission::CatalogueView,
                AdminPermission::InventoryView,
                AdminPermission::ViewPayouts,
                // The whole point of the role. Reading every figure and
                // being unable to change one is exactly the shape §2
                // asks for.
                AdminPermission::ViewMarketplaceAnalytics,
                AdminPermission::ViewSellerAnalytics,
                AdminPermission::ViewProductReviews,
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

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * What a member of the platform team may do.
 *
 * Capabilities rather than an is_admin flag: a support agent answering
 * "did it charge?" needs to read payments, and must not thereby be able to
 * approve a seller or move the commission rate.
 */
enum AdminPermission: string
{
    case ViewDashboard = 'dashboard.view';

    // Seller governance
    case SellerApplicationView = 'seller.application.view';
    case SellerApplicationReview = 'seller.application.review';
    case SellerApprove = 'seller.approve';
    case SellerReject = 'seller.reject';
    case SellerSuspend = 'seller.suspend';
    case SellerReactivate = 'seller.reactivate';
    case SellerViewSensitive = 'seller.view_sensitive';

    // Catalogue moderation
    case ReviewOffers = 'offers.review';
    case ManageTaxonomy = 'taxonomy.manage';

    /*
     * Catalogue moderation, split so the roles can be too.
     *
     * Reviewing a proposal and publishing it to the storefront are
     * different acts of trust, and neither implies the authority to
     * restructure the taxonomy every seller lists against.
     */
    case CatalogueView = 'catalog.view';
    case CatalogueProductReview = 'catalog.product.review';
    case CatalogueProductApprove = 'catalog.product.approve';
    case CatalogueProductReject = 'catalog.product.reject';
    case CatalogueProductSuspend = 'catalog.product.suspend';
    case CatalogueCategoryManage = 'catalog.category.manage';
    case CatalogueAttributeManage = 'catalog.attribute.manage';
    case CatalogueBrandManage = 'catalog.brand.manage';

    /*
     * Inventory, split view from adjust.
     *
     * Reading a seller's stock to answer "why can nobody buy this" is an
     * everyday support question. Changing that number is the platform
     * reaching into a seller's business, and is not the same act of trust.
     */
    case InventoryView = 'inventory.view';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryAudit = 'inventory.audit';

    // Commerce
    case ViewOrders = 'orders.view';
    case IssueRefunds = 'orders.refund';
    case ViewPayments = 'payments.view';

    // Money
    case ManageCommission = 'commission.manage';
    case ViewSellerEarnings = 'earnings.view';
    case DecidePayouts = 'payouts.decide';

    // Platform
    case ManageOperationalSettings = 'settings.operational';
    case ManageCompanySettings = 'settings.company';
    case ManageStaff = 'staff.manage';
    case ResetAdminMfa = 'staff.reset_mfa';

    /**
     * The queue dashboard.
     *
     * It exposes job payloads, which carry ids and email addresses, so it
     * is not something every admin role gets by default.
     */
    case ViewQueues = 'platform.queues.view';

    public function label(): string
    {
        return $this->value;
    }
}

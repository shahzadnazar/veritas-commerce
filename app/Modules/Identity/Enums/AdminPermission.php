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
    /*
     * The finance half of an order.
     *
     * Support answers "where is my parcel" from the operational data and
     * has no business knowing what the platform took from the seller on
     * that line. Splitting the two is the difference between a role model
     * and an is_admin flag.
     */
    case ViewOrdersSensitive = 'orders.view_sensitive';
    case IssueRefunds = 'orders.refund';
    case ViewPayments = 'payments.view';

    /*
     * The provider's own record of a payment, and the events it sent.
     *
     * Separate from `payments.view` because they answer different
     * questions. "Did this order pay?" is a support question and the
     * attempt status answers it. "What exactly did Stripe send us at
     * 03:14?" is an incident question, and the payload behind it carries
     * provider identifiers, addresses and a description of the payment
     * method — so it is held by the roles that reconcile money, not by
     * everyone who can look an order up.
     */
    case ViewPaymentsSensitive = 'payments.view_sensitive';
    case ViewProviderEvents = 'payments.events.view';

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

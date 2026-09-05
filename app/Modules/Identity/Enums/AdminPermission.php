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

    /*
     * Fulfilment, split three ways because the three are different jobs.
     *
     * Reading it answers "where is the customer's parcel", which is
     * support's question. Correcting tracking rewrites what a customer was
     * told about a delivery, and overriding fulfilment state decides that
     * a parcel arrived — both are the platform contradicting a seller's
     * own record of their own shipment, so neither is something every
     * member of staff should hold.
     */
    case ViewFulfilment = 'fulfilment.view';
    case OverrideFulfilment = 'fulfilment.override';
    case CorrectTracking = 'fulfilment.tracking.correct';

    // Money
    case ManageCommission = 'commission.manage';
    case ViewSellerEarnings = 'earnings.view';

    /*
     * What a seller is owed and when it becomes theirs. Separate from
     * `earnings.view` because it is the number M7 will pay out against,
     * and because seeing a balance is not the same as seeing the schedule
     * that produces it.
     */
    case ViewEarningsClearing = 'earnings.clearing.view';

    /*
     * Payout operations, split five ways because they are five decisions.
     *
     * Reading the queue is a support question ("has my payout been
     * looked at"). Approving one is an authorisation. Recording a
     * settlement is a claim that money left the platform, and it is the
     * only one of the five that writes to the seller ledger. A single
     * `payouts.decide` would have let anyone who can answer the first
     * question do the third.
     */
    case ViewPayouts = 'payouts.view';
    case ReviewPayouts = 'payouts.review';
    case ApprovePayouts = 'payouts.approve';
    case RejectPayouts = 'payouts.reject';
    case SettlePayouts = 'payouts.settle';

    /*
     * The destination behind a payout: the account reference, the last
     * four digits, the country.
     *
     * Nothing here can move money by itself — the platform holds no
     * credentials — but it is a seller's banking identity, and support
     * answering "was I paid" needs the amount and the date, not that.
     */
    case ViewPayoutsSensitive = 'payouts.view_sensitive';

    /*
     * Correcting a seller's balance by hand. Exceptional, audited, and
     * held only by the roles that reconcile money.
     */
    case AdjustSellerFinance = 'finance.adjust';

    /** The kept M0 name, now meaning "may decide a payout at all". */
    case DecidePayouts = 'payouts.decide';

    /*
     * Product reviews, split two ways.
     *
     * Reading the moderation queue is a support question; hiding a review
     * is an editorial act that changes what shoppers see and moves a
     * product's public rating. A single `reviews.manage` would have let
     * anyone who can read the queue silently reshape the catalogue's
     * reputation.
     */
    case ViewProductReviews = 'reviews.view';
    case ModerateProductReviews = 'reviews.moderate';

    /*
     * Marketplace analytics.
     *
     * Separate from `dashboard.view` because these pages carry trading
     * volume, conversion and per-seller performance — the marketplace's
     * commercial position, not its operational status. §2: nothing behind
     * this permission can change anything.
     */
    case ViewMarketplaceAnalytics = 'analytics.view';

    /*
     * Per-seller figures inside the platform's own analytics.
     *
     * Held apart because a seller-by-seller breakdown is the one report
     * that could be lifted out and sold as competitive intelligence.
     */
    case ViewSellerAnalytics = 'analytics.sellers.view';

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

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

    public function label(): string
    {
        return $this->value;
    }
}

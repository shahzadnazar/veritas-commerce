<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

enum AdminPermission: string
{
    case ViewDashboard = 'dashboard.view';
    case ReviewSellerApplications = 'sellers.review_applications';
    case SuspendSellers = 'sellers.suspend';
    case ReviewOffers = 'offers.review';
    case ManageTaxonomy = 'taxonomy.manage';
    case ViewOrders = 'orders.view';
    case IssueRefunds = 'orders.refund';
    case ViewPayments = 'payments.view';
    case ManageCommission = 'commission.manage';
    case ViewSellerEarnings = 'earnings.view';
    case DecidePayouts = 'payouts.decide';
    case ManageOperationalSettings = 'settings.operational';
    case ManageCompanySettings = 'settings.company';
    case ManageStaff = 'staff.manage';

    public function label(): string
    {
        return $this->value;
    }
}

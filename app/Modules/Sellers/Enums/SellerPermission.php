<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * What a member of a seller organisation may do.
 *
 * Capabilities, not screens: authorisation asks "may this actor manage the
 * catalogue", never "is this actor an administrator". Adding a role later
 * is then a matter of listing capabilities rather than hunting for every
 * place a role name was compared.
 */
enum SellerPermission: string
{
    case StoreManage = 'store.manage';
    case MembersView = 'members.view';
    case MembersManage = 'members.manage';
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';
    case InventoryView = 'inventory.view';
    case InventoryManage = 'inventory.manage';
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case FinanceView = 'finance.view';

    /*
     * Reading the payout side of finance, split from requesting one.
     *
     * `finance.view` is the earnings statement — what the store made.
     * `payouts.view` is where that money went and where it is going, which
     * a finance manager needs and a catalogue manager does not.
     */
    case PayoutsView = 'payouts.view';
    case PayoutsRequest = 'payouts.request';

    /*
     * Changing where the money goes.
     *
     * The most dangerous capability in the seller portal: an attacker who
     * can point payouts at their own account does not need to touch
     * anything else. It stays with the owner, and the action behind it
     * asks for a password even when they hold it.
     */
    case PayoutAccountManage = 'payouts.account.manage';

    /*
     * The store's own performance: views, conversion, best sellers.
     *
     * Separate from `finance.view`, which is what the store *made*. A
     * catalogue manager deciding which photographs to reshoot needs to see
     * that a listing gets traffic and no orders; they have no business
     * seeing the earnings statement. §2: nothing behind this permission
     * changes anything.
     */
    case AnalyticsView = 'analytics.view';

    public function label(): string
    {
        return $this->value;
    }
}

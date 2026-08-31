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
    case PayoutsRequest = 'payouts.request';

    public function label(): string
    {
        return $this->value;
    }
}

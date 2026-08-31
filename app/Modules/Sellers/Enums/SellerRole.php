<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * A user's role within one seller organisation.
 *
 * Owner is deliberately the only role that can change the team or move
 * money: those two capabilities are how a compromised staff account turns
 * into a stolen business, so they stay with the person who owns it.
 */
enum SellerRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case CatalogManager = 'catalog_manager';
    case InventoryManager = 'inventory_manager';
    case FulfillmentManager = 'fulfillment_manager';
    case FinanceManager = 'finance_manager';
    case Viewer = 'viewer';

    /** @return array<int, SellerPermission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => SellerPermission::cases(),

            // Everything operational, but not the team and not the money:
            // an administrator runs the shop, the owner owns it.
            self::Administrator => [
                SellerPermission::StoreManage,
                SellerPermission::MembersView,
                SellerPermission::CatalogView,
                SellerPermission::CatalogManage,
                SellerPermission::InventoryView,
                SellerPermission::InventoryManage,
                SellerPermission::OrdersView,
                SellerPermission::OrdersManage,
                SellerPermission::FinanceView,
            ],

            self::CatalogManager => [
                SellerPermission::CatalogView,
                SellerPermission::CatalogManage,
                SellerPermission::InventoryView,
            ],

            self::InventoryManager => [
                SellerPermission::CatalogView,
                SellerPermission::InventoryView,
                SellerPermission::InventoryManage,
                SellerPermission::OrdersView,
            ],

            self::FulfillmentManager => [
                SellerPermission::CatalogView,
                SellerPermission::InventoryView,
                SellerPermission::OrdersView,
                SellerPermission::OrdersManage,
            ],

            self::FinanceManager => [
                SellerPermission::OrdersView,
                SellerPermission::FinanceView,
            ],

            self::Viewer => [
                SellerPermission::CatalogView,
                SellerPermission::InventoryView,
                SellerPermission::OrdersView,
            ],
        };
    }

    public function can(SellerPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Only the owner may transfer or dissolve the organisation. */
    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Administrator => 'Administrator',
            self::CatalogManager => 'Catalog manager',
            self::InventoryManager => 'Inventory manager',
            self::FulfillmentManager => 'Fulfillment manager',
            self::FinanceManager => 'Finance manager',
            self::Viewer => 'Viewer',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * A user's role within one seller account.
 *
 * Phase 1 creates only an Owner per seller, but authorisation already asks
 * "which seller is this actor scoped to, and with what role", so adding
 * staff to a store later changes data, not the authorisation model.
 */
enum SellerRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    public function canManageStore(): bool
    {
        return $this === self::Owner;
    }

    public function canManageOffers(): bool
    {
        return in_array($this, [self::Owner, self::Manager, self::Staff], true);
    }

    public function canFulfilOrders(): bool
    {
        return true;
    }

    public function canRequestPayout(): bool
    {
        return $this === self::Owner;
    }

    public function canViewEarnings(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

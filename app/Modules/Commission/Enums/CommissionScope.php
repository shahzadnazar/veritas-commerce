<?php

declare(strict_types=1);

namespace App\Modules\Commission\Enums;

/**
 * How specific a commission rule is.
 *
 * Phase 1 ships only Global, but resolution already walks the ladder most
 * specific first, so category, seller and campaign rules are a data change
 * rather than a rewrite.
 */
enum CommissionScope: string
{
    case Global = 'global';
    case Category = 'category';
    case Seller = 'seller';
    case SellerCategory = 'seller_category';
    case Campaign = 'campaign';

    /** Higher wins. Resolution picks the highest-precedence applicable rule. */
    public function precedence(): int
    {
        return match ($this) {
            self::Global => 0,
            self::Category => 10,
            self::Seller => 20,
            self::SellerCategory => 30,
            self::Campaign => 40,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Platform default',
            self::Category => 'Category',
            self::Seller => 'Seller',
            self::SellerCategory => 'Seller + category',
            self::Campaign => 'Campaign',
        };
    }
}

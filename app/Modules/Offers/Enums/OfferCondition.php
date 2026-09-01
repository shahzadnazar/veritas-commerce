<?php

declare(strict_types=1);

namespace App\Modules\Offers\Enums;

/**
 * What state the goods are in.
 *
 * A closed set because condition is a comparison axis, not a description:
 * two offers on one product page have to be comparable, and free text
 * ("mint!", "barely used") makes that impossible.
 */
enum OfferCondition: string
{
    case New = 'new';
    case Refurbished = 'refurbished';
    case UsedLikeNew = 'used_like_new';
    case UsedGood = 'used_good';
    case UsedAcceptable = 'used_acceptable';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Refurbished => 'Refurbished',
            self::UsedLikeNew => 'Used — like new',
            self::UsedGood => 'Used — good',
            self::UsedAcceptable => 'Used — acceptable',
        };
    }

    /**
     * Best first.
     *
     * Used to break a price tie: between two offers at the same price, the
     * one in better condition is the better offer.
     */
    public function rank(): int
    {
        return match ($this) {
            self::New => 0,
            self::Refurbished => 1,
            self::UsedLikeNew => 2,
            self::UsedGood => 3,
            self::UsedAcceptable => 4,
        };
    }

    public function isUsed(): bool
    {
        return $this !== self::New;
    }
}

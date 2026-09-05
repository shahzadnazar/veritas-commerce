<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Enums;

/**
 * Why this customer cannot review this product right now.
 *
 * Returned to the screen so a "Write a review" button appears exactly when
 * it would work, and so the absence of one can be explained. The enum name
 * never reaches a customer; the wording below does.
 *
 * Checked worst-first: a customer who never bought the thing is told that,
 * not that their parcel has not arrived.
 */
enum ReviewIneligibility: string
{
    case NotPurchased = 'not_purchased';
    case NotPaid = 'not_paid';
    case NotDelivered = 'not_delivered';
    case FullyRefunded = 'fully_refunded';
    case AlreadyReviewed = 'already_reviewed';
    case Rejected = 'rejected';

    public function message(): string
    {
        return match ($this) {
            self::NotPurchased => 'You can review a product once you have bought it here.',
            self::NotPaid => 'This order has not been paid for yet.',
            self::NotDelivered => 'You can write a review once your order has been delivered.',
            self::FullyRefunded => 'This item was refunded in full, so there is nothing to review.',
            self::AlreadyReviewed => 'You have already reviewed this product. You can edit your review instead.',
            self::Rejected => 'Your review of this product was not accepted. Contact support if you think that is wrong.',
        };
    }
}

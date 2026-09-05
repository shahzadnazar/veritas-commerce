<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/**
 * The platform will not make this fulfilment change.
 *
 * A reason code the caller branches on and a message a person reads — the
 * same split the payment domain uses, and for the same reason: a seller
 * shown `INVALID_TRANSITION` has been handed the inside of the machine and
 * nothing they can act on.
 */
final class FulfilmentRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'refused')
    {
        parent::__construct($message);
    }

    public static function notPaid(): self
    {
        return new self(
            'This order cannot be worked on until its payment is confirmed.',
            'not_paid',
        );
    }

    public static function notConfirmed(): self
    {
        return new self(
            'Confirm this order before packing it, so the customer knows you have accepted it.',
            'not_confirmed',
        );
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("An order that is {$from} cannot become {$to}.", 'invalid_transition');
    }

    public static function notThisSellersItem(): self
    {
        return new self('That item is not part of this order.', 'item_not_in_order');
    }

    public static function nothingToShip(): self
    {
        return new self('A shipment needs at least one item.', 'no_items');
    }

    public static function exceedsRemaining(string $title, int $remaining): self
    {
        return new self(
            $remaining === 0
                ? "There is nothing left to ship for {$title}."
                : "Only {$remaining} of {$title} remain to be shipped.",
            'exceeds_remaining',
        );
    }

    public static function shipmentAlreadyGone(): self
    {
        return new self('This parcel has already been sent.', 'already_shipped');
    }

    public static function shipmentNotSent(): self
    {
        return new self('A parcel cannot arrive before it has been sent.', 'not_shipped');
    }

    public static function trackingRequired(): self
    {
        return new self(
            'A carrier and a tracking number are needed before this parcel can be marked sent.',
            'tracking_required',
        );
    }

    public static function trackingIsHistory(): self
    {
        return new self(
            'This parcel has been delivered; its tracking is a historical record and correcting it '
            .'needs a platform administrator.',
            'tracking_is_history',
        );
    }

    public static function reasonRequired(): self
    {
        return new self('This change needs a written reason.', 'reason_required');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * The platform will not prepare or finalize this payment.
 *
 * Carries a reason code the caller branches on and a message the customer
 * reads — the same split the checkout uses, and for the same reason: a
 * shopper shown "AMOUNT_MISMATCH" has been given the inside of the machine
 * and nothing they can act on.
 */
final class PaymentRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'not_payable')
    {
        parent::__construct($message);
    }

    public static function orderNotPayable(): self
    {
        return new self('This order can no longer be paid.', 'order_not_payable');
    }

    public static function alreadyPaid(): self
    {
        return new self('This order has already been paid.', 'already_paid');
    }

    public static function expired(): self
    {
        return new self('The payment window for this order has closed.', 'order_expired');
    }
}

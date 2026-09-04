<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * What a payment transaction row records.
 *
 * Signed amounts: a capture is positive, a refund negative, so an order's
 * net position is a sum rather than a case expression somebody has to keep
 * in step across four reports.
 */
enum PaymentTransactionType: string
{
    case Capture = 'capture';
    case Refund = 'refund';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Capture => 'Capture',
            self::Refund => 'Refund',
            self::Reversal => 'Reversal',
        };
    }
}

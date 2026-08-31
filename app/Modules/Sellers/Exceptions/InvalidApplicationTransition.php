<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Exceptions;

use App\Modules\Sellers\Enums\SellerApplicationStatus;
use RuntimeException;

final class InvalidApplicationTransition extends RuntimeException
{
    public static function between(SellerApplicationStatus $from, SellerApplicationStatus $to): self
    {
        return new self("An application cannot move from {$from->value} to {$to->value}.");
    }

    public static function reasonRequired(SellerApplicationStatus $to): self
    {
        return new self("Moving an application to {$to->value} requires a written reason.");
    }
}

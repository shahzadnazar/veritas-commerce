<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Exceptions;

use App\Modules\Sellers\Enums\SellerStatus;
use RuntimeException;

final class SellerActionNotPermitted extends RuntimeException
{
    public static function suspended(SellerStatus $status): self
    {
        return new self("A {$status->label()} store cannot perform operational actions.");
    }
}

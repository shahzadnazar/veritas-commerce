<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Modules\Catalog\Data\DuplicateMatch;
use RuntimeException;

/**
 * The catalogue already holds this product.
 *
 * Carries the match so the seller can be shown what to list against
 * instead. "This already exists" with no way to reach it is the fastest
 * route to a duplicate created under a slightly different name.
 */
final class DuplicateProduct extends RuntimeException
{
    public function __construct(public readonly DuplicateMatch $match)
    {
        parent::__construct($match->explanation);
    }
}

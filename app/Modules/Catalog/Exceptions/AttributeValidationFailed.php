<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

/**
 * Specifications the category would not accept.
 *
 * Carries one message per attribute so a controller can attach each to the
 * field that caused it, rather than showing one banner for a form with
 * fifteen inputs.
 */
final class AttributeValidationFailed extends RuntimeException
{
    /** @param  array<string, string>  $errors  attribute code => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Those specifications are not valid for this category: '.implode(' ', $errors));
    }
}

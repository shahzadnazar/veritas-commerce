<?php

declare(strict_types=1);

namespace App\Modules\Cart\Exceptions;

use App\Modules\Cart\Enums\CartIssueCode;
use RuntimeException;

/**
 * A cart operation the marketplace will not perform.
 *
 * Carries the structured code as well as the sentence, so a controller can
 * turn it into a field error and a test can assert on the reason rather
 * than on wording.
 */
final class CartOperationRefused extends RuntimeException
{
    /**
     * Named `$issue` rather than `$code`: Exception already has a `$code`,
     * an int, and shadowing it would make getCode() return an enum.
     */
    public function __construct(
        public readonly CartIssueCode $issue,
        string $message,
        public readonly ?int $available = null,
    ) {
        parent::__construct($message);
    }

    public static function offerUnavailable(): self
    {
        return new self(
            CartIssueCode::OfferUnavailable,
            'That listing is not available to buy.',
        );
    }

    public static function insufficientStock(int $available): self
    {
        return new self(
            CartIssueCode::QuantityReduced,
            $available === 0
                ? 'That listing has sold out.'
                : "Only {$available} left, so that quantity is not available.",
            $available,
        );
    }

    public static function variantMismatch(): self
    {
        return new self(
            CartIssueCode::VariantUnavailable,
            'That option does not belong to this listing.',
        );
    }
}

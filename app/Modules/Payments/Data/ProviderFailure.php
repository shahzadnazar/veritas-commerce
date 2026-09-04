<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

/**
 * Why a provider refused, in two registers.
 *
 * The code and the provider's own message are kept for the operator and
 * the support conversation. Neither is ever shown to the customer: §53
 * draws that line, and it is not only about tone — a raw provider message
 * can name internal configuration, and a decline code tells a card tester
 * exactly which card to try next.
 */
final readonly class ProviderFailure
{
    public function __construct(
        public ?string $code = null,
        public ?string $declineCode = null,
        /** The provider's wording. For operators, never for customers. */
        public ?string $message = null,
        /** Whether the customer could reasonably succeed by trying again. */
        public bool $retryable = true,
    ) {}
}

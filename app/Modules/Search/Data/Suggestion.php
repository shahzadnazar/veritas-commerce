<?php

declare(strict_types=1);

namespace App\Modules\Search\Data;

/**
 * One autocomplete row.
 *
 * Carries what it is as well as what it says, so the dropdown can send a
 * brand suggestion to a filtered search and a product suggestion straight
 * to the product — a suggestion list that only returns strings makes the
 * frontend guess.
 */
final readonly class Suggestion
{
    public const PRODUCT = 'product';

    public const BRAND = 'brand';

    public const CATEGORY = 'category';

    public function __construct(
        public string $type,
        public string $label,
        /** Where selecting it goes. */
        public string $url,
        public ?string $context = null,
    ) {}
}

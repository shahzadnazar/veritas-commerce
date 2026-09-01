<?php

declare(strict_types=1);

namespace App\Modules\Search\Data;

/** One option in a facet, with how many results carry it. */
final readonly class FacetValue
{
    public function __construct(
        public string $value,
        public string $label,
        public int $count,
        public bool $selected = false,
    ) {}
}

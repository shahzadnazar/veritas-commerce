<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product addresses.
 *
 * A product URL accumulates search authority for years, so it is derived
 * from the title rather than the id, kept unique, and never silently
 * reused. When a title is corrected the old address keeps working: a
 * typo fixed in year two must not cost the position earned in year one.
 */
final class ProductSlug
{
    /**
     * Words a product slug may not take, because /products/{slug} shares
     * its namespace with these.
     */
    public const RESERVED = ['new', 'edit', 'create', 'search', 'compare', 'all', 'null', 'undefined'];

    public static function normalise(string $title): string
    {
        // Punctuation separates rather than disappears: Str::slug() alone
        // turns "1.2L" into "12l", which reads as a different capacity.
        return Str::of($title)
            ->ascii()
            ->replaceMatches('/[^a-zA-Z0-9]+/', ' ')
            ->slug()
            ->limit(80, '')
            ->trim('-')
            ->value();
    }

    /**
     * A slug nothing else is using — not a live product, and not the
     * history of one, because an old address still resolves.
     */
    public static function unique(string $title, ?int $ignoreProductId = null): string
    {
        $base = self::normalise($title);

        if ($base === '' || in_array($base, self::RESERVED, true)) {
            $base = 'product';
        }

        $slug = $base;
        $suffix = 2;

        while (self::taken($slug, $ignoreProductId)) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private static function taken(string $slug, ?int $ignoreProductId): bool
    {
        $live = Product::query()
            ->where('slug', $slug)
            ->when($ignoreProductId !== null, fn ($query) => $query->whereKeyNot($ignoreProductId))
            ->exists();

        if ($live) {
            return true;
        }

        // A retired address is still an address: handing it to a second
        // product would redirect its traffic to a stranger.
        return DB::table('product_slug_history')
            ->where('old_slug', $slug)
            ->when($ignoreProductId !== null, fn ($query) => $query->where('product_id', '!=', $ignoreProductId))
            ->exists();
    }
}

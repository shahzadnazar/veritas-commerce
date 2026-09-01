<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Catalog\Events\ProductEdited;
use App\Modules\Catalog\Exceptions\AttributeValidationFailed;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CatalogueText;
use App\Modules\Catalog\Support\ProductSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Editing the canonical product itself.
 *
 * The catalogue entry belongs to the marketplace, not to whoever proposed
 * it. Three sellers list against one product, and a change to its title,
 * category or barcode changes what all three appear to be selling — so
 * once a proposal has been accepted, only a catalogue admin may touch it.
 * The proposing seller keeps their own draft until that point and nothing
 * after it.
 *
 * Renaming does not throw away the old address. A title corrected in year
 * two must not cost the search position earned in year one, so the
 * previous slug is retired into history and continues to redirect.
 */
final class UpdateCanonicalProduct
{
    /** The product's own identity. Status is not here: it is a decision. */
    private const EDITABLE = [
        'title', 'description', 'category_id', 'brand_id',
        'gtin', 'upc', 'ean', 'isbn', 'mpn', 'model_number',
        'seo_title', 'seo_description',
    ];

    private const IDENTIFIERS = ['gtin', 'upc', 'ean', 'isbn'];

    public function __construct(
        private readonly SaveAttributeValues $saveValues,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  a subset of EDITABLE
     * @param  array<string, mixed>|null  $specifications  null leaves them alone
     *
     * @throws AttributeValidationFailed
     */
    public function __invoke(
        Product $product,
        array $attributes,
        ?array $specifications,
        string $actorType,
        int $actorId,
    ): Product {
        /*
         * The rule §9 exists for. A seller may correct their own proposal
         * while it is still theirs; the moment it is part of the shared
         * catalogue, an edit is a platform decision. Checked here rather
         * than only in the controller, so a second caller cannot skip it.
         */
        if ($actorType !== 'admin' && ! $product->status->isEditableByProposer()) {
            throw new RuntimeException(
                'This product is part of the marketplace catalogue. Ask a moderator to change it.'
            );
        }

        return DB::transaction(function () use ($product, $attributes, $specifications, $actorType, $actorId): Product {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $before = [];
            $after = [];

            foreach (self::EDITABLE as $column) {
                if (! array_key_exists($column, $attributes)) {
                    continue;
                }

                $value = $this->normalise($column, $attributes[$column]);

                // A product always has a title and a category. An empty
                // one in a payload is a mistake, not an instruction to
                // erase them.
                if ($value === null && in_array($column, ['title', 'category_id'], true)) {
                    continue;
                }

                if ($value === $locked->getAttribute($column)) {
                    continue;
                }

                $before[$column] = $locked->getAttribute($column);
                $after[$column] = $value;
                $locked->setAttribute($column, $value);
            }

            if (array_key_exists('title', $after)) {
                $title = (string) $after['title'];
                $locked->normalised_title = CatalogueText::normalise($title);

                // The old address is retired, not reused: it keeps
                // resolving, and no other product may ever take it.
                DB::table('product_slug_history')->insert([
                    'product_id' => $locked->id,
                    'old_slug' => $locked->slug,
                    'changed_at' => now(),
                ]);

                $locked->slug = ProductSlug::unique($title, $locked->id);
            }

            $locked->save();

            if ($specifications !== null) {
                ($this->saveValues)($locked, $specifications);
            }

            // Nothing changed is not an event and not an audit entry: a
            // log full of no-ops is a log nobody reads.
            if ($after === [] && $specifications === null) {
                return $locked;
            }

            ($this->audit)(
                action: 'catalogue.product.edited',
                actorType: $actorType,
                actorId: $actorId,
                subjectType: Product::class,
                subjectId: $locked->id,
                changes: ['from' => $before, 'to' => $after],
            );

            $changed = array_keys($after);

            DB::afterCommit(function () use ($locked, $actorType, $actorId, $changed): void {
                Event::dispatch(new ProductEdited($locked->id, $actorType, $actorId, $changed));
            });

            return $locked;
        });
    }

    private function normalise(string $column, mixed $value): mixed
    {
        if (in_array($column, self::IDENTIFIERS, true)) {
            return CatalogueText::normaliseIdentifier(is_string($value) ? $value : null);
        }

        if (in_array($column, ['category_id', 'brand_id'], true)) {
            return $value === null || $value === '' ? null : (int) $value;
        }

        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}

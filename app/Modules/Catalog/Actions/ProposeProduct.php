<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Events\ProductProposed;
use App\Modules\Catalog\Exceptions\AttributeValidationFailed;
use App\Modules\Catalog\Exceptions\DuplicateProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\FindDuplicateProduct;
use App\Modules\Catalog\Support\CatalogueText;
use App\Modules\Catalog\Support\ProductSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * A seller proposing a product the catalogue does not have.
 *
 * The proposing seller is recorded as provenance. Ownership is not
 * transferred with it: once approved the entry belongs to the marketplace,
 * because three sellers will list against it and none of them may edit
 * what the other two are selling.
 *
 * A conclusive duplicate is refused outright rather than queued — sending
 * a moderator a proposal for a barcode the catalogue already holds wastes
 * their time and the seller's.
 */
final class ProposeProduct
{
    public function __construct(
        private readonly FindDuplicateProduct $findDuplicate,
        private readonly SaveAttributeValues $saveValues,
        private readonly TransitionProduct $transition,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  the product's own columns
     * @param  array<string, mixed>  $specifications  attribute code => value
     *
     * @throws DuplicateProduct
     * @throws AttributeValidationFailed
     */
    public function __invoke(
        array $attributes,
        array $specifications,
        int $sellerAccountId,
        bool $submitForReview = true,
    ): Product {
        $identifiers = [
            'gtin' => CatalogueText::normaliseIdentifier($attributes['gtin'] ?? null),
            'upc' => CatalogueText::normaliseIdentifier($attributes['upc'] ?? null),
            'ean' => CatalogueText::normaliseIdentifier($attributes['ean'] ?? null),
            'isbn' => CatalogueText::normaliseIdentifier($attributes['isbn'] ?? null),
            'mpn' => $attributes['mpn'] ?? null,
            'model_number' => $attributes['model_number'] ?? null,
        ];

        $title = (string) ($attributes['title'] ?? '');

        $matches = ($this->findDuplicate)(
            title: $title,
            brandId: isset($attributes['brand_id']) ? (int) $attributes['brand_id'] : null,
            categoryId: isset($attributes['category_id']) ? (int) $attributes['category_id'] : null,
            identifiers: $identifiers,
        );

        $conclusive = $this->findDuplicate->conclusiveIn($matches);

        if ($conclusive !== null) {
            // Not a validation message: the seller wanted to sell this, and
            // the answer is "you can — list against the product we already
            // have", which the exception carries.
            throw new DuplicateProduct($conclusive);
        }

        return DB::transaction(function () use ($attributes, $identifiers, $specifications, $sellerAccountId, $title, $matches, $submitForReview): Product {
            /*
             * Built field by field rather than spread from the caller's
             * array. A proposal may describe a product; it may not decide
             * its moderation state or claim someone else proposed it, and
             * spreading an untyped bag into create() is exactly how a
             * `status => published` in a payload gets honoured.
             */
            $product = Product::query()->create([
                'title' => $title,
                'normalised_title' => CatalogueText::normalise($title),
                'slug' => ProductSlug::unique($title),
                'description' => isset($attributes['description']) ? (string) $attributes['description'] : null,
                'category_id' => (int) $attributes['category_id'],
                'brand_id' => isset($attributes['brand_id']) ? (int) $attributes['brand_id'] : null,
                'gtin' => $identifiers['gtin'],
                'upc' => $identifiers['upc'],
                'ean' => $identifiers['ean'],
                'isbn' => $identifiers['isbn'],
                'mpn' => $this->text($attributes, 'mpn'),
                'model_number' => $this->text($attributes, 'model_number'),
                'seo_title' => $this->text($attributes, 'seo_title'),
                'seo_description' => $this->text($attributes, 'seo_description'),
                'created_by_seller_account_id' => $sellerAccountId,
                'status' => ProductStatus::Draft->value,
            ]);

            // Specifications are checked against the category schema before
            // anything is queued for review: a moderator should never be
            // handed a proposal that could not have been valid.
            ($this->saveValues)($product, $specifications);

            ($this->audit)(
                action: 'catalogue.product.proposed',
                actorType: 'seller',
                actorId: $sellerAccountId,
                subjectType: Product::class,
                subjectId: $product->id,
                changes: [
                    'title' => $product->title,
                    'category_id' => $product->category_id,
                    // A weak match does not block the proposal, but the
                    // moderator should see what it resembled.
                    'possible_duplicates' => array_map(
                        static fn ($match): string => $match->product->public_id,
                        $matches,
                    ),
                ],
            );

            if ($submitForReview) {
                ($this->transition)($product, ProductStatus::PendingReview, 'seller', $sellerAccountId);
            }

            DB::afterCommit(function () use ($product, $sellerAccountId): void {
                Event::dispatch(new ProductProposed($product->id, $sellerAccountId));
            });

            return $product->refresh();
        });
    }

    /** @param  array<string, mixed>  $attributes */
    private function text(array $attributes, string $key): ?string
    {
        $value = trim((string) ($attributes[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}

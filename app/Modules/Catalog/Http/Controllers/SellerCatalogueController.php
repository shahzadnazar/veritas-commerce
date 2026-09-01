<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Actions\ProposeProduct;
use App\Modules\Catalog\Actions\ResolveBrand;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Actions\UpdateCanonicalProduct;
use App\Modules\Catalog\Data\DuplicateMatch;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\AttributeValidationFailed;
use App\Modules\Catalog\Exceptions\DuplicateProduct;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\AttributeOption;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\FindDuplicateProduct;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The seller's route into the catalogue.
 *
 * The order of operations is the point: search first, propose only if
 * nothing matches. Making "create a new product" the default action is how
 * a marketplace ends up with eleven entries for one kettle and a customer
 * unable to compare any of them.
 */
final class SellerCatalogueController
{
    public function __construct(
        private readonly FindDuplicateProduct $findDuplicate,
        private readonly ProposeProduct $propose,
        private readonly UpdateCanonicalProduct $update,
        private readonly TransitionProduct $transition,
        private readonly ResolveBrand $brands,
    ) {}

    /** Search the canonical catalogue, and see your own proposals. */
    public function index(Request $request): Response
    {
        $sellerId = $this->sellerId();
        $search = trim($request->string('search')->toString());

        $matches = $search === '' ? [] : Product::query()
            ->with(['brand', 'category'])
            ->whereIn('status', [ProductStatus::Approved->value, ProductStatus::Published->value])
            ->whereNull('merged_into_product_id')
            ->where(function ($query) use ($search): void {
                $query->where('title', 'ilike', '%'.$search.'%')
                    ->orWhere('gtin', $search)
                    ->orWhere('ean', $search)
                    ->orWhere('upc', $search)
                    ->orWhere('mpn', $search);
            })
            ->orderBy('title')
            ->limit(20)
            ->get()
            ->map(fn (Product $product): array => [
                'publicId' => $product->public_id,
                'title' => $product->title,
                'brand' => $product->brand?->name,
                'category' => $product->category?->name,
                'identifiers' => $product->identifiers(),
                // Whether this seller already lists it, so the action can
                // say "edit your listing" rather than offering a duplicate.
                'alreadyListed' => $product->offers()
                    ->where('seller_account_id', $sellerId)
                    ->exists(),
            ])
            ->all();

        return Inertia::render('Catalogue/Search', [
            'search' => $search,
            'matches' => $matches,
            'proposals' => Product::query()
                ->with('category')
                ->where('created_by_seller_account_id', $sellerId)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (Product $product): array => [
                    'publicId' => $product->public_id,
                    'title' => $product->title,
                    'status' => $product->status->value,
                    'category' => $product->category?->name,
                    'moderationReason' => $product->moderation_reason,
                    'canEdit' => $product->status->isEditableByProposer(),
                    'canList' => $product->status->acceptsOffers(),
                ])
                ->all(),
        ]);
    }

    /** The form for a product the catalogue genuinely does not have. */
    public function create(Request $request): Response
    {
        $categoryId = $request->integer('category');
        $category = $categoryId > 0 ? Category::query()->find($categoryId) : null;
        $title = $request->string('title')->toString();
        $brandId = $request->integer('brand');

        /*
         * One last look before the form.
         *
         * Search already ran, but a seller who typed a title no search
         * matched may still be about to duplicate something the same
         * detection would refuse on submit. Showing it here means they see
         * the existing product while there is still a cheaper option than
         * proposing — and it is the same deterministic check, not a second
         * opinion that could disagree with it.
         */
        $likelyDuplicates = $title === '' ? [] : array_map(
            static fn (DuplicateMatch $match): array => [
                'publicId' => $match->product->public_id,
                'title' => $match->product->title,
                'explanation' => $match->explanation,
            ],
            ($this->findDuplicate)($title, $brandId > 0 ? $brandId : null, $category?->id),
        );

        return Inertia::render('Catalogue/Propose', [
            'likelyDuplicates' => $likelyDuplicates,
            'editing' => null,
            'categories' => $this->categoryOptions(),
            'selectedCategoryId' => $category?->id,
            // The specification schema is the category's, so the form can
            // only be built once one is chosen.
            'attributes' => $this->attributeFields($category),
            'brands' => $this->brandOptions(),
            'prefill' => ['title' => $title],
        ]);
    }

    /** @return array<int, array{id: int, name: string}> */
    private function categoryOptions(): array
    {
        return Category::query()->where('is_visible', true)->orderBy('path')->get()
            ->map(fn (Category $item): array => [
                'id' => $item->id,
                'name' => str_repeat('— ', $item->depth).$item->name,
            ])
            ->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    private function brandOptions(): array
    {
        return Brand::query()->where('is_active', true)->orderBy('name')->limit(200)->get()
            ->map(fn (Brand $brand): array => ['id' => $brand->id, 'name' => $brand->name])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function attributeFields(?Category $category): array
    {
        if ($category === null) {
            return [];
        }

        return $category->effectiveAttributes()
            ->map(fn (Attribute $attribute): array => [
                'code' => $attribute->code,
                'name' => $attribute->name,
                'type' => $attribute->data_type->value,
                'unit' => $attribute->unit,
                'required' => $attribute->isRequiredByLoadedCategories(),
                'options' => $attribute->options
                    ->map(fn (AttributeOption $option): array => [
                        'value' => $option->value,
                        'label' => $option->label,
                    ])
                    ->all(),
            ])
            ->values()
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(CurrentSeller::can(SellerPermission::CatalogManage), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'new_brand' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'gtin' => ['nullable', 'string', 'max:14'],
            'upc' => ['nullable', 'string', 'max:12'],
            'ean' => ['nullable', 'string', 'max:13'],
            'isbn' => ['nullable', 'string', 'max:17'],
            'mpn' => ['nullable', 'string', 'max:120'],
            'model_number' => ['nullable', 'string', 'max:120'],
            'specifications' => ['array'],
        ]);

        $sellerId = $this->sellerId();

        // A brand the seller could not find is proposed, not minted: it
        // arrives unapproved and invisible until a moderator accepts it.
        if (($validated['new_brand'] ?? null) !== null && ($validated['brand_id'] ?? null) === null) {
            $validated['brand_id'] = $this->brands->propose($validated['new_brand'], $sellerId)->id;
        }

        try {
            $product = ($this->propose)(
                attributes: $validated,
                specifications: $validated['specifications'] ?? [],
                sellerAccountId: $sellerId,
            );
        } catch (DuplicateProduct $duplicate) {
            // Not a dead end: the seller wanted to sell this, and the
            // answer is "list against the one we already have".
            throw ValidationException::withMessages([
                'gtin' => $duplicate->getMessage()
                    .' List against it instead: '.route('seller.offers.create', ['product' => $duplicate->match->product->public_id]),
            ]);
        } catch (AttributeValidationFailed $failed) {
            throw ValidationException::withMessages(
                array_combine(
                    array_map(static fn (string $code): string => 'specifications.'.$code, array_keys($failed->errors)),
                    array_values($failed->errors),
                ),
            );
        }

        return redirect()
            ->route('seller.products')
            ->with('success', "“{$product->title}” is with the catalogue team.");
    }

    /**
     * Correcting a proposal a moderator sent back.
     *
     * Scoped twice: to proposals this seller made, and to the states in
     * which a proposal is still theirs. An approved product is part of the
     * shared catalogue, and a seller editing it would silently change what
     * every other seller listing against it appears to be selling.
     */
    public function edit(Request $request, string $publicId): Response
    {
        $product = $this->ownProposal($publicId);
        $category = $product->category;

        return Inertia::render('Catalogue/Propose', [
            'likelyDuplicates' => [],
            'editing' => [
                'publicId' => $product->public_id,
                'status' => $product->status->value,
                'moderationReason' => $product->moderation_reason,
            ],
            'categories' => $this->categoryOptions(),
            'selectedCategoryId' => $product->category_id,
            'attributes' => $this->attributeFields($category),
            'brands' => $this->brandOptions(),
            'prefill' => [
                'title' => $product->title,
                'description' => (string) $product->description,
                'brand_id' => $product->brand_id === null ? '' : (string) $product->brand_id,
                'gtin' => (string) $product->gtin,
                'upc' => (string) $product->upc,
                'ean' => (string) $product->ean,
                'isbn' => (string) $product->isbn,
                'mpn' => (string) $product->mpn,
                'model_number' => (string) $product->model_number,
                'specifications' => $this->currentSpecifications($product),
            ],
        ]);
    }

    public function update(Request $request, string $publicId): RedirectResponse
    {
        abort_unless(CurrentSeller::can(SellerPermission::CatalogManage), 403);

        $product = $this->ownProposal($publicId);

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'gtin' => ['nullable', 'string', 'max:14'],
            'upc' => ['nullable', 'string', 'max:12'],
            'ean' => ['nullable', 'string', 'max:13'],
            'isbn' => ['nullable', 'string', 'max:17'],
            'mpn' => ['nullable', 'string', 'max:120'],
            'model_number' => ['nullable', 'string', 'max:120'],
            'specifications' => ['array'],
        ]);

        try {
            ($this->update)(
                $product,
                $validated,
                $validated['specifications'] ?? [],
                'seller',
                $this->sellerId(),
            );
        } catch (AttributeValidationFailed $failed) {
            throw ValidationException::withMessages(
                array_combine(
                    array_map(static fn (string $code): string => 'specifications.'.$code, array_keys($failed->errors)),
                    array_values($failed->errors),
                ),
            );
        }

        /*
         * A correction goes back into the queue.
         *
         * changes_requested means a moderator is waiting for exactly this,
         * so the seller should not have to find a second button to say
         * they are done. A draft stays a draft.
         */
        if ($product->refresh()->status === ProductStatus::ChangesRequested) {
            ($this->transition)($product, ProductStatus::PendingReview, 'seller', $this->sellerId());
        }

        return redirect()
            ->route('seller.products')
            ->with('success', 'Your corrections are back with the catalogue team.');
    }

    /**
     * A proposal this seller made and may still change.
     *
     * A product belonging to another seller does not resolve, and neither
     * does one that has already been accepted — so a crafted id reaches a
     * 404, not somebody else's catalogue entry.
     */
    private function ownProposal(string $publicId): Product
    {
        $product = Product::query()
            ->with(['category', 'attributeValues.attribute'])
            ->where('public_id', $publicId)
            ->where('created_by_seller_account_id', $this->sellerId())
            ->firstOrFail();

        abort_unless($product->status->isEditableByProposer(), 403);

        return $product;
    }

    /** @return array<string, string> attribute code => current value */
    private function currentSpecifications(Product $product): array
    {
        $values = [];

        foreach ($product->attributeValues as $value) {
            if ($value->product_variant_id !== null || $value->attribute === null) {
                continue;
            }

            $values[$value->attribute->code] = (string) $value->raw();
        }

        return $values;
    }

    private function sellerId(): int
    {
        $sellerId = CurrentSeller::id();
        abort_if($sellerId === null, 404);

        return $sellerId;
    }
}

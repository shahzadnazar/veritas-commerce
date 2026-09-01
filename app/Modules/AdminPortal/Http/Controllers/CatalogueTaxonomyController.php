<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Catalog\Actions\ResolveBrand;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Enums\AttributeType;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The taxonomy every seller lists against: categories, attributes, brands.
 *
 * Changing it changes what every product in the marketplace can say about
 * itself, so it sits behind its own permissions and is audited by the
 * actions it calls.
 */
final class CatalogueTaxonomyController
{
    public function __construct(
        private readonly SaveCategory $saveCategory,
        private readonly ResolveBrand $brands,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize($request, AdminPermission::CatalogueView);

        return Inertia::render('Catalogue/Taxonomy', [
            'categories' => Category::query()->orderBy('path')->get()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'publicId' => $category->public_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'depth' => $category->depth,
                    'parentId' => $category->parent_id,
                    'isVisible' => $category->is_visible,
                    'attributeCount' => $category->attributes()->count(),
                ])
                ->all(),
            'attributes' => Attribute::query()->orderBy('name')->withCount('options')->get()
                ->map(fn (Attribute $attribute): array => [
                    'id' => $attribute->id,
                    'code' => $attribute->code,
                    'name' => $attribute->name,
                    'type' => $attribute->data_type->value,
                    'typeLabel' => $attribute->data_type->label(),
                    'unit' => $attribute->unit,
                    'isFilterable' => $attribute->is_filterable,
                    'isVariantDefining' => $attribute->is_variant_defining,
                    'optionCount' => $attribute->options_count ?? 0,
                ])
                ->all(),
            'brands' => Brand::query()->orderBy('name')->get()
                ->map(fn (Brand $brand): array => [
                    'publicId' => $brand->public_id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'isApproved' => $brand->isApproved(),
                    'proposedBySellerId' => $brand->proposed_by_seller_account_id,
                ])
                ->all(),
            'attributeTypes' => array_map(
                static fn (AttributeType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'canDefineVariants' => $type->canDefineVariants(),
                ],
                AttributeType::cases(),
            ),
            'can' => [
                'categories' => $this->admin($request)->role->can(AdminPermission::CatalogueCategoryManage),
                'attributes' => $this->admin($request)->role->can(AdminPermission::CatalogueAttributeManage),
                'brands' => $this->admin($request)->role->can(AdminPermission::CatalogueBrandManage),
            ],
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize($request, AdminPermission::CatalogueCategoryManage);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'min:2', 'max:120', 'unique:categories,slug'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_visible' => ['boolean'],
        ]);

        try {
            ($this->saveCategory)(null, $validated, 'admin', $this->admin($request)->id);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['parent_id' => $exception->getMessage()]);
        }

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::CatalogueCategoryManage);

        $category = Category::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'min:2', 'max:120', Rule::unique('categories', 'slug')->ignore($category->id)],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_visible' => ['boolean'],
        ]);

        try {
            ($this->saveCategory)($category, $validated, 'admin', $this->admin($request)->id);
        } catch (RuntimeException $exception) {
            // A refused move — a cycle — is the seller-facing message, not
            // a 500.
            throw ValidationException::withMessages(['parent_id' => $exception->getMessage()]);
        }

        return back()->with('success', 'Category updated.');
    }

    public function storeAttribute(Request $request): RedirectResponse
    {
        $this->authorize($request, AdminPermission::CatalogueAttributeManage);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:attributes,code'],
            'name' => ['required', 'string', 'max:120'],
            'data_type' => ['required', Rule::enum(AttributeType::class)],
            'unit' => ['nullable', 'string', 'max:24'],
            'is_filterable' => ['boolean'],
            'is_searchable' => ['boolean'],
            'is_variant_defining' => ['boolean'],
        ]);

        $type = AttributeType::from($validated['data_type']);

        if (($validated['is_variant_defining'] ?? false) && ! $type->canDefineVariants()) {
            throw ValidationException::withMessages([
                'is_variant_defining' => "A {$type->label()} attribute cannot distinguish one variant from another.",
            ]);
        }

        Attribute::query()->create($validated);

        return back()->with('success', 'Attribute created.');
    }

    public function attachAttribute(Request $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::CatalogueCategoryManage);

        $category = Category::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'attribute_id' => ['required', 'integer', 'exists:attributes,id'],
            'is_required' => ['boolean'],
            'is_variant_defining' => ['boolean'],
        ]);

        $category->attributes()->syncWithoutDetaching([
            $validated['attribute_id'] => [
                'is_required' => $validated['is_required'] ?? false,
                'is_variant_defining' => $validated['is_variant_defining'] ?? false,
            ],
        ]);

        return back()->with('success', 'Attribute assigned to the category.');
    }

    public function approveBrand(Request $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::CatalogueBrandManage);

        $brand = Brand::query()->where('public_id', $publicId)->firstOrFail();

        $this->brands->approve($brand, $this->admin($request)->id);

        return back()->with('success', "{$brand->name} is now a marketplace brand.");
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->user('admin');

        abort_if(! $admin instanceof AdminUser, 403);

        return $admin;
    }

    private function authorize(Request $request, AdminPermission $permission): void
    {
        abort_unless($this->admin($request)->role->can($permission), 403);
    }
}

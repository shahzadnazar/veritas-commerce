<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\AdminPortal\Http\Requests\DecisionRequest;
use App\Modules\AdminPortal\Queries\ProductModerationQueue;
use App\Modules\Catalog\Actions\ApproveProduct;
use App\Modules\Catalog\Actions\DecideProduct;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Catalog\Models\ProductProposalEvent;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catalogue moderation, in the custom admin area.
 *
 * Every route declares the permission it needs and the controller checks
 * it again, so neither alone is the only thing between a role and a
 * decision that changes what a marketplace sells.
 */
final class CatalogueProductController
{
    public function __construct(
        private readonly ProductModerationQueue $queue,
        private readonly ApproveProduct $approve,
        private readonly DecideProduct $decide,
        private readonly ObjectStore $objects,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize($request, AdminPermission::CatalogueView);

        $products = ($this->queue)($request);

        return Inertia::render('Catalogue/Products', [
            'products' => [
                'data' => array_map(
                    static fn (Product $product): array => [
                        'publicId' => $product->public_id,
                        'title' => $product->title,
                        'status' => $product->status->value,
                        'brand' => $product->brand?->name,
                        'category' => $product->category?->name,
                        'proposedBy' => $product->proposedBy?->legal_name,
                        'submittedAt' => $product->submitted_at?->toDayDateTimeString(),
                    ],
                    $products->items(),
                ),
                'currentPage' => $products->currentPage(),
                'lastPage' => $products->lastPage(),
                'total' => $products->total(),
            ],
            'filters' => [
                'status' => $request->string('status')->toString(),
                'search' => $request->string('search')->toString(),
                'category' => $request->string('category')->toString(),
            ],
            'statuses' => array_map(
                static fn (ProductStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                ProductStatus::cases(),
            ),
            'categories' => Category::query()->orderBy('path')->get()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => str_repeat('— ', $category->depth).$category->name,
                ])
                ->all(),
            'can' => $this->capabilities($request),
        ]);
    }

    public function show(Request $request, string $publicId): Response
    {
        $this->authorize($request, AdminPermission::CatalogueView);

        $product = Product::query()
            ->with([
                'brand', 'category', 'proposedBy', 'media', 'variants',
                'attributeValues.attribute', 'attributeValues.option',
                'proposalEvents',
            ])
            ->where('public_id', $publicId)
            ->firstOrFail();

        return Inertia::render('Catalogue/ProductReview', [
            'product' => [
                'publicId' => $product->public_id,
                'title' => $product->title,
                'slug' => $product->slug,
                'description' => $product->description,
                'status' => $product->status->value,
                'moderationReason' => $product->moderation_reason,
                'brand' => $product->brand?->name,
                'category' => $product->category?->name,
                'identifiers' => $product->identifiers(),
                'proposedBy' => $product->proposedBy?->legal_name,
                'submittedAt' => $product->submitted_at?->toDayDateTimeString(),
            ],
            'specifications' => $product->attributeValues
                ->filter(fn (ProductAttributeValue $value): bool => $value->product_variant_id === null)
                ->map(fn (ProductAttributeValue $value): array => [
                    'name' => $value->attribute->name ?? '',
                    'value' => $value->display(),
                ])
                ->values()
                ->all(),
            'variants' => $product->variants
                ->map(fn (ProductVariant $variant): array => [
                    'name' => $variant->name,
                    'options' => $variant->option_values ?? [],
                ])
                ->all(),
            'media' => $product->media
                ->map(fn (ProductMedia $media): array => [
                    'url' => $this->objects->url($this->objects->fromReference($media->reference(), Visibility::Public)),
                    'alt' => $media->alt_text,
                    'state' => $media->processing_state,
                ])
                ->all(),
            'history' => $product->proposalEvents->sortBy('id')->values()
                ->map(fn (ProductProposalEvent $event): array => [
                    'fromStatus' => $event->from_status,
                    'toStatus' => $event->to_status,
                    'actorType' => $event->actor_type,
                    'reason' => $event->reason,
                    'at' => $event->created_at->toDayDateTimeString(),
                ])
                ->all(),
            'can' => $this->capabilities($request),
        ]);
    }

    public function approve(Request $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::CatalogueProductApprove);

        ($this->approve)(
            $this->find($publicId),
            $this->admin($request)->id,
            publish: $request->boolean('publish'),
        );

        return back()->with('success', 'Product accepted into the catalogue.');
    }

    public function reject(DecisionRequest $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::CatalogueProductReject);

        $this->decide->reject($this->find($publicId), $this->admin($request)->id, $request->reason());

        return back()->with('success', 'Product rejected.');
    }

    public function requestChanges(DecisionRequest $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::CatalogueProductReview);

        $this->decide->requestChanges($this->find($publicId), $this->admin($request)->id, $request->reason());

        return back()->with('success', 'Sent back to the seller with your note.');
    }

    public function suspend(DecisionRequest $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::CatalogueProductSuspend);

        $this->decide->suspend($this->find($publicId), $this->admin($request)->id, $request->reason());

        return back()->with('success', 'Product suspended. Listings against it are no longer visible.');
    }

    /** @return array<string, bool> */
    private function capabilities(Request $request): array
    {
        $role = $this->admin($request)->role;

        return [
            'review' => $role->can(AdminPermission::CatalogueProductReview),
            'approve' => $role->can(AdminPermission::CatalogueProductApprove),
            'reject' => $role->can(AdminPermission::CatalogueProductReject),
            'suspend' => $role->can(AdminPermission::CatalogueProductSuspend),
        ];
    }

    private function find(string $publicId): Product
    {
        return Product::query()->where('public_id', $publicId)->firstOrFail();
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

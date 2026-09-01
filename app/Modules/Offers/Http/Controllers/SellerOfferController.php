<?php

declare(strict_types=1);

namespace App\Modules\Offers\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Offers\Actions\SaveOffer;
use App\Modules\Offers\Actions\TransitionOffer;
use App\Modules\Offers\Enums\OfferCondition;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerPermission;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * A seller's own listings.
 *
 * Every lookup is scoped to the acting seller, so an offer id belonging to
 * another store does not resolve — there is no id in any of these requests
 * that decides whose goods are being priced.
 */
final class SellerOfferController
{
    public function __construct(
        private readonly SaveOffer $saveOffer,
        private readonly TransitionOffer $transition,
    ) {}

    public function index(): Response
    {
        $offers = Offer::query()
            ->with(['product.brand', 'productVariant'])
            ->where('seller_account_id', $this->sellerId())
            ->orderByDesc('id')
            ->paginate(25);

        return Inertia::render('Catalogue/Offers', [
            'offers' => [
                'data' => array_map(
                    static fn (Offer $offer): array => [
                        'publicId' => $offer->public_id,
                        'sku' => $offer->seller_sku,
                        'productTitle' => $offer->product->title ?? 'Unknown product',
                        'productSlug' => $offer->product->slug ?? null,
                        'variantName' => $offer->productVariant->name ?? null,
                        'price' => Money::of($offer->price_minor, $offer->currency)->format(),
                        'condition' => $offer->condition->value,
                        'conditionLabel' => $offer->condition->label(),
                        'status' => $offer->status->value,
                        'canPublish' => $offer->status->allowedTransitions()
                            && in_array(OfferStatus::Published, $offer->status->allowedTransitions(), true),
                    ],
                    $offers->items(),
                ),
                'currentPage' => $offers->currentPage(),
                'lastPage' => $offers->lastPage(),
                'total' => $offers->total(),
            ],
            'can' => ['manage' => CurrentSeller::can(SellerPermission::CatalogManage)],
        ]);
    }

    public function create(Request $request, string $productPublicId): Response
    {
        $product = Product::query()
            ->with(['brand', 'variants'])
            ->where('public_id', $productPublicId)
            ->firstOrFail();

        abort_unless($product->status->acceptsOffers(), 404);

        return Inertia::render('Catalogue/OfferForm', [
            'product' => [
                'publicId' => $product->public_id,
                'title' => $product->title,
                'brand' => $product->brand?->name,
            ],
            'variants' => $product->variants
                ->map(fn (ProductVariant $variant): array => [
                    'publicId' => $variant->public_id,
                    'name' => $variant->name,
                ])
                ->all(),
            'conditions' => array_map(
                static fn (OfferCondition $condition): array => [
                    'value' => $condition->value,
                    'label' => $condition->label(),
                ],
                OfferCondition::cases(),
            ),
            'offer' => null,
            'currency' => config('veritas.money.default_currency'),
        ]);
    }

    public function store(Request $request, string $productPublicId): RedirectResponse
    {
        abort_unless(CurrentSeller::can(SellerPermission::CatalogManage), 403);

        $product = Product::query()->where('public_id', $productPublicId)->firstOrFail();
        $validated = $this->validated($request);

        $variant = $validated['variant_public_id'] === null ? null : ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('public_id', $validated['variant_public_id'])
            ->firstOrFail();

        try {
            ($this->saveOffer)($this->sellerId(), $product, $validated, $variant);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['seller_sku' => $exception->getMessage()]);
        }

        return redirect()->route('seller.offers')->with('success', 'Listing saved as a draft.');
    }

    public function update(Request $request, string $publicId): RedirectResponse
    {
        abort_unless(CurrentSeller::can(SellerPermission::CatalogManage), 403);

        $offer = $this->ownOffer($publicId);
        $product = $offer->product;
        abort_if($product === null, 404);

        try {
            ($this->saveOffer)(
                $this->sellerId(),
                $product,
                $this->validated($request),
                $offer->productVariant,
                $offer,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['seller_sku' => $exception->getMessage()]);
        }

        return back()->with('success', 'Listing updated.');
    }

    public function transition(Request $request, string $publicId): RedirectResponse
    {
        abort_unless(CurrentSeller::can(SellerPermission::CatalogManage), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(OfferStatus::class)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user('web');
        abort_if($user === null, 403);

        try {
            ($this->transition)(
                $this->ownOffer($publicId),
                OfferStatus::from($validated['status']),
                'seller',
                $user->getAuthIdentifier(),
                $validated['reason'] ?? null,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        return back()->with('success', 'Listing updated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'seller_sku' => ['required', 'string', 'max:80'],
            'condition' => ['required', Rule::enum(OfferCondition::class)],
            // Minor units all the way in: a decimal in a form field is
            // converted at the boundary, never carried as a float.
            'price_minor' => ['required', 'integer', 'min:1'],
            'compare_at_price_minor' => ['nullable', 'integer', 'min:1', 'gte:price_minor'],
            'handling_days' => ['required', 'integer', 'min:0', 'max:30'],
            'seller_notes' => ['nullable', 'string', 'max:1000'],
            'variant_public_id' => ['nullable', 'string'],
        ]);

        $validated['variant_public_id'] ??= null;

        return $validated;
    }

    private function ownOffer(string $publicId): Offer
    {
        return Offer::query()
            ->with(['product', 'productVariant'])
            ->where('seller_account_id', $this->sellerId())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function sellerId(): int
    {
        $sellerId = CurrentSeller::id();
        abort_if($sellerId === null, 404);

        return $sellerId;
    }
}

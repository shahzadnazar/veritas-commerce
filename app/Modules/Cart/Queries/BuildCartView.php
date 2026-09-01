<?php

declare(strict_types=1);

namespace App\Modules\Cart\Queries;

use App\Modules\Cart\Data\CartIssue;
use App\Modules\Cart\Data\CartLine;
use App\Modules\Cart\Data\CartSellerGroup;
use App\Modules\Cart\Data\CartView;
use App\Modules\Cart\Enums\CartIssueCode;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Queries\OfferEligibility;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads a cart, and re-checks every assumption in it.
 *
 * §8's requirement, and the reason the cart holds no financial record: the
 * conditions a customer added under expire. Prices move, sellers get
 * suspended, products get pulled and stock runs out — so every read
 * re-prices from the live offer and re-tests eligibility rather than
 * trusting what was true yesterday.
 *
 * Two queries regardless of how many lines there are: one for the items
 * with their relations, one for availability. §65 rules out an N+1 across
 * offers, products, stores and inventory, which a naive per-line
 * eligibility check would be four times over.
 */
final class BuildCartView
{
    public function __construct(
        private readonly OfferEligibility $eligibility,
        private readonly ObjectStore $objects,
    ) {}

    public function __invoke(?Cart $cart): CartView
    {
        $currency = (string) config('veritas.money.default_currency');

        if ($cart === null) {
            return CartView::empty($currency);
        }

        /** @var Collection<int, CartItem> $items */
        $items = $cart->items()
            ->with([
                'offer.product.brand', 'offer.product.media',
                'offer.productVariant', 'offer.store', 'offer.sellerAccount',
            ])
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return CartView::empty($cart->currency ?? $currency);
        }

        // One query for availability across every offer in the cart, and
        // one set of eligible ids — not a lookup per line.
        $offerIds = $items->pluck('offer_id')->all();
        $availability = $this->availabilityFor($offerIds);
        $eligible = $this->eligibleOfferIds($offerIds);

        $lines = [];

        foreach ($items as $item) {
            $line = $this->line($item, $availability, $eligible, $cart->currency ?? $currency);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $this->group($cart, $lines, $cart->currency ?? $currency);
    }

    /**
     * One line, revalidated.
     *
     * Returns null only when the offer row is gone entirely — a line
     * pointing at nothing cannot be described, let alone priced.
     *
     * @param  array<int, int>  $availability
     * @param  array<int, bool>  $eligible
     */
    private function line(CartItem $item, array $availability, array $eligible, string $currency): ?CartLine
    {
        $offer = $item->offer;

        if ($offer === null) {
            return null;
        }

        $product = $offer->product;
        $store = $offer->store;

        if ($product === null || $store === null) {
            return null;
        }

        $issues = [];
        $available = $availability[$offer->id] ?? 0;
        $isEligible = $eligible[$offer->id] ?? false;

        if (! $isEligible) {
            // Which of the five eligibility conditions failed, because
            // "unavailable" tells the customer nothing they can act on.
            $issues[] = new CartIssue(
                code: $this->ineligibilityCode($offer),
                lineIdentity: $item->line_identity,
            );
        }

        if ($offer->currency !== $currency) {
            $issues[] = new CartIssue(
                code: CartIssueCode::CurrencyMismatch,
                lineIdentity: $item->line_identity,
            );
        }

        /*
         * The price change §39 locks down.
         *
         * `unit_price_at_add_minor` is display history, never authority:
         * the line is priced from the live offer, and the difference is
         * reported so the customer sees it rather than discovering it at
         * checkout.
         */
        if ($item->unit_price_at_add_minor !== $offer->price_minor) {
            $issues[] = new CartIssue(
                code: CartIssueCode::PriceChanged,
                lineIdentity: $item->line_identity,
                previousMinor: $item->unit_price_at_add_minor,
                currentMinor: $offer->price_minor,
            );
        }

        if ($isEligible && $available <= 0) {
            $issues[] = new CartIssue(
                code: CartIssueCode::OutOfStock,
                lineIdentity: $item->line_identity,
                available: 0,
            );
        } elseif ($isEligible && $available < $item->quantity) {
            $issues[] = new CartIssue(
                code: CartIssueCode::QuantityReduced,
                lineIdentity: $item->line_identity,
                available: $available,
            );
        }

        if ($item->product_variant_id !== null && $offer->product_variant_id !== $item->product_variant_id) {
            $issues[] = new CartIssue(
                code: CartIssueCode::VariantUnavailable,
                lineIdentity: $item->line_identity,
            );
        }

        $unitPrice = Money::of($offer->price_minor, $offer->currency);

        return new CartLine(
            lineIdentity: $item->line_identity,
            offerId: $offer->id,
            offerPublicId: $offer->public_id,
            productId: $product->id,
            productTitle: $product->title,
            productSlug: $product->slug,
            brandName: $product->brand->name ?? null,
            variantName: $offer->productVariant->name ?? null,
            variantId: $offer->product_variant_id,
            sellerAccountId: $offer->seller_account_id,
            storeName: $store->name,
            storeSlug: $store->slug,
            sellerSku: $offer->seller_sku,
            quantity: $item->quantity,
            unitPrice: $unitPrice,
            lineTotal: $unitPrice->times($item->quantity),
            available: $available,
            isBuyable: $isEligible && $available >= $item->quantity,
            issues: $issues,
            imageUrl: $this->imageUrl($product),
        );
    }

    /**
     * Which eligibility condition a line failed.
     *
     * The five conditions are OfferEligibility's; naming which one broke
     * is presentation, and belongs here rather than complicating the
     * shared rule.
     */
    private function ineligibilityCode(Offer $offer): CartIssueCode
    {
        $seller = $offer->sellerAccount;
        $store = $offer->store;
        $product = $offer->product;

        if ($seller === null || ! $seller->status->canSell()) {
            return CartIssueCode::SellerUnavailable;
        }

        if ($store === null || ! $store->is_open) {
            return CartIssueCode::SellerUnavailable;
        }

        if ($product === null || ! $product->isPubliclyVisible()) {
            return CartIssueCode::ProductUnavailable;
        }

        return CartIssueCode::OfferUnavailable;
    }

    /**
     * Available units per offer, in one query.
     *
     * @param  array<int, int>  $offerIds
     * @return array<int, int>
     */
    private function availabilityFor(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        /** @var array<int, int> $rows */
        $rows = DB::table('inventory_balances')
            ->whereIn('offer_id', $offerIds)
            ->selectRaw('offer_id, sum(available) as available')
            ->groupBy('offer_id')
            ->pluck('available', 'offer_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return $rows;
    }

    /**
     * Which of these offers a customer may buy, by the shared rule.
     *
     * @param  array<int, int>  $offerIds
     * @return array<int, bool>
     */
    private function eligibleOfferIds(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        $ids = $this->eligibility->query()
            ->whereIn('offers.id', $offerIds)
            ->pluck('offers.id')
            ->all();

        $eligible = [];

        foreach ($ids as $id) {
            $eligible[(int) $id] = true;
        }

        return $eligible;
    }

    private function imageUrl(Product $product): ?string
    {
        $media = $product->primaryImage();

        if ($media === null || ! $media->isReady()) {
            return null;
        }

        return $this->objects->url(
            $this->objects->fromReference($media->reference(), Visibility::Public),
        );
    }

    /**
     * @param  array<int, CartLine>  $lines
     */
    private function group(Cart $cart, array $lines, string $currency): CartView
    {
        /** @var array<int, array<int, CartLine>> $bySeller */
        $bySeller = [];

        foreach ($lines as $line) {
            $bySeller[$line->sellerAccountId][] = $line;
        }

        // Sorted by seller id: the same cart always groups the same way,
        // and the order here is the order the seller orders are numbered
        // in at checkout.
        ksort($bySeller);

        $groups = [];
        $subtotal = Money::zero($currency);
        $quantity = 0;

        foreach ($bySeller as $sellerId => $sellerLines) {
            $groupSubtotal = Money::zero($currency);

            foreach ($sellerLines as $line) {
                $groupSubtotal = $groupSubtotal->plus($line->lineTotal);
                $quantity += $line->quantity;
            }

            $first = $sellerLines[0];

            $groups[] = new CartSellerGroup(
                sellerAccountId: $sellerId,
                storeName: $first->storeName,
                storeSlug: $first->storeSlug,
                lines: $sellerLines,
                subtotal: $groupSubtotal,
            );

            $subtotal = $subtotal->plus($groupSubtotal);
        }

        return new CartView(
            cartPublicId: $cart->public_id,
            groups: $groups,
            issues: [],
            subtotal: $subtotal,
            itemCount: count($lines),
            quantityCount: $quantity,
            currency: $currency,
        );
    }
}

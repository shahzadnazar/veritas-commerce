<?php

declare(strict_types=1);

namespace App\Modules\Offers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Offers\Enums\OfferCondition;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Events\OfferCreated;
use App\Modules\Offers\Events\OfferUpdated;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Concerns\CurrentSeller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * A seller's commercial listing against a canonical product.
 *
 * The seller is taken from the acting membership and the store from the
 * seller — never from the request. An offer is the one place where a
 * tampered id would let one seller price another's goods, so there is
 * nothing in the payload to tamper with.
 *
 * What a seller controls here is entirely commercial: price, condition,
 * SKU, dispatch. Nothing in this action can reach the canonical product's
 * identity, because that identity is shared with every other seller
 * listing against it.
 */
final class SaveOffer
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(
        int $sellerAccountId,
        Product $product,
        array $attributes,
        ?ProductVariant $variant = null,
        ?Offer $offer = null,
    ): Offer {
        if (! $product->status->acceptsOffers()) {
            throw new RuntimeException(
                "“{$product->title}” is {$product->status->label()} and is not accepting offers."
            );
        }

        if ($variant !== null && $variant->product_id !== $product->id) {
            // The database enforces this too. Saying it here means the
            // seller gets a sentence rather than a constraint violation.
            throw new RuntimeException('That variant belongs to a different product.');
        }

        return CurrentSeller::actingAs($sellerAccountId, function () use ($sellerAccountId, $product, $attributes, $variant, $offer): Offer {
            return DB::transaction(function () use ($sellerAccountId, $product, $attributes, $variant, $offer): Offer {
                $store = $this->storeIdFor($sellerAccountId);
                $isNew = $offer === null;
                $before = $offer?->only(['price_minor', 'compare_at_price_minor', 'condition', 'seller_sku', 'status']);

                $offer ??= new Offer;

                // Ownership is assigned, never accepted: whatever the
                // request said about seller or store is overwritten here.
                $offer->seller_account_id = $sellerAccountId;
                $offer->store_id = $store;
                $offer->product_id = $product->id;
                $offer->product_variant_id = $variant?->id;

                $offer->seller_sku = trim((string) ($attributes['seller_sku'] ?? $offer->seller_sku));
                $offer->condition = $attributes['condition'] instanceof OfferCondition
                    ? $attributes['condition']
                    : OfferCondition::from((string) $attributes['condition']);

                $offer->price_minor = (int) $attributes['price_minor'];
                // isset() is already false for a null, so the extra check
                // said nothing.
                $offer->compare_at_price_minor = isset($attributes['compare_at_price_minor'])
                    ? (int) $attributes['compare_at_price_minor']
                    : null;
                $offer->currency = (string) ($attributes['currency'] ?? config('veritas.money.default_currency'));
                $offer->handling_days = (int) ($attributes['handling_days'] ?? 2);
                $offer->seller_notes = $attributes['seller_notes'] ?? null;

                if ($isNew) {
                    $offer->status = OfferStatus::Draft;
                }

                try {
                    $offer->save();
                } catch (UniqueConstraintViolationException $violation) {
                    throw new RuntimeException($this->explain($violation), previous: $violation);
                }

                ($this->audit)(
                    action: $isNew ? 'catalogue.offer.created' : 'catalogue.offer.updated',
                    actorType: 'seller',
                    actorId: $sellerAccountId,
                    subjectType: Offer::class,
                    subjectId: $offer->id,
                    changes: [
                        'before' => $before,
                        'after' => $offer->only(['price_minor', 'compare_at_price_minor', 'condition', 'seller_sku', 'status']),
                    ],
                );

                $offerId = $offer->id;

                DB::afterCommit(function () use ($offerId, $sellerAccountId, $isNew): void {
                    Event::dispatch($isNew
                        ? new OfferCreated($offerId, $sellerAccountId)
                        : new OfferUpdated($offerId, $sellerAccountId));
                });

                return $offer;
            });
        });
    }

    /** A constraint violation, in words the seller can act on. */
    private function explain(UniqueConstraintViolationException $violation): string
    {
        // Matched on index names, not column names: the exception carries
        // the whole failing statement, so every column appears in it
        // whichever constraint actually fired.
        $message = $violation->getMessage();

        if (str_contains($message, 'offers_seller_variant_unique') || str_contains($message, 'offers_seller_product_unique')) {
            return 'You already have a listing for this product. Edit that one instead of creating a second.';
        }

        if (str_contains($message, 'seller_sku_unique')) {
            return 'You already use that SKU on another listing.';
        }

        return 'That listing conflicts with one you already have.';
    }

    private function storeIdFor(int $sellerAccountId): int
    {
        $storeId = DB::table('stores')->where('seller_account_id', $sellerAccountId)->value('id');

        if ($storeId === null) {
            throw new RuntimeException('Set your store up before listing anything.');
        }

        return (int) $storeId;
    }
}

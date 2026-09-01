<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Enums\CartIssueCode;
use App\Modules\Cart\Events\CartLineAdded;
use App\Modules\Cart\Exceptions\CartOperationRefused;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Support\LineIdentity;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Queries\OfferEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Putting a seller's offer in a cart.
 *
 * Everything is checked here, on the server, from the offer id alone. §6 is
 * blunt about why: the Add button's disabled state is a courtesy to the
 * customer, not a control — a crafted request carrying a suspended
 * seller's offer id has to be refused by the same code that would have
 * refused it politely.
 *
 * What it does NOT do is reserve stock. §7 draws that line: a cart is
 * intent, and reserving on add would let abandoned carts lock the
 * marketplace's inventory indefinitely. Availability is checked so the
 * customer is not misled, and the hold is taken at checkout.
 */
final class AddOfferToCart
{
    /** Public so the HTTP layer validates against the same ceiling. */
    public const MAX_LINE_QUANTITY = 99;

    public function __construct(private readonly OfferEligibility $eligibility) {}

    /**
     * @param  array<string, scalar|null>  $customisation  reserved for future product types
     *
     * @throws CartOperationRefused
     */
    public function __invoke(
        Cart $cart,
        string $offerPublicId,
        int $quantity = 1,
        array $customisation = [],
    ): CartItem {
        if ($quantity < 1 || $quantity > self::MAX_LINE_QUANTITY) {
            throw new CartOperationRefused(
                CartIssueCode::QuantityReduced,
                'Choose a quantity between 1 and '.self::MAX_LINE_QUANTITY.'.',
            );
        }

        /*
         * Resolved through the eligibility rule, not by id.
         *
         * An offer that fails any of the five conditions simply does not
         * come back, so a manipulated id reaches the same refusal as a
         * suspended seller's — there is no separate check to forget.
         */
        $offer = $this->eligibility->query()
            ->with(['product', 'productVariant'])
            ->where('offers.public_id', $offerPublicId)
            ->select('offers.*')
            ->first();

        if ($offer === null) {
            throw CartOperationRefused::offerUnavailable();
        }

        $variantId = $offer->product_variant_id;

        if ($variantId !== null && $offer->productVariant?->product_id !== $offer->product_id) {
            // The database trigger prevents this being written at all; the
            // check stays because a cart should not be the thing that
            // discovers a broken invariant.
            throw CartOperationRefused::variantMismatch();
        }

        $identity = LineIdentity::for($offer->id, $variantId, $customisation);

        return DB::transaction(function () use ($cart, $offer, $identity, $variantId, $quantity): CartItem {
            /** @var CartItem|null $existing */
            $existing = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('line_identity', $identity)
                ->lockForUpdate()
                ->first();

            // Combined, not appended: a double-click is one intention.
            $wanted = ($existing->quantity ?? 0) + $quantity;

            $available = $this->availableFor($offer);

            if ($available < $wanted) {
                throw CartOperationRefused::insufficientStock($available);
            }

            if ($existing !== null) {
                $existing->forceFill([
                    'quantity' => $wanted,
                    // Re-read, so the line reflects what the customer is
                    // being shown now rather than what it cost last week.
                    'unit_price_at_add_minor' => $offer->price_minor,
                ])->save();

                $cart->touchActivity();
                $this->announce($cart, $offer, $existing, $quantity, $wanted);

                return $existing;
            }

            $item = CartItem::query()->create([
                'cart_id' => $cart->id,
                'offer_id' => $offer->id,
                'product_variant_id' => $variantId,
                'line_identity' => $identity,
                'quantity' => $quantity,
                'unit_price_at_add_minor' => $offer->price_minor,
            ]);

            $cart->touchActivity();
            $this->announce($cart, $offer, $item, $quantity, $quantity);

            return $item;
        });
    }

    /**
     * The behavioural signal, dispatched after the row is committed.
     *
     * The action itself records no analytics — it announces what
     * happened, and the Events module decides what is worth keeping. A
     * cart add from a console command therefore works exactly as well as
     * one from a request.
     */
    private function announce(Cart $cart, Offer $offer, CartItem $item, int $added, int $lineQuantity): void
    {
        $event = new CartLineAdded(
            cartId: $cart->id,
            lineIdentity: (string) $item->line_identity,
            offerId: $offer->id,
            productId: $offer->product_id,
            sellerAccountId: $offer->seller_account_id,
            quantity: $added,
            unitPriceMinor: $offer->price_minor,
            lineQuantity: $lineQuantity,
        );

        DB::afterCommit(static fn () => Event::dispatch($event));
    }

    /** What a customer could actually take right now. */
    private function availableFor(Offer $offer): int
    {
        return (int) DB::table('inventory_balances')
            ->where('offer_id', $offer->id)
            ->sum('available');
    }
}

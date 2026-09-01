<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Enums\CartIssueCode;
use App\Modules\Cart\Events\CartLineRemoved;
use App\Modules\Cart\Exceptions\CartOperationRefused;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Changing or removing a line.
 *
 * Scoped to the cart it was given, which the caller resolved from the
 * session — so a line identity from another customer's cart simply does
 * not match anything, and there is no id to manipulate into a different
 * result.
 */
final class UpdateCartLine
{
    private const MAX_LINE_QUANTITY = 99;

    /** @throws CartOperationRefused */
    public function setQuantity(Cart $cart, string $lineIdentity, int $quantity): ?CartItem
    {
        if ($quantity < 0 || $quantity > self::MAX_LINE_QUANTITY) {
            throw new CartOperationRefused(
                CartIssueCode::QuantityReduced,
                'Choose a quantity between 0 and '.self::MAX_LINE_QUANTITY.'.',
            );
        }

        return DB::transaction(function () use ($cart, $lineIdentity, $quantity): ?CartItem {
            /** @var CartItem|null $item */
            $item = CartItem::query()
                ->with('offer')
                ->where('cart_id', $cart->id)
                ->where('line_identity', $lineIdentity)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                throw new CartOperationRefused(
                    CartIssueCode::OfferUnavailable,
                    'That line is no longer in your cart.',
                );
            }

            // Zero is how a quantity control removes something, which is
            // what a customer stepping down from one expects.
            if ($quantity === 0) {
                $this->announceRemoval($cart, $item);
                $item->delete();
                $cart->touchActivity();

                return null;
            }

            $available = (int) DB::table('inventory_balances')
                ->where('offer_id', $item->offer_id)
                ->sum('available');

            if ($available < $quantity) {
                throw CartOperationRefused::insufficientStock($available);
            }

            $item->forceFill(['quantity' => $quantity])->save();
            $cart->touchActivity();

            return $item;
        });
    }

    public function remove(Cart $cart, string $lineIdentity): bool
    {
        return DB::transaction(function () use ($cart, $lineIdentity): bool {
            /** @var CartItem|null $item */
            $item = CartItem::query()
                ->with('offer')
                ->where('cart_id', $cart->id)
                ->where('line_identity', $lineIdentity)
                ->first();

            if ($item === null) {
                return false;
            }

            $this->announceRemoval($cart, $item);
            $item->delete();
            $cart->touchActivity();

            return true;
        });
    }

    public function clear(Cart $cart): int
    {
        return DB::transaction(function () use ($cart): int {
            /** @var Collection<int, CartItem> $items */
            // Eager loaded: emptying a twelve-line cart must not be
            // twelve queries for twelve prices.
            $items = CartItem::query()->with('offer')->where('cart_id', $cart->id)->get();

            foreach ($items as $item) {
                $this->announceRemoval($cart, $item);
            }

            CartItem::query()->where('cart_id', $cart->id)->delete();
            $cart->touchActivity();

            return $items->count();
        });
    }

    /**
     * Emptying a cart is a removal per line, not one bulk event.
     *
     * A single "cart cleared" event would tell a ranking model that
     * something was abandoned without telling it what — and what was
     * abandoned is the entire signal.
     */
    private function announceRemoval(Cart $cart, CartItem $item): void
    {
        $offer = $item->offer;

        $event = new CartLineRemoved(
            cartId: $cart->id,
            lineIdentity: (string) $item->line_identity,
            offerId: (int) $item->offer_id,
            productId: $offer?->product_id,
            sellerAccountId: $offer?->seller_account_id,
            quantity: (int) $item->quantity,
            unitPriceMinor: $offer->price_minor ?? (int) $item->unit_price_at_add_minor,
        );

        DB::afterCommit(static fn () => Event::dispatch($event));
    }
}

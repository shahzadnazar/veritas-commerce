<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Checkout\Data\CheckoutQuote;
use App\Modules\Checkout\Data\ShippingAddress;
use App\Modules\Checkout\Enums\CheckoutStatus;
use App\Modules\Checkout\Exceptions\CheckoutRefused;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Accepting a checkout: quote it, hold the stock, record the attempt.
 *
 * §15's guarantee lives here. A customer double-clicking the pay button,
 * refreshing after a timeout or retrying a failed request presents the
 * same idempotency key, and gets the same attempt back — the same
 * reservations, and later the same order. Never a second hold on the
 * seller's stock.
 *
 * Three things make that true rather than likely:
 *
 *  1. The key is UNIQUE in the database. A read-then-write would let two
 *     simultaneous requests both find nothing and both proceed; here the
 *     second insert loses, is caught, and re-reads the winner's row.
 *  2. An attempt that has already been decided returns immediately,
 *     before any quote is computed or any stock is touched.
 *  3. Quote and reservation share one transaction. There is no state where
 *     an attempt exists without its holds, so a retry can never find a
 *     half-finished checkout to complete differently.
 *
 * The reservation reference is the attempt's own public id, so every hold
 * this checkout took can be released or committed in one query, and an
 * orphaned hold can always be traced back to the attempt that took it.
 *
 * What this does NOT do is create the order or call a payment provider.
 * Both happen after this transaction has committed — a network call inside
 * a transaction holding inventory locks is how a marketplace discovers
 * what a lock timeout looks like under load.
 */
final class StartCheckout
{
    public function __construct(
        private readonly QuoteCheckout $quote,
        private readonly ReserveStock $reserve,
    ) {}

    /**
     * @throws CheckoutRefused
     */
    public function __invoke(
        Cart $cart,
        string $idempotencyKey,
        ShippingAddress $address,
        ?int $userId = null,
    ): CheckoutAttempt {
        $existing = $this->existingAttempt($idempotencyKey, $cart, $userId);

        if ($existing !== null) {
            return $existing;
        }

        $quote = ($this->quote)($cart);

        if ($quote->cart->itemCount === 0) {
            throw $this->refuse($idempotencyKey, $cart, $userId, $address, CheckoutRefused::cartIsEmpty());
        }

        if (! $quote->isBuyable()) {
            throw $this->refuse(
                $idempotencyKey,
                $cart,
                $userId,
                $address,
                CheckoutRefused::cartIsNotBuyable($quote->blockingIssues()),
            );
        }

        try {
            return $this->accept($idempotencyKey, $cart, $userId, $address, $quote);
        } catch (InsufficientStock) {
            /*
             * The gap §11 exists to close. The quote was buyable a
             * moment ago; between reading availability and taking the
             * lock somebody else committed to the last unit. The lock is
             * the authority, not the read, so the checkout is refused
             * rather than oversold.
             */
            throw $this->refuse($idempotencyKey, $cart, $userId, $address, CheckoutRefused::stockRanOut());
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            // Two requests raced on the same key. The loser reads the
            // winner's attempt rather than starting a second one.
            $won = $this->existingAttempt($idempotencyKey, $cart, $userId);

            if ($won === null) {
                throw $e;
            }

            return $won;
        }
    }

    /**
     * An attempt already made under this key.
     *
     * Scoped to the same cart and the same customer. A key presented for
     * someone else's checkout is refused outright: handing back the first
     * attempt would hand one customer another's order.
     */
    private function existingAttempt(string $key, Cart $cart, ?int $userId): ?CheckoutAttempt
    {
        /** @var CheckoutAttempt|null $attempt */
        $attempt = CheckoutAttempt::query()->where('idempotency_key', $key)->first();

        if ($attempt === null) {
            return null;
        }

        if ($attempt->cart_id !== $cart->id || $attempt->user_id !== $userId) {
            throw CheckoutRefused::keyBelongsToAnotherCheckout();
        }

        if ($attempt->status === CheckoutStatus::Failed) {
            // A decided refusal stays refused for this key. Retrying the
            // same key must not produce a different answer; fixing the
            // cart and starting again is a new checkout.
            throw new CheckoutRefused(
                $attempt->failure_reason ?? 'That checkout could not be completed.',
                reason: 'already_failed',
            );
        }

        return $attempt;
    }

    /**
     * Quote, hold and record, atomically.
     *
     * One transaction, so there is no window in which an attempt exists
     * without its reservations. The reference is derived from the row's
     * own public id, which is why the row is created first.
     */
    private function accept(
        string $key,
        Cart $cart,
        ?int $userId,
        ShippingAddress $address,
        CheckoutQuote $quote,
    ): CheckoutAttempt {
        return DB::transaction(function () use ($key, $cart, $userId, $address, $quote): CheckoutAttempt {
            $attempt = CheckoutAttempt::query()->create([
                'idempotency_key' => $key,
                'user_id' => $userId,
                'cart_id' => $cart->id,
                'status' => CheckoutStatus::Reserved->value,
                'currency' => $quote->currency,
                'items_total_minor' => $quote->itemsTotal->minor,
                'shipping_total_minor' => $quote->shippingTotal->minor,
                'tax_total_minor' => $quote->taxTotal->minor,
                'grand_total_minor' => $quote->grandTotal->minor,
                'shipping_address' => $address->toArray(),
                'expires_at' => now()->addMinutes((int) config('veritas.checkout.payment_window_minutes')),
            ]);

            ($this->reserve)(
                $this->quantitiesByOffer($cart),
                $attempt->reservationReference(),
                (int) config('veritas.checkout.payment_window_minutes'),
            );

            return $attempt;
        });
    }

    /**
     * What to hold, read from the cart rows rather than from the quote.
     *
     * The quote groups by seller for display; the reservation needs one
     * quantity per offer, and two lines of the same offer would otherwise
     * be held once. Summed here so that stays true if line identity ever
     * splits a single offer across lines — which is exactly what the
     * customisation seam is for.
     *
     * @return array<int, int>
     */
    private function quantitiesByOffer(Cart $cart): array
    {
        /** @var array<int, int> $rows */
        $rows = CartItem::query()
            ->where('cart_id', $cart->id)
            ->selectRaw('offer_id, sum(quantity) as quantity')
            ->groupBy('offer_id')
            ->pluck('quantity', 'offer_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return $rows;
    }

    /**
     * Records the refusal against the key, then hands the exception back
     * to be thrown.
     *
     * Written outside the failed transaction on purpose: an attempt that
     * refused has to survive for the customer to be told why, and for the
     * same key to give the same answer if it is presented again.
     */
    private function refuse(
        string $key,
        Cart $cart,
        ?int $userId,
        ShippingAddress $address,
        CheckoutRefused $refusal,
    ): CheckoutRefused {
        try {
            CheckoutAttempt::query()->create([
                'idempotency_key' => $key,
                'user_id' => $userId,
                'cart_id' => $cart->id,
                'status' => CheckoutStatus::Failed->value,
                'currency' => (string) config('veritas.money.default_currency'),
                'shipping_address' => $address->toArray(),
                'failure_reason' => $refusal->getMessage(),
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }
            // Another request already recorded a decision for this key.
        }

        return $refusal;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }
}

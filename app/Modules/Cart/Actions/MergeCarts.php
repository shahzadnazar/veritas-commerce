<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Data\CartIssue;
use App\Modules\Cart\Data\CartMergeResult;
use App\Modules\Cart\Enums\CartIssueCode;
use App\Modules\Cart\Enums\CartStatus;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Offers\Queries\OfferEligibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Folding an anonymous cart into the one the customer already had.
 *
 * §12. A customer who filled a basket signed out on their laptop and
 * signed in on their phone expects to find both, and the honest answer is
 * usually "the sum of the two" — but not when the sum is more than the
 * seller has. The whole point of this action is that the arithmetic
 * happens against live inventory and eligibility rather than against the
 * two carts alone, and that anything it could not honour comes back as an
 * issue rather than disappearing.
 *
 * Nothing here reserves stock either. A merge is still intent.
 */
final class MergeCarts
{
    private const MAX_LINE_QUANTITY = 99;

    public function __construct(private readonly OfferEligibility $eligibility) {}

    /**
     * The anonymous cart becomes part of the customer's cart, and is
     * retired rather than deleted — an abandoned-cart analysis wants to
     * know that these two were the same person.
     */
    public function __invoke(Cart $source, Cart $target): CartMergeResult
    {
        if ($source->id === $target->id) {
            return CartMergeResult::nothing();
        }

        return DB::transaction(function () use ($source, $target): CartMergeResult {
            /*
             * Locked in id order, always. Two tabs signing in at once
             * would otherwise take the two carts in opposite orders and
             * deadlock, which is the classic way a merge becomes an
             * intermittent 500 nobody can reproduce.
             */
            $this->lockInOrder($source, $target);

            /** @var Collection<int, CartItem> $sourceItems */
            $sourceItems = CartItem::query()->where('cart_id', $source->id)->orderBy('id')->get();

            if ($sourceItems->isEmpty()) {
                $this->retire($source, $target);

                return CartMergeResult::nothing();
            }

            /** @var Collection<int, CartItem> $targetItems */
            $targetItems = CartItem::query()->where('cart_id', $target->id)->get();
            $byIdentity = $targetItems->keyBy('line_identity');

            $offerIds = $sourceItems->pluck('offer_id')
                ->merge($targetItems->pluck('offer_id'))
                ->unique()->values()->all();

            // Two queries for the whole merge, however many lines it has.
            $available = $this->availabilityFor($offerIds);
            $eligible = $this->eligibleOfferIds($offerIds);

            $moved = 0;
            $combined = 0;
            $dropped = 0;
            $issues = [];

            foreach ($sourceItems as $item) {
                $identity = (string) $item->line_identity;
                $offerId = (int) $item->offer_id;
                $stock = $available[$offerId] ?? 0;

                /*
                 * An offer that stopped being sellable while the customer
                 * was away does not come across. Carrying it over would
                 * put a line in the account cart that blocks checkout for
                 * every other line in it, which is a worse outcome than
                 * being told it is gone.
                 */
                if (($eligible[$offerId] ?? false) === false) {
                    $issues[] = new CartIssue(code: CartIssueCode::OfferUnavailable, lineIdentity: $identity);
                    $item->delete();
                    $dropped++;

                    continue;
                }

                /** @var CartItem|null $existing */
                $existing = $byIdentity->get($identity);

                if ($existing === null) {
                    $wanted = (int) $item->quantity;
                    $take = min($wanted, self::MAX_LINE_QUANTITY, $stock);

                    if ($take <= 0) {
                        $issues[] = new CartIssue(
                            code: CartIssueCode::OutOfStock,
                            lineIdentity: $identity,
                            available: 0,
                        );
                        $item->delete();
                        $dropped++;

                        continue;
                    }

                    if ($take < $wanted) {
                        $issues[] = new CartIssue(
                            code: CartIssueCode::QuantityReduced,
                            lineIdentity: $identity,
                            available: $stock,
                        );
                    }

                    // Moved rather than copied and deleted, so the line
                    // keeps the price it was added at and the customer is
                    // still told if it has changed since.
                    $item->forceFill(['cart_id' => $target->id, 'quantity' => $take])->save();
                    $available[$offerId] = $stock - $take;
                    $moved++;

                    continue;
                }

                $wanted = (int) $existing->quantity + (int) $item->quantity;
                $take = min($wanted, self::MAX_LINE_QUANTITY, $stock);

                if ($take < $wanted) {
                    /*
                     * §12's hard rule: the combined quantity is capped at
                     * what exists, never at what was asked for. The
                     * customer keeps the line and is told the number
                     * moved, which is the only outcome that does not
                     * promise stock the seller does not have.
                     */
                    $issues[] = new CartIssue(
                        code: $take <= 0 ? CartIssueCode::OutOfStock : CartIssueCode::QuantityReduced,
                        lineIdentity: $identity,
                        available: $stock,
                    );
                }

                if ($take > 0) {
                    $existing->forceFill(['quantity' => $take])->save();
                    $available[$offerId] = $stock - $take;
                }

                // The duplicate always goes: uniqueness is on
                // (cart_id, line_identity), so two of these cannot coexist.
                $item->delete();
                $combined++;
            }

            $this->retire($source, $target);
            $target->touchActivity();

            return new CartMergeResult(
                moved: $moved,
                combined: $combined,
                dropped: $dropped,
                issues: $issues,
            );
        });
    }

    /**
     * The customer had no cart of their own, so the anonymous one simply
     * becomes theirs.
     *
     * Cheaper than a merge and, more importantly, it keeps the line ids —
     * but the session token is dropped, so signing out does not leave the
     * browser holding a handle on an account's cart.
     */
    public function adopt(Cart $cart, int $userId): Cart
    {
        return DB::transaction(function () use ($cart, $userId): Cart {
            $cart->forceFill([
                'user_id' => $userId,
                'session_token' => null,
                'last_activity_at' => now(),
            ])->save();

            return $cart;
        });
    }

    private function lockInOrder(Cart $first, Cart $second): void
    {
        $ids = [$first->id, $second->id];
        sort($ids);

        Cart::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * The emptied anonymous cart is kept, marked Merged and pointed at the
     * customer it turned out to belong to.
     *
     * Its token stays on it: the one-active-cart indexes are partial on
     * `status = 'active'`, so a retired cart blocks nothing, and keeping
     * the token is what lets an abandonment analysis see that the
     * anonymous browsing and the account were the same person.
     */
    private function retire(Cart $cart, Cart $target): void
    {
        $cart->forceFill([
            'status' => CartStatus::Merged,
            'user_id' => $cart->user_id ?? $target->user_id,
        ])->save();
    }

    /**
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
     * @param  array<int, int>  $offerIds
     * @return array<int, bool>
     */
    private function eligibleOfferIds(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        $eligible = [];

        foreach ($this->eligibility->query()->whereIn('offers.id', $offerIds)->pluck('offers.id') as $id) {
            $eligible[(int) $id] = true;
        }

        return $eligible;
    }
}

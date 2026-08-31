# 05 · Inventory

## 5.1 Three numbers, one derived

The prototype's inventory screen shows exactly three figures per variant, and the distinction is the whole design:

| Number | Source | Editable |
|---|---|---|
| **On hand** | `stock_levels.on_hand` | only via a movement |
| **Held** | `Σ stock_holds.quantity WHERE state = 'held'` | never — derived from open orders |
| **Available** | `on_hand − held` | never — derived |

`available` is what the storefront may sell. `low stock` is `available ≤ threshold` (platform default 5, configurable in admin settings). `out of stock` is `available = 0`, and the product card **disables its button rather than hiding the product**, so the grid does not reflow.

## 5.2 Preventing oversell

The failure mode: two customers check out the last unit simultaneously; both succeed. This is prevented with a row lock, inside the checkout transaction, before any payment call:

```php
DB::transaction(function () use ($cart) {
    // Lock every affected stock row in a deterministic order (variant_id ASC)
    // to make deadlocks impossible between concurrent checkouts.
    $levels = StockLevel::whereIn('variant_id', $cart->variantIds()->sort())
        ->lockForUpdate()
        ->get()
        ->keyBy('variant_id');

    foreach ($cart->lines as $line) {
        $available = $levels[$line->variant_id]->on_hand
                   - Stock::heldFor($line->variant_id);      // same transaction

        if ($available < $line->quantity) {
            throw new InsufficientStock($line->variant_id, $available);
        }
    }

    // Only now: create holds. on_hand is NOT yet decremented.
    foreach ($cart->lines as $line) {
        StockHold::create([...'state' => 'held',
                           'expires_at' => now()->addMinutes(20)]);
    }
});
// Payment is called AFTER the transaction commits, never inside it.
```

**Why holds rather than immediate decrement.** A payment can fail or take seconds. Decrementing on submit would make failed checkouts destroy availability. A hold reserves without changing the physical count, and it expires: a `ReleaseExpiredHolds` job runs every minute and releases anything past `expires_at` whose order never captured.

**Ordering the locks by `variant_id` matters.** Two carts containing the same two variants in different sequences would otherwise deadlock. Sorting makes lock acquisition order identical across all transactions.

## 5.3 Movements are the truth

`stock_movements` is append-only. Every change — system or human — writes a row carrying the change, the resulting on-hand, the reason and the actor.

| Reason | Actor | Trigger |
|---|---|---|
| `order_placed` | System | payment captured → hold consumed → on_hand decremented |
| `order_cancelled` | System | cancel before packed → stock restored |
| `refund_restock` | System | refund issued |
| `restock_received` | Seller | manual adjustment |
| `count_correction` | Seller | manual adjustment |
| `damaged` | Seller | manual adjustment |
| `returned_to_supplier` | Seller | manual adjustment |
| `manual_edit` | Seller | stock changed from the product editor |

The prototype's adjust-stock dialog demands a **reason from a fixed list** and states plainly that adjustments "cannot be edited afterwards". The UI shows the delta and the resulting stock before saving. All of this is enforced server-side: the reason is a required, validated enum, and there is no update or delete route for movements.

**Reconstruction test:** replaying every movement for a variant from zero must equal its current `on_hand`. This runs nightly across the catalogue and alerts on any divergence — the equivalent of the balance reconciliation in [04](04-money-and-commission.md).

## 5.4 Variants

Each variant is a first-class record with its own SKU, price and stock. The prototype is explicit: *"Each variant has its own price and stock. Unavailable combinations are disabled, never hidden."*

- Options (up to two in Phase 1: e.g. Colour, Capacity) generate the variant matrix.
- A generated combination that the seller does not stock is still a row, marked inactive — so the storefront can render it disabled at 40% opacity and the customer can see the full range.
- Removing an option value that has orders against it **archives** the variant; it never deletes, because order lines reference it and order history must reconstruct.
- Quantity selectors on the storefront are capped at the variant's `available`.

## 5.5 Alerts

The seller dashboard carries a low-stock alert; the sidebar carries the only badge in the portal. Both are driven by a `LowStockDetected` event raised when a movement or hold takes `available` to or below the threshold, deduplicated per variant per day so a busy seller is not flooded.

## 5.6 Suspension interaction

When a seller is suspended, their listings are hidden from the storefront and their balance is frozen against payout — but **open orders are not cancelled** and their stock holds remain. The seller must still fulfil. This is stated in the prototype's seller-management screen and is a rule, not a UI nicety: the suspension scope excludes `sub_orders` in a non-terminal state.

## 5.7 Scale path

| Trigger | Change |
|---|---|
| Multiple warehouses | `stock_locations` already exists with one default row per seller; add a location selector and allocation rules |
| Very high contention on a hot SKU | Move the hold counter into a Redis atomic counter with Postgres as the durable record, reconciled continuously |
| Bulk stock updates | CSV import writing movements in batches — Phase 1.1, and the movement table needs no change |

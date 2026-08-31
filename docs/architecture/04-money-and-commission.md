# 04 · Money, commission & payouts

This is the module that must never be wrong. Everything here follows one principle: **record what happened, never restate it.**

## 4.1 The snapshot rule

When a sub-order reaches its commission trigger, the system writes four values onto it and never touches them again:

| Field | Meaning |
|---|---|
| `order_total_minor` | what the customer paid for this seller's portion |
| `commission_rate_snapshot` | the rate in force at that instant, e.g. `12.00` |
| `commission_amount_minor` | the platform's cut, computed once |
| `seller_earning_minor` | `order_total − commission`, computed once |
| `snapshotted_at` | when, so the UI can label it ("snapshotted at 12.0% on 12 Aug 2026") |

Every screen that shows commission reads these columns. Nothing anywhere multiplies `total × current_rate`. There is exactly one place in the codebase that computes a commission — `Commission\Actions\SnapshotCommission` — and a static-analysis rule forbids the multiplication anywhere else.

**The four places the UI surfaces this** (all drawn in the prototype, all must ship):
seller order detail · admin order detail · the seller earnings ledger · the commission settings screen ("changing this rate does not alter historical orders").

## 4.2 Where money moves, step by step

```
CART                     no money, no records beyond the cart itself
  │
CHECKOUT SUBMIT
  ├─ re-price from live rows           (client prices are never trusted)
  ├─ place stock holds under row locks
  ├─ create order + sub-orders + lines with price snapshots
  ├─ SnapshotCommission per sub-order  ← rate is read and frozen HERE
  └─ payment_attempts row (pending)
  │
PAYMENT
  ├─ FAILED    → release holds · attempt row stays forever · retry allowed
  │              (commission snapshot on an unpaid order is discarded with it)
  └─ CAPTURED  → payments row · holds consumed → stock_movements written
                 · sub-order → placed · seller + customer notified
  │
FULFILMENT               placed → processing → packed → shipped → delivered
  │                      each transition is an append-only status event
  │                      shipped requires a carrier + tracking number
  │
COMPLETION (delivered)
  └─ EarningPosted: seller_ledger_entries += (+seller_earning_minor)
                    available_from = delivered_at + 7 days   (clearing window)
                    platform revenue is recognised from the sub-order row
  │
REFUND (any time after capture)
  ├─ restore stock  → stock_movements (reason: refund_restock)
  ├─ ledger:  earning_reversal  −seller_earning_minor  AT THE STORED RATE
  ├─ commission reversal recorded against the same sub-order
  └─ the original rows are untouched; the reversal is its own row
```

### The completion trigger — decided 31 Aug 2026

The spec says *"Order marked complete → order total split"*. The prototype's seller dashboard shows an **available balance** that a seller can withdraw, and the PDF asks explicitly: *"When does seller earning become available — after payment, shipment, delivery, or a hold period?"*

**Decision 5, settled:** the earning posts to the ledger at **Delivered**, and becomes withdrawable after a **7-day clearing window**. This protects the platform from refund-after-payout, the single most expensive marketplace failure mode.

Implementation:

- `seller_ledger_entries` carries `available_from timestamptz` — `delivered_at + 7 days` on an `earning` row, `now()` on reversals and adjustments.
- The seller's balance renders as **three figures**, which the prototype's stat-card strip already has room for:

  | Figure | Definition |
  |---|---|
  | **Clearing** | `Σ amount WHERE available_from > now()` — earned, not yet withdrawable |
  | **Available** | `Σ amount WHERE available_from <= now()` − held by the open request |
  | **Held** | the amount of the seller's one open payout request |

- Payout validation reads **available only**. A request above it is rejected server-side with the actual figure named in the error, not a generic message.
- The earnings statement shows the clearing date on every earning row, so a seller sees when money lands rather than guessing.
- The window is a platform setting (`payout.clearing_days`, default 7), so it can be shortened for trusted sellers later without a migration.

## 4.3 Rounding — specified, not left to the language

```php
// Commission\Support\Split
public static function split(int $totalMinor, string $ratePercent): array
{
    // rate arrives as a decimal string, e.g. "12.00", never a float
    $commission = intdiv($totalMinor * (int) bcmul($ratePercent, '100'), 10_000);
    // half-up on the exact half, deterministic:
    $remainder  = ($totalMinor * (int) bcmul($ratePercent, '100')) % 10_000;
    if ($remainder * 2 >= 10_000) { $commission++; }

    return [$commission, $totalMinor - $commission];   // always sums to total
}
```

The seller earning is defined as *the remainder*, never independently computed. That guarantees invariant 1 in [03](03-data-model.md#34-invariants) can never break, whatever the rounding.

**Multi-line rounding.** Commission is taken on the sub-order total, once — not per line — so per-line rounding drift cannot accumulate.

## 4.4 The seller ledger

An append-only journal. Every row is an event, with a running balance written at insert time.

| Type | Sign | Written when |
|---|---|---|
| `earning` | + | sub-order completes |
| `earning_reversal` | − | refund, at the sub-order's stored rate |
| `payout` | − | admin approves a payout request |
| `payout_reversal` | + | a settled payout is reversed (rare, manual) |
| `adjustment` | ± | Finance correction, always with a note and an actor |

**Never edit a row to fix a mistake.** A wrong earning is corrected by an `adjustment` that references it. The prototype states this in plain English on the earnings screen — *"Reversals appear as their own negative entry — the original earning is never edited"* — and the implementation must match the promise.

**Balance derivation:**
```
available = Σ(entries where available_from <= now) − held_by_open_requests
clearing  = Σ(entries where available_from >  now)      -- Decision 5: +7 days
held      = the amount of the seller's one open payout request
```

`seller_balances` caches these; a nightly `ReconcileSellerBalances` job recomputes from the ledger and raises a **critical alert** on any mismatch, with the seller id and the delta. A silent balance drift is a P1 incident, not a data-quality ticket.

## 4.5 Commission rate changes

The commission settings screen is described in the prototype as *"the single most consequential control in the product, so the screen is mostly explanation."* The implementation matches:

- Rates live in an **append-only `commission_rates` table**. Setting a rate inserts a row with a future `effective_from`; it never updates the previous one.
- **Minimum 7 days' notice.** A rate cannot be back-dated — validated server-side, not just disabled in the UI.
- **Owner or Finance role only**, enforced by policy on the request, not by hiding the nav item.
- Sellers are **emailed 7 days before** the change takes effect (a scheduled job reading forward-dated rows).
- The rate history table shows, per rate: effective from, orders completed under it, commission collected under it, who set it, and the note. All derived from snapshots, so it is exact.
- The confirmation dialog previews the effect on a $100 order and restates the snapshot rule in words before committing.

**Per-seller and per-category rates** are not Phase 1, but the schema anticipates them: `commission_rates` gains nullable `seller_id` / `category_id` columns and resolution becomes "most specific applicable row". No table changes, no migration of history.

## 4.6 Payouts

```
Seller: AVAILABLE balance ≥ $50 (platform minimum, configurable)
        — clearing money is not requestable
  → Request payout (amount ≤ available; "withdraw full balance" shortcut)
  → amount is HELD: excluded from available, one open request at a time
    (enforced by a partial unique index, not just a UI check)
  → Admin queue shows: amount, balance after, bank last4, seller context
      ├─ APPROVE → ledger: payout −amount · request approved ·
      │            settlement happens outside the platform in Phase 1 ·
      │            settlement_ref recorded when the transfer clears
      └─ REJECT  → REQUIRED reason (validated server-side) ·
                   held amount released back to available ·
                   reason shown to the seller verbatim, stored forever
```

The seller can cancel their own open request while it is `requested`; that releases the hold and records the cancellation.

**Phase 2 automation.** `PayoutGateway` is a port with one Phase 1 implementation (`ManualSettlement`) that only records. Stripe Connect Transfers or ACH slot in behind the same interface, and the admin screen gains a status column — no workflow redesign.

## 4.7 Payments — provider-agnostic by construction

The spec requires a payment layer that does not depend on a single provider. The port:

```php
interface PaymentGateway {
    public function createIntent(OrderPaymentRequest $r): PaymentIntentResult;
    public function capture(string $intentId, string $idempotencyKey): CaptureResult;
    public function refund(RefundRequest $r): RefundResult;
    public function verifyWebhook(string $payload, string $signature): WebhookEvent;
}
```

Phase 1 ships `StripeGateway` in test mode. **Stripe Connect** is the recommended production model because it handles the commission split, seller identity verification (KYC) and automated seller payouts natively — but nothing above depends on it, so PayPal Commerce, Adyen or Authorize.net are drop-in.

**Rules that hold regardless of provider:**
- Card data never touches our servers. Stripe Elements / Payment Element only. This keeps us on **PCI DSS SAQ-A**, the lightest scope.
- Every attempt is a row, including failures. A customer retrying three times produces three rows against one order — the prototype's payments screen depends on this.
- Every webhook is verified by signature, stored by `event_id` with a unique index, and processed exactly once. Replays are no-ops.
- Every capture and refund carries an **idempotency key** derived from the order reference, so a network retry cannot double-charge or double-refund.
- Amounts are re-verified against our own order record on webhook receipt. A mismatch is an alert, never an auto-accept.

## 4.8 What Finance can prove at any moment

These are the reports the ledger design makes trivially correct, and they are the real test of the model:

- **GMV** for a period = Σ `sub_orders.order_total_minor` where completed in range.
- **Platform revenue** = Σ `commission_amount_minor` − Σ commission reversed. Never gross × rate.
- **Seller liability** = Σ ledger balances across all sellers. Ties to the balance cache.
- **Cash out** = Σ approved payouts. Ties to the bank statement.
- **Per-seller commission** = Σ of that seller's order snapshots — which will *not* equal gross × today's rate the moment a rate has changed, and the admin screen says so in a footnote for exactly that reason.

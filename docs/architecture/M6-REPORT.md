# M6 completion report — fulfilment, tracking, delivery and earnings clearing

Answers the fifty-four questions in the M6 brief, in order, followed by a
traceability table against the ninety-eight required behaviours. Where a
gate could not be run in this environment it says so rather than claiming
a result.

---

## 1. Branch and final SHA

`claude/veritas-marketplace-architecture-vov8c0`.

The last code commit is `b2f40f7`; the branch head is the commit carrying
this report, which is its immediate child. Nothing was force-pushed and no
published history was rewritten.

## 2. M6 commit range

`ab8c8e1..HEAD` — twelve code commits plus this report, oldest first.

| SHA       | Subject                                                            |
| --------- | ------------------------------------------------------------------ |
| `ba09327` | fix: codify payment-attempt vs order-cancellation semantics        |
| `727aed3` | feat: implement shipment and shipment-item aggregates              |
| `b5cf6ee` | feat: implement seller earnings clearing                           |
| `17f7554` | feat: integrate refunds with fulfilable quantities and tracking    |
| `eb988fb` | feat: add the seller fulfilment state machine, actions and HTTP    |
| `537322a` | feat: build the seller fulfilment UI                               |
| `61c9341` | feat: build customer tracking and the parent order aggregate       |
| `0211f29` | feat: extend admin fulfilment operations                           |
| `c797575` | feat: announce fulfilment to the customer and record it            |
| `af120e1` | fix: match real key prefixes rather than two letters               |
| `0266ff4` | feat: give the seller dashboard real work counts and earnings      |
| `b2f40f7` | test: add fulfilment read-surface bounds and the M6 CI smokes      |

## 3. Package and version changes

**None.** No dependency was added, removed or upgraded. M6 is entirely
application code: no courier SDK, no shipping-label library, no scheduling
package. `composer.json`, `composer.lock`, `package.json` and
`package-lock.json` are untouched.

## 4. M0–M5 regression result

**Preserved.** The M5 baseline of 929 tests is intact; M6 adds 78 net new,
for **1,007 tests / 12,591 assertions, all passing**. The focused M5
regression named in §1 of the brief — payment authority, browser-cannot-pay,
webhook replay and concurrency, amount/currency/reference verification,
inventory commit, earnings and commission posting, reconciliation, refunds
in every shape, refund concurrency, and both isolation suites — was run
first and returned **121 tests / 733 assertions passing** before any
fulfilment code was written.

Nothing was deleted, skipped or weakened. Three existing assertions were
*adapted*, each because the thing they described legitimately changed:

- `StateMachineTest::shipping_declares_that_it_requires_tracking` now
  asserts the shipment enum. Tracking moved from the seller order to the
  parcel, because an order can go out in two boxes with two carriers and a
  single requirement on the order could only describe one of them.
- `StateMachineTest::earning_posts_at_delivered_not_at_capture` became
  `earning_clears_at_delivered_not_at_capture`. M0 assumed the earning
  would be *posted* on delivery; M5 settled it differently and better — the
  money is recorded at payment from the purchase snapshot and sits pending
  — so what delivery starts is the clock. The assertion is strictly
  stronger: it also pins that a *partial* delivery starts nothing.
- Two payment secret-leak assertions searched response bodies for `sk_`,
  which a ULID provider reference eventually contains by chance, and did.
  They now match `sk_test_`/`sk_live_`. That is a fix to a test that failed
  on a coin flip rather than on a leak.

## 5. Payment-attempt vs order-cancellation policy

Codified in `App\Modules\Orders\Support\OrderPayability` and pinned by
`OrderCancellationSemanticsTest`.

**A — a payment attempt failed or was cancelled at the provider.** One try
at moving money did not work. That is usually a customer reaching for a
different card, so the order stays `pending_payment`, its reservation
stays held, and they may try again. `RecordPaymentFailure` closes the
*attempt* and touches neither the order nor the hold. A Stripe
`payment_intent.canceled` is always this.

**B — the order was cancelled, or its checkout expired.** The business
decision: the order stops being payable, the hold is released, and no
earning or commission is realised. `CancelUnpaidOrder` does all of it in
one idempotent transaction — cancelling twice cancels once, with one
history row and one release.

The rule had drifted into being duplicated by `PreparePayment` and
`DescribePaymentState`; both now read the one policy. The clock that ends
an order is the checkout expiry that existed before payments did — one
mechanism, not one per provider event.

## 6. Seller-order fulfilment states

`PENDING_PAYMENT → PAID → CONFIRMED → PROCESSING → PACKED →
PARTIALLY_SHIPPED | SHIPPED → PARTIALLY_DELIVERED | DELIVERED → COMPLETED`,
with `CANCELLED`, `PARTIALLY_REFUNDED`, `REFUNDED` and `DISPUTED` as
exception states.

The enum is **deliberately not a payment state**. `paid` is the entry
point — the moment fulfilment becomes possible — and everything after it is
about physical goods. An order that is `shipped` and fully refunded is a
real, coherent thing, and one column trying to say both would have to lie
about one of them.

Two transitions are worth naming. `CONFIRMED → PACKED` is allowed
directly: a seller who confirms and immediately makes up the parcel has
done the processing, and forcing a click that means nothing only teaches
them to click it without meaning it. `PARTIALLY_SHIPPED → DELIVERED` is
reachable because a refund can reduce what the seller still owes — an order
that shipped two of three units is fully delivered once those two arrive
and the third has been refunded, and holding it open forever would strand
the seller's earnings.

Invalid transitions fail server-side in `AdvanceSellerOrder`, which is the
only thing that moves a seller order. Repeating a transition returns false
and writes no second history row.

## 7. Shipment schema

`shipments` — `id`, `public_id` (ULID), `reference`, `seller_order_id`,
`sequence`, `status`, `carrier_name`, `carrier_code`, `tracking_number`,
`tracking_url`, `packed_at`, `shipped_at`, `delivered_at`, `cancelled_at`,
`created_by_type`, `created_by_id`, `notes`, timestamps.

M0's table was replaced rather than extended: it carried NOT NULL carrier,
tracking number and `shipped_at`, which a parcel being packed does not have
yet, and nothing had shipped in any environment.

The model is append-only in the ways that matter — `seller_order_id`,
`reference` and `sequence` are immutable, and a shipment is cancelled
rather than deleted, because a customer was told it existed.

## 8. Shipment-item architecture

`shipment_items` — `shipment_id`, `order_item_id`, `quantity`, unique on
`(shipment_id, order_item_id)`, with a CHECK that quantity is positive.

This row is what makes a partial shipment expressible. Without it a
shipment is only a status change on a seller order, "what is left to send"
has to be inferred, and a customer who ordered three things and received
one is told their order shipped.

Contents are immutable once the parcel has left: the model refuses updates
and deletes when the shipment's status is no longer mutable, because what
was in the box is then a historical fact.

## 9. Shipment numbering

`VC-24081-01-S01`, from a `sequence` column, with `MAX(sequence) + 1`
allocated under the seller order's row lock and a unique index on
`(seller_order_id, sequence)` underneath.

Both are required, not one or the other. The lock stops two requests
reading the same maximum; the index means that if a future caller ever
loses the lock, the second insert fails loudly rather than duplicating a
number a customer has been given. `FulfilmentConcurrencyTest` proves the
index refuses a duplicate from a second connection.

## 10. Partial-shipment support

Fully supported at the data and domain level. A seller order may have any
number of parcels; each names its items and quantities; the aggregate state
is derived from what is actually in them.

`RecomputeSellerOrderFulfilment` decides: everything fulfilable delivered →
`DELIVERED`, some delivered → `PARTIALLY_DELIVERED`, everything shipped →
`SHIPPED`, some shipped → `PARTIALLY_SHIPPED`. Delivery is checked before
dispatch, because "everything arrived" is the more specific fact.

The Phase-1 UI defaults the packing list to everything still owed, because
one parcel usually is — and leaves it editable, because the whole reason
shipments are their own aggregate is that sometimes it is not.

## 11. Tracking architecture

Carrier and tracking number are free text with a datalist of the six
couriers the platform can build a link for. Any carrier name is accepted —
the world has more couriers than any list — and an unknown one simply gets
no link.

**The URL is generated and never accepted.** `Carriers::trackingUrl` builds
it from a template with the tracking number `rawurlencode`d into one path
segment. A seller-supplied URL rendered to a customer is an instruction to
their browser that the marketplace would be vouching for, and "it was in
the tracking field" is not a defence. A test posts a tracking number that
tries to be a redirect and asserts it comes back encoded.

`Carriers` is also the seam a future `ShippingProvider` slots into: it
already only answers code, name and URL.

## 12. Shipment transition rules

`ShipmentStatus`: `DRAFT → READY | SHIPPED | CANCELLED`,
`READY → SHIPPED | DRAFT | CANCELLED`,
`SHIPPED → IN_TRANSIT | DELIVERED | EXCEPTION`,
`IN_TRANSIT → DELIVERED | EXCEPTION`,
`EXCEPTION → IN_TRANSIT | DELIVERED | CANCELLED`.
`DELIVERED` and `CANCELLED` are terminal.

`MarkShipmentShipped` requires: the seller order paid, at least one item,
and both a carrier and a tracking number — "shipped" with neither is a
status change dressed up as information. `MarkShipmentDelivered` requires
that the parcel actually left.

Every move writes to `shipment_status_history` with the actor, the reason
where there is one, and the tracking as it stood.

## 13. Delivery authority policy

A **seller** may record delivery for their own parcel. An **admin** holding
`fulfilment.override` may record one on their behalf, with a written reason
that goes into the parcel's history. A **customer** cannot: visiting a
tracking page is not delivery, and making it so would let anyone start a
seller's earnings clock by refreshing a URL.

There is no carrier integration, and the UI says so rather than implying
the platform watched the parcel arrive — the delivered notification says a
person recorded it. A future carrier webhook becomes another caller of
`MarkShipmentDelivered`, not a rewrite of it.

## 14. Seller-order delivery aggregation

Never set by whoever pressed the button. `MarkShipmentDelivered` credits
the parcel's units and then calls `RecomputeSellerOrderFulfilment`, which
reads every item's fulfilable, shipped and delivered counts and decides.

One parcel arriving out of three is `PARTIALLY_DELIVERED`, and no clock
starts. Refunded units are excluded from the denominator throughout, so an
order whose remaining unit was refunded is delivered once everything still
owed has arrived.

## 15. Refund/fulfilment interaction

`FulfilmentQuantities` is the one calculation, and refunded units come from
`RefundedQuantities` — a Payments query the Orders module depends on, not
Payments' models, which keeps the two extractable.

The distinction that makes it work is **units versus money**. A refund
returning whole lines carries a quantity and reduces what is owed; a
partial money refund — goodwill for a late delivery — carries quantity zero
and reduces nothing, because the customer is still expecting the goods.

A refunded unit cannot be put in a parcel: `CreateShipment` refuses it with
`exceeds_remaining`.

## 16. Inventory behaviour at shipment

**Nothing.** No movement, no balance change. The units were sold —
reservation consumed, `on_hand` reduced — when the payment was verified in
M5. Debiting again at dispatch would take the same stock off the shelf
twice and leave every seller's count short by exactly their sales.

Asserted twice: a unit test comparing balances and movement counts across
the whole ship-and-deliver flow, and the CI smoke's
`inventory after_payment=N after_shipping=N`.

A refund after shipment does not restock either (§25 of the brief): the
goods are on a customer's shelf, in a van, or in a bin. Returns are M7's.

## 17. Customer tracking UI

`/account/orders/{reference}` shows each seller's part on its own — state,
parcels, carrier, tracking link, and what is in each box — under a derived
summary of the whole order.

Merging them would be tidier and wrong: it would say "shipped" when a third
of the order had left. The summary is only as far along as the least
advanced seller.

Authenticated only. An order number is printed on emails and packing slips,
so it is not a secret and cannot be a credential; there is no page that
takes one and nothing else, and the tests check the obvious URLs somebody
would try.

## 18. Seller fulfilment UI

`/seller/orders/{reference}` gains a fulfilment panel: per-item progress,
a packing list, parcels with their contents and history, tracking editing,
and the problem-reporting form.

Every number arrives computed — `canConfirm`, `canPack`,
`remainingToShip`. Nothing is worked out in the browser, because an
order's remaining quantity depends on refunds in another module and a
number recalculated in React would be a fourth opinion about what the
seller owes.

The dashboard gains real work counts and the three money states.

## 19. Admin fulfilment UI

`/admin/fulfilment` lists every seller's work with filters for store,
fulfilment state, carrier, tracking number, date and clearing status —
"due for release" being the one an operator reaches for when a seller asks
where their money is.

`/admin/fulfilment/{reference}` shows the hierarchy: the parent's derived
summary, per-item counts, every parcel with its full history, reported
problems, and — behind `earnings.clearing.view` — the seller's ledger
entries with their `available_at`.

Two corrective actions and **no status dropdown**: record a delivery, and
correct tracking. Both take a written reason and both run the same domain
action a seller would, so an operator cannot put an order into a state the
domain has no route to.

## 20. Seller fulfilment RBAC

The existing `orders.manage`, which the role matrix already gives to Owner,
Administrator and Fulfilment Manager and withholds from Catalogue Manager,
Inventory Manager, Finance Manager and Viewer — exactly §47's suggested
behaviour. A separate `fulfilment.manage` would have drawn the same line
twice and left two places to keep in step.

`orders.view` opens the screen; the finance figures on it stay behind
`finance.view`.

## 21. Platform fulfilment RBAC

Four new permissions, because they are four different jobs:

| Permission                   | Held by                                    |
| ---------------------------- | ------------------------------------------ |
| `fulfilment.view`            | Marketplace Admin, Finance, Seller Ops, Support |
| `fulfilment.override`        | Marketplace Admin                          |
| `fulfilment.tracking.correct`| Marketplace Admin                          |
| `earnings.clearing.view`     | Finance Admin                              |

Support answers "where is my parcel" and holds only the first. Analyst
holds none and cannot open the screens. Finance sees the money and its
schedule and does not move parcels.

## 22. Seller isolation result

Enforced server-side and 404 rather than 403 throughout. `SellerOrder`'s
tenant scope removes another seller's rows; the 404 keeps their existence
private too.

Every write re-resolves the parcel through its own seller order by public
id, so a shipment identifier lifted from another seller's page reaches no
action — proven by posting Seller B's parcel id at Seller A's own order and
getting 404. A seller also cannot put another seller's item in their own
parcel: the item lookup is scoped to the seller order.

## 23. Shipment concurrency strategy

Three guards, in descending order of how much they can be trusted:

1. The seller order is locked for the whole transaction, which serialises
   two concurrent shipments and makes `MAX(sequence)+1` safe.
2. Each order item row is locked before its remaining quantity is read, and
   `allocated_quantity` moves in the same transaction.
3. `CHECK (allocated_quantity <= quantity)` refuses over-allocation
   outright, so a future caller that forgets the lock gets an error rather
   than a customer getting nothing.

Units leave the fulfilable pool at allocation — when the parcel is made up,
not when it leaves — because waiting would let a second shipment claim the
same unit in between.

## 24. Delivery idempotency

`MarkShipmentDelivered` returns early for a parcel already delivered, under
a row lock. Delivering three times produces one arrival, one set of
delivered units, one history row, one clearing date, and one
`SellerOrderDelivered`. The clock is not restarted.

`SellerOrderDelivered` fires exactly once because a seller order enters
`DELIVERED` once — `AdvanceSellerOrder` refuses a same-state move and the
enum has no route back — rather than because a listener checks a flag.

## 25. Seller earning clearing architecture

```
payment verified → earning PENDING, available_at null
seller order DELIVERED → CLEARING, available_at = delivered_at + period
available_at passes → sweep → AVAILABLE, order COMPLETED
```

`StartClearing` runs **inside the delivery transaction**, not in a queued
listener: a delivery recorded with a clock that failed to start is a seller
whose money is stuck pending forever, and nothing would notice.

Nothing financial moves at either step. No amount is written, no entry
created, nothing recalculated — the money was recorded at payment from the
purchase snapshot, and both steps change availability on records that
already exist. That is why re-running is safe.

## 26. Clearing-period resolution

`ClearingPolicy` resolves it once: the seller's own override, else the
platform setting, else the seven-day default in config. Phase-1 default is
**7 days**, asserted directly. A seller override is honoured, asserted with
a two-day seller.

`Carbon::now()->addDays(7)` appears nowhere: scattering it would mean
changing the platform's terms required a deploy and a search, and would
make a per-seller arrangement impossible to honour.

## 27. `available_at` implementation

`delivered_at + resolved period`, written on each clearing ledger entry and
denormalised onto the seller order as `earnings_clear_at`.

Computed from the **delivery timestamp**, not from `now()`, so a delivery
recorded late — an admin correcting the record a day afterwards — clears
from when the parcel actually arrived. The denormalised copy exists so the
sweep finds its work with an indexed range scan
(`seller_orders_clearing_due`, partial) rather than by walking the ledger,
and so an operator can see the date without opening a financial table.

## 28. Clearing job

`php artisan earnings:clear`, scheduled **hourly** with
`withoutOverlapping`. Hourly rather than daily because a seller whose money
cleared at 09:00 should not wait until the small hours, and the pass is
cheap: it reads an indexed range of orders actually due.

`CompleteDeliveredSellerOrders` releases and completes in one pass, because
both happen at the same moment and for the same reason — two jobs would
race each other to the same rows.

## 29. Clearing concurrency result

A conditional UPDATE whose WHERE is the lock:
`status = clearing AND available_at <= now`. Two workers sweeping together
both narrow to the same rows and the second matches nothing.

Proven from a second database connection: after the first sweep commits,
the other connection's claim updates **zero** rows. A second sweep releases
0 and completes 0; the balance is unchanged; there is one completion
history row and no new ledger rows. A reversed entry is never released,
because it is no longer clearing.

## 30. Refund-during-clearing behaviour

**One M5 behaviour was corrected here.** Refund reversals were always
posted `PENDING`. A reversal against an earning that was clearing would
have stayed behind in pending while the positive entry cleared, and the
seller's available balance would have ended up overstated by exactly the
refund.

A reversal now carries the same state as the entry it cancels, with the
same `available_at` when that is `CLEARING`. The pair then moves together
and the net is right at every stage: $90 clearing less an $18 reversal is
$72 clearing, and $72 available when it releases.

A full refund during clearing nets to zero available, with both entries
still in the ledger — the original untouched, the reversal beside it
pointing at what it reverses.

## 31. Refund-after-available behaviour

The reversal is posted `AVAILABLE` too, so the available balance drops
immediately: $8,800 available less a $1,760 reversal is $7,040 available.

The original entry is neither mutated nor deleted — it is still $8,800 and
still `AVAILABLE`. This matters before M7 exists: available is the number a
payout will be requested against, and a refund sitting invisibly behind it
would be paid out.

## 32. Net available-balance behaviour

`SellerBalance` derives it from the ledger — a filtered, currency-scoped sum
of positive earnings and the negative reversals that cancel them, netted
within each state. Never "sum the delivered orders" and never the seller
orders' own earning totals: those are summaries of intent that drift the
moment a refund is issued.

A signed `netMinor()` is exposed alongside the clamped figures so a
genuinely negative position is visible to tests and to M7 rather than
silently absorbed. **No constraint was added that would make a future
negative seller balance impossible** (§37 of the brief) — the ledger takes
negative amounts today.

## 33. Seller-order completion policy

`DELIVERED` = everything fulfilable has arrived. `COMPLETED` = the clearing
period then elapsed with nothing blocking. The same sweep does both,
because they happen at the same moment.

A seller **cannot** accelerate it: completion is a transition only the
sweep makes, from a date the platform wrote, from a period the platform
resolved. There is no seller route to `COMPLETED` at all. A disputed or
fully refunded order is not walked forward by a clock.

## 34. Parent multi-seller completion policy

The parent's state is derived, never set by a seller finishing.
`SummariseOrderFulfilment` reports the least advanced seller: A delivered
and C processing is `partially_delivered`, never `delivered`.

Each seller order completes independently on its own clearing date —
Seller A delivered on the 1st is available on the 8th while Seller B
delivered on the 3rd is available on the 10th. Cancelled and fully refunded
sellers drop out of the reckoning rather than dragging the parent
backwards.

## 35. Dashboard and earnings changes

The seller dashboard answers what needs doing (to confirm, being prepared,
on their way, delivered, completed, low stock) and where the money is
(pending, clearing, available, with the next release date).

Every count is a SQL aggregate — the same query count for ten orders as for
one, asserted — and every one links to the filtered list containing exactly
those orders. The three money figures are kept apart because one
"earnings" number would reasonably be read as spendable, and the screen
says withdrawals are not open yet rather than implying they are.

A member without `finance.view` is not shown the money, and the ledger is
not read for them at all.

## 36. Notifications

| Trigger              | Recipient | Notification                      |
| -------------------- | --------- | --------------------------------- |
| `ShipmentShipped`    | Customer  | `ShipmentShippedNotification`     |
| `ShipmentDelivered`  | Customer  | `ShipmentDeliveredNotification`   |

One per parcel, because that is what happened: an order in two boxes
produces two, and one covering both would be wrong about the first while
the second was still being packed. Both are queued on the emails lane.

Exactly once, and the guarantee is not a flag in the listener — the actions
refuse a parcel that has already moved, under a row lock, so a retried job
sends nothing.

The delivered message distinguishes "part of your order arrived" from "your
order arrived", and says a person recorded it. `SellerOrderDelivered` has
no customer message of its own: the customer was already told about every
parcel that arrived.

## 37. Audit events

Seller: `fulfilment.confirmed`, `fulfilment.processing`,
`fulfilment.shipment_created`, `fulfilment.tracking_updated`,
`fulfilment.shipped`, `fulfilment.delivered`,
`fulfilment.issue_reported`.

Admin: `fulfilment.override.delivered`,
`fulfilment.override.tracking_corrected` — both with the reason recorded.

Plus `shipment_status_history`, which is the operational record rather than
the compliance one: every parcel move with its actor, reason, and the
tracking as it stood, so a correction never silently replaces what the
customer was told.

## 38. Domain events

`SellerOrderConfirmed`, `SellerOrderProcessing`, `SellerOrderPacked`,
`ShipmentCreated`, `ShipmentShipped`, `ShipmentDelivered`,
`SellerOrderDelivered`, `SellerOrderCompleted`,
`SellerEarningEnteredClearing`, `SellerEarningAvailable`.

All readonly scalars, all dispatched after commit. Core state changes
happen in the domain actions; listeners handle notifications and analytics
only.

## 39. Exact migrations and constraints

One migration: `2026_07_01_000100_build_fulfilment_lifecycle.php`.

**Tables created** — `shipment_items`, `shipment_status_history`,
`fulfilment_issues`; `shipments` dropped and rebuilt.

**Columns added** — `order_items`: `allocated_quantity`,
`delivered_quantity`. `seller_orders`: `processing_at`, `packed_at`,
`earnings_clear_at`.

**Unique indexes** — `shipments.reference`;
`shipments (seller_order_id, sequence)`;
`shipment_items (shipment_id, order_item_id)`; plus public-id uniques.

**CHECK constraints** — `shipment_items_quantity_is_positive`,
`order_items_allocated_within_ordered`,
`order_items_delivered_within_allocated`.

**Partial index** — `seller_orders_clearing_due` on `earnings_clear_at`
where it is set and the order is not completed.

Tracking numbers are **deliberately not globally unique**: carriers reuse
formats and two couriers may legitimately issue the same string, so a
global unique index would reject a real shipment.

## 40. Exact total tests and assertions

**1,007 tests, 12,591 assertions — all passing.**

## 41. Exact M6-specific test count

**79 tests** across twelve files:

| Suite                              | Tests |
| ---------------------------------- | ----: |
| `EarningsClearingTest`             |    13 |
| `FulfilmentInvariantsTest`         |     8 |
| `RefundFulfilmentIntegrationTest`  |     8 |
| `AdminFulfilmentTest`              |     7 |
| `FulfilmentReadSurfaceTest`        |     7 |
| `SellerFulfilmentHttpTest`         |     7 |
| `FulfilmentNotificationTest`       |     6 |
| `CustomerTrackingTest`             |     5 |
| `FulfilmentConcurrencyTest`        |     5 |
| `SellerDashboardTest`              |     5 |
| `OrderCancellationSemanticsTest`   |     5 |
| `StateMachineTest` (new cases)     |     3 |

## 42. PHPStan / Larastan result

**Level 8, zero errors**, with Larastan loaded. No baseline entries, no
`@phpstan-ignore` comments, no casts added to silence anything.

## 43. Frontend gates

| Gate                        | Result                                  |
| --------------------------- | --------------------------------------- |
| `tsc --noEmit` (strict)     | pass                                    |
| ESLint (`--max-warnings=0`) | pass                                    |
| Prettier `--check`          | pass                                    |
| Client production build     | pass                                    |
| SSR production build        | pass — `bootstrap/ssr/ssr.js` 128.8 kB  |

No financial or fulfilment arithmetic in React, and no local status maps:
every new status goes through the generated presentation registry, which
an invariant test enforces.

## 44. Query-count results

Asserted against a **growing dataset** rather than a fixed number: what
matters is that a page runs the same queries with ten parcels as with one.
A fixed number fails on every refactor and passes on the N+1 it was meant
to catch.

| Surface                       | Result                                      |
| ----------------------------- | ------------------------------------------- |
| Seller order list             | unchanged from 1 to 9 orders                |
| Seller fulfilment detail      | unchanged from 1 to 6 parcels               |
| Customer tracking             | unchanged from 1 to 4 sellers               |
| Admin fulfilment list         | unchanged from 1 to 7 rows                  |
| Admin fulfilment detail       | unchanged from 1 to 4 parcels               |
| Seller dashboard              | unchanged from 0 to 10 delivered orders     |

## 45. Redis / Horizon smoke

**Verified.** `php artisan queues:smoke --timeout=60` against the live
Redis drained every queue, payments first:

```
ok payments   ok critical   ok emails   ok catalogue
ok default    ok search     ok media
Every queue was drained.
```

The clearing sweep is a scheduled console command rather than a queued job,
and is exercised directly; the notification listeners are queued on the
emails lane and drain with it.

## 46. Full fulfilment live smoke

**Verified locally** against real PostgreSQL and real Redis, through the
domain actions, with `.github/ci/m6-fulfilment-smoke.php` — the same script
the CI Docker job runs:

```
paid seller_order=VC-1-01 status=paid
shipped shipment=VC-1-01-S01 status=shipped order=shipped carrier=UPS
inventory after_payment=23 after_shipping=23
delivered status=delivered clear_at=2026-09-12
clearing pending=0 clearing=7920 available=0
early_sweep released=0 completed=0
sweep released=1 completed=1
resweep released=0 completed=0
cleared available=7920 clearing=0 order_status=completed
```

Payment goes through the real M5 boundary — signed event, real webhook
controller, real `queue:work` against Redis — and the clearing sweep is the
real scheduled command. Delivery is called three times to prove the repeat
is inert.

## 47. Multi-seller delivery smoke

Part of the same script:

```
multi seller_orders=2
first_delivered a=delivered b=paid parent=partially_delivered
independent_clocks a=2026-09-12 b=null
both_delivered a=delivered b=delivered parent=delivered
```

Delivering Seller A leaves Seller B untouched and starts no clock for them;
the parent is `partially_delivered`, never `delivered`.

## 48. SSR / noindex smoke

Customer tracking and seller fulfilment pages are server-rendered and
carry `X-Robots-Tag: noindex, nofollow`, asserted in the suite and again
over HTTP in the CI Docker job. Admin fulfilment likewise.

`§44` is covered by tests rather than only by absence: the obvious
unauthenticated tracking URLs (`/track/{ref}`, `/orders/{ref}`,
`/tracking/{ref}`) all 404, and `/account/orders/{ref}` redirects to login.

## 49. Docker local status

**Docker local: unverified — no daemon.** `docker info` fails in this
environment. Nothing about local Docker is claimed.

## 50. Docker CI status

The Docker job gains two steps, which run on push:

1. **An order is fulfilled, delivered and cleared in the built image** —
   the smoke above, with greps for each line including
   `inventory after_payment=N after_shipping=N` (the same N, by
   backreference), the three sweep outcomes, and the multi-seller lines.
2. **The fulfilment surfaces answer over HTTP and stay private** — customer
   tracking showing the seller order and carrier, the seller's own parcel,
   a stranger getting 404 on both, an order number opening nothing, noindex
   on both pages, and a customer's POST to a seller route returning 404.

The CI seed gained a second seller listing so the two-seller order is real.

## 51. Stripe real test-network status

**Still unverified — no credentials, no egress to `api.stripe.com`**,
carried forward unchanged from M5. M6 required no new Stripe integration
and did not touch the adapter. The M5 report's instructions for closing
this gate still stand.

## 52. Bugs discovered during M6, and their fixes

1. **Refund reversals were invisible to the balance they should reduce.**
   M5 posted every reversal `PENDING`. A refund against an available
   earning left the available figure at its full amount with the refund
   sitting behind it — and M7 would have paid that out. Reversals now carry
   the state of the entry they cancel.
2. **A reversal left behind in pending would have overstated a later
   release.** `StartClearing` initially moved only positive entries. A
   refund issued before delivery would have stayed pending while the
   earning cleared, and the seller's eventual available balance would have
   been too high by exactly the refund. It now moves everything for the
   order.
3. **A refund could strand an order forever.** An order that shipped two of
   three units, with the third refunded, had no route from
   `PARTIALLY_SHIPPED` to `DELIVERED` — so the earnings clock would never
   start. The transition was added.
4. **A leak assertion failed on a coin flip.** Two M5 tests searched
   response bodies for `sk_`, which a ULID provider reference eventually
   contains by chance, and did. They now match the real key prefixes. A
   test that fails randomly is worse than no test: the next person to see
   it red learns to re-run rather than to look.

## 53. Intentional deviations

1. **No new seller permission for fulfilment.** §47 offered
   `fulfilment.view` / `fulfilment.manage`; the existing `orders.view` /
   `orders.manage` already produce exactly the role behaviour §47
   describes, and adding a second pair would have meant two places to keep
   in step. Platform staff *did* get new permissions, because there the
   jobs genuinely differ.
2. **Confirmation is required before packing.** §10 allows packing at
   seller-order or shipment level; making up a parcel from `PAID` without
   confirming would skip the only step that says a person has looked, so
   `CreateShipment` refuses it with `not_confirmed`. `CONFIRMED → PACKED`
   is allowed directly so the required click is not a meaningless one.
3. **Making up a parcel is packing.** Creating a shipment moves the order
   to `PACKED` rather than requiring a separate button. It is a consequence
   of a deliberate action, which is the distinction §9 draws — not a state
   change caused by opening a page.
4. **`allocated_quantity` counts units committed, not units shipped.** It
   is incremented when the parcel is made up, so the CHECK constraint
   prevents over-allocation rather than merely over-shipping. Units shipped
   are derived from the parcels' own states.
5. **Sellers report fulfilment problems; they do not refund them.** §26
   offered either; the report path was chosen and the refund path was not
   built for sellers, because the party with the incentive to make a
   problem disappear should not also be the party who can pay for it.
6. **A tracking number is not globally unique.** §63 warned against the
   assumption; no unique index was added, and the reasoning is recorded in
   the migration.
7. **Clearing starts on the seller order as a whole**, not per parcel —
   §71's recommended Phase-1 baseline, implemented centrally and tested.

## 54. Remaining blockers before M7

1. **Stripe test-mode network verification** (§51) — carried from M5, still
   the one gate that cannot be closed here, and it should be run before any
   environment takes real cards.
2. **Payouts do not exist.** No Stripe Connect account, no transfer, no
   bank settlement, no payout request execution. `available` is a number a
   seller can see and nothing more, and the dashboard says so.
3. **Negative balances are possible but not managed.** The ledger takes
   them and no constraint prevents them, but nothing recovers a debt: in
   M6 every refund is covered because no money has left. M7 must define
   payout eligibility around net available balance, and what happens when a
   refund lands behind a payout.
4. **Returns and restocking.** A refund never restocks. M7 (or later) needs
   a physical return event before inventory can come back.
5. **Delivery is recorded by people.** There is no carrier feed, and the UI
   and the delivered email both say so. A `ShippingProvider` seam exists in
   `Carriers`; making a carrier webhook authoritative is a new caller of
   `MarkShipmentDelivered`, not a rewrite.
6. **Local Docker** (§49) could not be exercised here; the CI job is the
   only Docker evidence.

---

# Traceability — §83's ninety-eight required behaviours

## Payment/fulfilment boundary

| #  | Behaviour                              | Test                                                                     |
| -- | -------------------------------------- | ------------------------------------------------------------------------ |
| 1  | unpaid seller order cannot be confirmed | `SellerFulfilmentHttpTest::an_unpaid_order_offers_nothing_and_accepts_nothing` |
| 2  | unpaid seller order cannot ship        | `FulfilmentInvariantsTest::an_unpaid_order_cannot_ship`                  |
| 3  | paid seller order becomes actionable   | `SellerFulfilmentHttpTest::a_seller_walks_an_order_from_paid_to_delivered` |
| 4  | payment state separate from fulfilment | `StateMachineTest::fulfilment_is_impossible_before_payment` + `SellerOrderStatus` docblock |

## Confirm / process / pack

| #  | Behaviour                              | Test                                                                     |
| -- | -------------------------------------- | ------------------------------------------------------------------------ |
| 5  | paid → confirmed valid                 | `SellerFulfilmentHttpTest::a_seller_walks_an_order_from_paid_to_delivered` |
| 6  | confirmed → processing valid           | same                                                                      |
| 7  | processing → packed valid              | same (packing is creating the shipment)                                   |
| 8  | invalid transition rejected            | `StateMachineTest::a_shipment_cannot_leave_a_terminal_state`, `FulfilmentInvariantsTest::an_unpaid_order_cannot_ship` |
| 9  | repeated transition adds no history    | `FulfilmentReadSurfaceTest::repeating_a_transition_does_not_repeat_its_history` |

## Shipments

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 10 | seller creates a shipment for their order    | `SellerFulfilmentHttpTest::a_seller_walks_an_order_from_paid_to_delivered` |
| 11 | Seller A cannot create one for Seller B      | `SellerFulfilmentHttpTest::a_seller_cannot_touch_another_sellers_order_or_parcel` |
| 12 | shipment requires a valid quantity           | `SellerFulfilmentHttpTest::a_parcel_cannot_be_sent_without_a_carrier_and_cannot_arrive_before_it_is_sent` |
| 13 | item must belong to the seller order         | `SellerFulfilmentHttpTest::a_seller_cannot_put_another_sellers_item_in_their_own_parcel` |
| 14 | shipment cannot exceed remaining quantity    | `FulfilmentInvariantsTest::two_shipments_racing_for_the_last_unit_cannot_both_have_it` |
| 15 | refunded quantity cannot be shipped          | `RefundFulfilmentIntegrationTest::a_refunded_unit_cannot_be_put_in_a_parcel` |
| 16 | multiple shipments supported                 | `FulfilmentInvariantsTest::a_partly_delivered_seller_order_is_not_delivered` |
| 17 | partial shipment sets PARTIALLY_SHIPPED      | same                                                                 |
| 18 | complete shipment sets SHIPPED               | `SellerFulfilmentHttpTest::a_seller_walks_an_order_from_paid_to_delivered` |
| 19 | concurrent allocation cannot overship        | `FulfilmentConcurrencyTest::the_last_unit_cannot_be_allocated_to_two_parcels` |
| 20 | numbering unique and concurrency-safe        | `FulfilmentConcurrencyTest::two_parcels_cannot_be_given_the_same_number` |

## Tracking

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 21 | carrier stored                              | `SellerFulfilmentHttpTest::a_seller_walks_an_order_from_paid_to_delivered` |
| 22 | tracking stored                             | same                                                                 |
| 23 | tracking change audited                     | `RefundFulfilmentIntegrationTest::tracking_may_be_corrected_before_delivery_and_the_old_value_is_kept` |
| 24 | unauthorised tracking edit denied           | `SellerFulfilmentHttpTest::a_seller_cannot_touch_another_sellers_order_or_parcel` |
| 25 | delivered correction needs permission+reason | `AdminFulfilmentTest::a_delivered_parcels_tracking_is_correctable_only_here_and_only_with_a_reason` |

## Shipped

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 26 | MarkShipmentShipped works                   | `SellerFulfilmentHttpTest::a_seller_walks_an_order_from_paid_to_delivered` |
| 27 | shipping twice has no duplicate effects     | `FulfilmentNotificationTest::a_dispatch_is_announced_once_with_its_tracking` |
| 28 | shipping does not debit inventory again     | `FulfilmentInvariantsTest::shipping_does_not_take_the_stock_off_the_shelf_a_second_time` |
| 29 | customer shipped notification queued once   | `FulfilmentNotificationTest::a_dispatch_is_announced_once_with_its_tracking` |

## Delivery

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 30 | a shipped parcel can be delivered           | `FulfilmentInvariantsTest::delivering_the_same_parcel_twice_records_one_arrival` |
| 31 | an unshipped parcel cannot                  | `SellerFulfilmentHttpTest::a_parcel_cannot_be_sent_without_a_carrier_and_cannot_arrive_before_it_is_sent` |
| 32 | one parcel does not falsely deliver an order | `FulfilmentInvariantsTest::a_partly_delivered_seller_order_is_not_delivered` |
| 33 | all fulfilable delivered → DELIVERED        | same                                                                 |
| 34 | duplicate delivery has no duplicate effects | `FulfilmentInvariantsTest::delivering_the_same_parcel_twice_records_one_arrival` |
| 35 | customer delivered notification once        | `FulfilmentNotificationTest::an_arrival_is_announced_once_and_says_who_recorded_it` |
| 36 | SellerOrderDelivered emitted exactly once   | `FulfilmentReadSurfaceTest::each_fulfilment_event_fires_exactly_once` |

## Refund interaction

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 37 | pre-shipment refund reduces fulfilable      | `RefundFulfilmentIntegrationTest::a_refund_before_shipment_reduces_what_is_left_to_ship` |
| 38 | refunded units cannot be shipped            | `RefundFulfilmentIntegrationTest::a_refunded_unit_cannot_be_put_in_a_parcel` |
| 39 | pre-shipment refund restocks per policy     | `AdminPaymentScreensTest::a_refund_does_not_put_the_stock_back_on_the_shelf` — the policy is "never" (§16/§28 above) |
| 40 | post-shipment refund does not restock       | `RefundFulfilmentIntegrationTest::a_refund_after_shipment_does_not_put_the_goods_back_on_the_shelf` |

## Clearing

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 41 | payment alone keeps the earning PENDING     | `EarningsClearingTest::payment_alone_leaves_the_earning_pending_forever` |
| 42 | delivery moves it to CLEARING               | `EarningsClearingTest::delivery_moves_the_earning_to_clearing_with_the_configured_period` |
| 43 | available_at uses the configured period     | same                                                                 |
| 44 | a seller override works                     | `EarningsClearingTest::a_seller_override_replaces_the_platform_period` |
| 45 | the seven-day default works                 | `EarningsClearingTest::delivery_moves_the_earning_to_clearing_with_the_configured_period` |
| 46 | not available before available_at           | `EarningsClearingTest::the_earning_is_not_available_before_its_date` |
| 47 | the sweep releases it after the deadline    | `EarningsClearingTest::the_sweep_releases_the_money_once_the_period_has_passed_and_is_idempotent` |
| 48 | the sweep is idempotent                     | same                                                                 |
| 49 | concurrent workers cannot duplicate it      | `FulfilmentConcurrencyTest::two_workers_clearing_together_release_the_money_once` |
| 50 | a seller cannot make it available           | `EarningsClearingTest::nobody_but_the_clock_can_make_money_available` |
| 51 | a customer cannot influence clearing        | `CustomerTrackingTest::a_customer_cannot_move_a_parcel`              |
| 52 | clearing uses the snapshot amount           | `EarningsClearingTest::the_amount_released_is_the_snapshot_and_not_a_current_rate` |
| 53 | a commission change does not alter it       | same                                                                 |

## Refunds during clearing

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 54 | partial refund reduces future availability  | `EarningsClearingTest::a_partial_refund_during_clearing_reduces_what_becomes_available` |
| 55 | full refund yields zero net availability    | `EarningsClearingTest::a_full_refund_during_clearing_leaves_nothing_to_spend` |
| 56 | it affects only the correct seller          | `EarningsClearingTest::refunding_one_seller_leaves_the_other_untouched` |
| 57 | the original earning stays in the ledger    | `EarningsClearingTest::a_full_refund_during_clearing_leaves_nothing_to_spend` |
| 58 | the reversal is a separate immutable entry  | `EarningsClearingTest::a_partial_refund_during_clearing_reduces_what_becomes_available` |

## Refund after available

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 59 | it reduces the net available balance        | `EarningsClearingTest::a_refund_after_the_money_became_available_reduces_the_available_balance` |
| 60 | the original is not mutated or deleted      | same                                                                 |
| 61 | no payout functionality is required         | `SellerDashboardTest::the_three_money_states_are_kept_apart` asserts `payoutsAvailable === false` |

## Completion

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 62 | a delivered order completes on policy       | `EarningsClearingTest::the_sweep_releases_the_money_once_the_period_has_passed_and_is_idempotent` |
| 63 | a seller cannot accelerate completion       | `EarningsClearingTest::nobody_but_the_clock_can_make_money_available` |
| 64 | the completion job is idempotent            | `FulfilmentConcurrencyTest::two_workers_clearing_together_release_the_money_once` |
| 65 | a multi-seller parent waits for all sellers | `FulfilmentReadSurfaceTest::a_multi_seller_parent_completes_only_when_every_seller_has` |

## Multi-seller

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 66 | A delivered while B still processing        | `FulfilmentInvariantsTest::one_seller_delivering_does_not_deliver_the_whole_marketplace_order` |
| 67 | A's clearing starts independently           | same                                                                 |
| 68 | B's clearing timestamp is independent       | `EarningsClearingTest::each_seller_clears_on_its_own_delivery_date`  |
| 69 | the customer sees per-seller tracking       | `CustomerTrackingTest::each_seller_appears_as_its_own_delivery`      |
| 70 | A never sees B's shipment                   | `SellerFulfilmentHttpTest::a_seller_cannot_touch_another_sellers_order_or_parcel` |

## RBAC

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 71 | a fulfilment manager may manage a shipment  | `SellerFulfilmentHttpTest::only_the_roles_that_run_a_warehouse_may_move_a_parcel` |
| 72 | a viewer cannot mutate                      | same                                                                 |
| 73 | a finance manager cannot mutate a shipment  | same                                                                 |
| 74 | an admin override requires permission       | `AdminFulfilmentTest::only_the_permission_opens_an_override`         |
| 75 | an admin override requires a reason         | `AdminFulfilmentTest::an_admin_records_a_delivery_with_a_reason_and_it_lands_in_the_history` |
| 76 | Support/Analyst cannot override             | `AdminFulfilmentTest::only_the_permission_opens_an_override`, `::support_reads_fulfilment_without_the_clearing_schedule` |

## Isolation

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 77 | a manipulated shipment id exposes nothing   | `SellerFulfilmentHttpTest::a_seller_cannot_touch_another_sellers_order_or_parcel` |
| 78 | a manipulated seller order number likewise  | same                                                                 |
| 79 | a seller cannot trigger another's clearing  | same (every write is 404 for another seller's order)                 |
| 80 | a customer cannot see another's tracking    | `CustomerTrackingTest::tracking_is_never_reachable_without_signing_in` |

## Read surfaces and UI

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 81 | seller detail uses historical snapshots     | `SellerFulfilmentHttpTest::a_seller_walks_an_order_from_paid_to_delivered` (items come from `FulfilmentQuantities`, which reads order-item snapshots) |
| 82 | customer tracking uses correct groups       | `CustomerTrackingTest::each_seller_appears_as_its_own_delivery`      |
| 83 | admin sees the complete hierarchy           | `AdminFulfilmentTest::the_detail_shows_the_whole_hierarchy_and_the_clearing_schedule` |
| 84 | seller list query count bounded             | `FulfilmentReadSurfaceTest::the_seller_order_list_does_not_grow_a_query_per_order` |
| 85 | seller detail query count bounded           | `FulfilmentReadSurfaceTest::the_seller_fulfilment_detail_does_not_grow_a_query_per_parcel` |
| 86 | customer tracking query count bounded       | `FulfilmentReadSurfaceTest::customer_tracking_does_not_grow_a_query_per_seller` |
| 87 | admin fulfilment query count bounded        | `FulfilmentReadSurfaceTest::the_admin_fulfilment_screens_do_not_grow_a_query_per_row` |
| 88 | seller dashboard query count bounded        | `SellerDashboardTest::the_dashboard_reads_a_bounded_number_of_queries` |

## Events and notifications

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 89 | shipment shipped event once                 | `FulfilmentReadSurfaceTest::each_fulfilment_event_fires_exactly_once` |
| 90 | shipment delivered event once               | same                                                                 |
| 91 | seller-order delivered event once           | same                                                                 |
| 92 | earning-clearing event once                 | `FulfilmentConcurrencyTest::two_deliveries_of_one_parcel_start_one_clock` (clearing runs in the delivery transaction, so once per delivery) |
| 93 | earning-available event once                | `FulfilmentConcurrencyTest::two_workers_clearing_together_release_the_money_once` |
| 94 | duplicate jobs do not duplicate messages    | `FulfilmentNotificationTest::a_dispatch_is_announced_once_with_its_tracking`, `::an_arrival_is_announced_once_and_says_who_recorded_it` |

## SEO and security

| #  | Behaviour                                   | Test                                                                |
| -- | ------------------------------------------- | ------------------------------------------------------------------- |
| 95 | customer order page noindex                 | `CustomerTrackingTest::tracking_is_never_reachable_without_signing_in` |
| 96 | seller fulfilment page noindex              | `SellerOrderScreensTest` (M4, still passing) + CI HTTP smoke        |
| 97 | admin fulfilment page noindex               | `AdminFulfilmentTest::the_screens_are_closed_to_sellers_and_customers` |
| 98 | no unauthenticated tracking by order number | `CustomerTrackingTest::tracking_is_never_reachable_without_signing_in` |

---

## Gate summary

| Gate                                | Result                                    |
| ----------------------------------- | ----------------------------------------- |
| `migrate:fresh --seed`              | pass                                      |
| PHPUnit (full)                      | pass — 1,007 tests, 12,591 assertions     |
| Invariants suite                    | pass                                      |
| PHPStan + Larastan level 8          | pass — 0 errors, no new baseline          |
| Pint                                | pass                                      |
| `tsc --noEmit`                      | pass                                      |
| ESLint                              | pass                                      |
| Prettier                            | pass                                      |
| Client build                        | pass                                      |
| SSR build                           | pass                                      |
| Real Redis / Horizon queue smoke    | pass                                      |
| Full fulfilment lifecycle smoke     | pass (local, real Postgres + Redis)       |
| Multi-seller delivery smoke         | pass                                      |
| Clearing sweep idempotency          | pass                                      |
| Docker CI fulfilment smokes         | added; run on push                        |
| Local Docker                        | **unverified — no daemon**                |
| Stripe test-mode network            | **unverified — carried from M5**          |

M6 is complete for every gate that can be run in this environment. The two
that cannot are named as unverified above rather than reported as passing.

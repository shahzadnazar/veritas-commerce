# M4 completion report — cart, checkout and the multi-seller order

Answers the forty-two questions in the M4 brief, in order, followed by a
traceability table against the eighty required behaviours. Where a gate
could not be run in this environment it says so rather than claiming a
result.

---

## 1. Branch

`claude/veritas-marketplace-architecture-vov8c0`

## 2. Final SHA

`7e1bd30` — the commit carrying this report. The last code commit is
`b9a138467d6c989b88acea0eb8349b4eb037da96`.

## 3. M4 commit range

`4db0a78..7e1bd30` — eleven commits, oldest first. Nothing was force-pushed
and no published history was rewritten.

| SHA       | Subject                                                         |
| --------- | --------------------------------------------------------------- |
| `79b48b7` | fix: rank in-stock offers above out-of-stock ones on every sort |
| `ee9d9da` | feat: implement the persistent cart domain                      |
| `4844220` | feat: carry an anonymous basket across sign-in, record intent   |
| `13b1fba` | feat: add checkout attempts, idempotency, authoritative quote   |
| `ab3c3c0` | feat: split a checkout into a marketplace and seller orders     |
| `8bf41e7` | feat: close the payment-pending boundary in both directions     |
| `77d3548` | feat: build the storefront cart and checkout UI                 |
| `f149a39` | feat: add customer, seller and admin order read surfaces        |
| `4a3f64e` | test: complete the M4 traceability and regression coverage      |
| `b9a1384` | ci: exercise the M4 commerce path on the built image            |
| `7e1bd30` | docs: add the M4 completion report                              |

## 4. Total tests and assertions

**815 tests, 11,083 assertions, all passing.** `php artisan test`, one
run, no skips and no incomplete tests.

## 5. M4-specific tests

**230 tests, 1,071 assertions** across eighteen files:

| Area     | File                          | Tests |
| -------- | ----------------------------- | ----- |
| Cart     | `CartDomainTest`              | 22    |
| Cart     | `CartMergeTest`               | 16    |
| Cart     | `CartSignInTest`              | 9     |
| Cart     | `CartAnalyticsTest`           | 10    |
| Cart     | `CartHttpTest`                | 17    |
| Checkout | `CheckoutQuoteTest`           | 11    |
| Checkout | `CheckoutIdempotencyTest`     | 13    |
| Checkout | `CheckoutConcurrencyTest`     | 3     |
| Checkout | `CheckoutHttpTest`            | 18    |
| Checkout | `CheckoutAnalyticsTest`       | 9     |
| Checkout | `OrderCreationTest`           | 23    |
| Checkout | `PaymentBoundaryTest`         | 15    |
| Orders   | `CustomerOrderScreensTest`    | 17    |
| Orders   | `SellerOrderScreensTest`      | 14    |
| Orders   | `AdminOrderScreensTest`       | 12    |
| Orders   | `OrderSnapshotRegressionTest` | 3     |
| Orders   | `OrderMoneyTest`              | 12    |
| Orders   | `UnpaidOrderExpiryTest`       | 6     |

M4 also extended `ModuleBoundaryTest`, `StatusPresentationTest`,
`PriceSnapshotTest` and `SearchEngineTest` rather than adding parallel
copies of them.

## 6. M0–M3 regression status

**No regressions.** Every pre-existing test still passes. Four earlier
tests were adjusted, each because M4 changed a fact they asserted, never to
make a failure go away:

- `SearchEngineTest` gained a `stock` parameter on its fixtures and three
  new tests, because availability now breaks ties on every sort.
- `PriceSnapshotTest` needed `OrderItemFactory` to derive the commission
  split from whatever line total a test sets, because
  `order_items_split_is_exact` is now a database constraint.
- `StatusPresentationTest` picked up `CartStatus` and `CheckoutStatus`
  automatically — it fails on any unregistered enum, which is what it is
  for.
- `ModuleBoundaryTest` gained justified allowances for `Cart`, `Checkout`
  and `Orders`, each documented inline with the coupling it permits.

## 7. PHPStan / Larastan

**Level 8, zero errors**, with Larastan `3.10.0` loaded (`tools/phpstan.php`
reports `Larastan: yes`; CI fails if it is absent). No baseline entries and
no `@phpstan-ignore` comments were added during M4. Four findings during
the milestone were real and were fixed at the cause: a nullsafe call on a
non-nullable `created_at`, an `instanceof` that could never be false, an
array-shape widening on a model `create()`, and — the useful one — a
string comparison against `CartIssueCode` values that would never have
matched, because the enum's values are uppercase.

## 8. Cart UI

`/cart`, server-rendered, grouped by seller. Each line shows the canonical
product image and title, the selected variant, the seller and store, the
seller's offer price, a quantity stepper, the line subtotal, availability
in words, and a remove action. The page shows the cart subtotal, the unit
and seller counts, and the checkout CTA.

Every figure comes from `BuildCartView`, which re-prices from the live
offer on every read. React adds nothing up. The header count comes from
`CountCart`, one joined query on every storefront page — never from
anything the browser keeps, because a badge showing "3" over a basket a
revalidation emptied is the interface lying to the person using it.

## 9. Checkout UI

`/checkout`, in five sections: contact, delivery address, seller-grouped
review, totals, and the continue action, with validation issues rendered
above the form. The CTA reads **"Continue to payment"**. No card entry, no
provider, no "Pay now".

The form posts an address, an email and an idempotency key. It posts no
money — there is no field for it, which is why
`the_total_cannot_be_influenced_by_anything_in_the_request` can throw
`grand_total_minor`, `unit_price_minor`, `commission_rate_snapshot` and
`quantity` at it and assert the order is unmoved.

## 10. Cart issue UX

All seven codes have a customer-facing reading, written once in
`CheckoutIssueLanguage` so the cart page, the checkout page and a refused
checkout say the same thing:

| Code                  | What the customer reads                                          |
| --------------------- | ---------------------------------------------------------------- |
| `PRICE_CHANGED`       | "Price changed from $50.00 to $55.00 since you added it."        |
| `OUT_OF_STOCK`        | "This item has sold out… Remove it to continue."                 |
| `QUANTITY_REDUCED`    | "Quantity updated because only 3 items are currently available." |
| `OFFER_UNAVAILABLE`   | "This offer is no longer available and cannot be bought."        |
| `SELLER_UNAVAILABLE`  | "The seller of this item is not trading at the moment…"          |
| `PRODUCT_UNAVAILABLE` | "This product has been withdrawn from the marketplace."          |
| `VARIANT_UNAVAILABLE` | "The option you chose is no longer offered for this product."    |
| `CURRENCY_MISMATCH`   | "This item is priced in a different currency…"                   |

Nothing is hidden behind a disclosure. Blocking and advisory are
distinguished by border weight and by explicit text ("action needed" /
"for information"), never by colour — the palette is mono, and a notice
relying on hue would be invisible to the people it most needs to reach.

A sign-in merge stores its issues in the session and `MergeNotice::drain()`
removes them on first read, so the notice appears exactly once whenever the
customer next looks at their cart. Sign-in rarely lands them on it, which
is why a one-request flash would have been wrong.

## 11. Address UX

A signed-in customer picks a saved address or enters a new one, optionally
saving it. A saved address is resolved through the customer's own rows, so
a public id belonging to someone else finds nothing and is refused
(`another_customers_saved_address_cannot_be_used`).

`state` is optional and labelled "State or province (optional)" with the
hint "Leave blank if your country does not use one" — §33. The migration
made `customer_addresses.state` and `marketplace_orders.ship_state`
nullable for the same reason.

No address business logic exists in React. The order copies the values onto
itself at placement and reads its own copy forever after; nothing
downstream of checkout holds a foreign key to the address book, which
`a_deleted_address_book_entry_does_not_change_where_an_order_went` proves
by deleting one.

## 12. Payment-pending page

`/checkout/{reference}/payment`. Shows the order number, the amount, the
item summary grouped by seller, the destination, and the hold expiry. Its
headline is **"Awaiting payment"** and its message is _"Your order has been
prepared, but payment has not yet been completed."_

It is deliberately not a confirmation page, and the CI smoke fails if the
page ever contains "payment successful" or "order confirmed".

Reachable by the customer who placed the order, or — for a guest — through
the cart their session still points at, which is the only durable link a
guest has to it. A reference alone is never enough.

## 13. Customer order-history screens

`/account/orders` lists reference, date, seller count, status badge and
total, as cards at every width. `/account/orders/{reference}` shows the
parent, each seller order with its own reference and status, the items with
their snapshot titles, brands, variants, quantities and prices, the totals
and the shipping snapshot.

Commission is absent from the payload entirely — what the platform took
from the seller is between the platform and the seller.

## 14. Seller order screens

`/seller/orders` lists the seller order number, the parent number, date,
line and unit counts, subtotal and status, with server-side filters on
seller order number, parent number, status and date range.
`/seller/orders/{reference}` shows only this seller's items with their SKU
and variant snapshots, the seller-order totals, the destination address,
and — behind the seller's own `finance.view` permission — the commission
and earning.

The controller never loads the parent's other children. The parent payload
carries `reference`, `status`, `placedAt` and `shippingAddress` and nothing
else, asserted by `missing('parent.grandTotal')`.

## 15. Admin order screens

`/admin/orders` lists reference, customer, date, total, seller-order count
and status, with server-side filters on reference, store name, status and
date. `/admin/orders/{reference}` shows the full hierarchy: parent totals
and shipping snapshot, every seller order with its store, amounts,
commission and earning, every item with its snapshots and commission, the
checkout attempt and its reservation reference, every inventory hold with
its status, any payment attempts, and the parent and child state history.

No refunds, no actions, nothing from a later milestone.

## 16. Read DTO architecture

No Eloquent model is passed to Inertia anywhere in M4.

| Read model                | Where                                                               |
| ------------------------- | ------------------------------------------------------------------- |
| `CartView`                | `Cart\Data\CartView` (+ `CartSellerGroup`, `CartLine`, `CartIssue`) |
| `CheckoutQuote`           | `Checkout\Data\CheckoutQuote`                                       |
| `CheckoutView`            | `Checkout\Queries\BuildCheckoutView`                                |
| `MarketplaceOrderDetail`  | `Orders\Queries\BuildOrderDetail::__invoke`                         |
| `MarketplaceOrderSummary` | `CustomerOrderController::index` row shape                          |
| `SellerOrderDetail`       | `BuildOrderDetail::sellerOrder`                                     |
| `SellerOrderSummary`      | `SellerOrderController::index` row shape                            |
| `AdminOrderDetail`        | `BuildOrderDetail` + attempt, holds, payments, history              |

The TypeScript side is `resources/js/shared/commerce.ts`, written by hand
against these because it is the contract between two languages and a
contract nobody reads is not one. Every money value crosses as both
`formatted` (for the page) and `minor` (so a test asserts on a number
rather than on a string somebody may reformat).

Authorization is separate from presentation throughout: `withFinance` is a
parameter to the read model, decided by the controller from the actor's
permissions, so a page cannot render a field it was not given.

## 17. Customer isolation result

**Pass.** A reference is a lookup key, never an authorization: every
customer query is scoped by `user_id` first, and a foreign reference 404s
rather than 403s — a 403 confirms the order exists, which is itself
something a stranger should not learn.

Proved by `another_customers_order_is_a_404_not_a_403`,
`a_guessed_reference_from_the_sequence_finds_nothing` (which asserts the
references are consecutive, and still refused),
`a_guest_order_is_not_reachable_by_a_signed_in_stranger`,
`another_customers_payment_page_is_not_reachable`, and by the live CI smoke
hitting both URLs directly with another customer's cookie jar.

## 18. Seller isolation result

**Pass**, on four separate attack shapes:

- Direct URL to another seller's order → 404
  (`a_seller_cannot_open_another_sellers_order_by_reference`).
- The parent reference as a route → 404
  (`the_parent_reference_does_not_open_the_whole_order`).
- The parent-reference filter as a side channel → empty page, not a hit
  (`filtering_by_another_sellers_parent_reference_returns_nothing`).
- The order-number search → empty
  (`searching_for_another_sellers_order_number_returns_nothing`).

Plus the structural guarantee: on a two-seller order the seller's list has
one row while the database has two, and the detail controller never loads
the sibling at all. All four are repeated in the CI Docker smoke against
the built image.

## 19. Unpaid seller-order policy

Locked: a `pending_payment` seller order is **visible for traceability and
not actionable**. The payload carries
`fulfilment.actionable = false` with the reason _"This order cannot be
packed or shipped until payment is confirmed."_, and the page renders that
as a status block rather than as unexplained greyed-out controls.

Asserted server-side even though no fulfilment buttons exist yet
(`an_unpaid_seller_order_is_visible_but_not_actionable`), so the milestone
that adds them cannot add them unguarded. The flag flips only after
`MarkOrderPaid` (`a_paid_seller_order_becomes_actionable`).

## 20. Snapshot presentation result

**Pass on all three surfaces.** `OrderSnapshotRegressionTest` places an
order, then moves the offer price (9,900 → 25,000), the product title, the
product slug, the seller SKU, the store name and the commission rate
(12% → 30%), and reads the customer, seller and admin screens back. All
three still show the original title, SKU, store name, unit price, rate and
split. A separate test moves the product into a new category with a 25%
category-scoped rule and the historical commission does not repoint.

`BuildOrderDetail` reads only `order_items`, `seller_orders` and
`marketplace_orders`. It has no relationship to an offer, product or store,
which is what makes the guarantee structural rather than careful.

## 21. Price-change handling

A price that moved is reported and does not block: the customer sees the
new authoritative figure and the old one, and may proceed at the new price.
Refusing outright would strand every basket a seller repriced.

Between quote and order creation the rule hardens: `PlaceOrder` recomputes
the quote and refuses if the grand total differs from what the attempt
recorded — **in either direction**. Cheaper is still not what the customer
agreed to, and quietly charging less is quietly charging the seller
(`a_price_that_moved_downward_stops_the_order_too`).

No stale price is ever sent back as authoritative. `unit_price_at_add_minor`
is display history only.

## 22. Checkout idempotency result

**Pass.** The key is `UNIQUE` in PostgreSQL rather than checked in PHP, so
two simultaneous requests cannot both find nothing and both proceed — the
loser catches the violation and reads the winner's attempt. An
already-decided attempt returns before any quote is computed or any stock
is touched, and quote and reservation share one transaction, so no state
exists in which an attempt has no holds.

Proved sequentially (`the_same_key_returns_the_same_attempt_and_reserves_once`),
over two connections (`CheckoutConcurrencyTest`), through HTTP
(`submitting_the_same_idempotency_key_twice_makes_one_order`), at
placement (`placing_the_same_attempt_twice_returns_the_same_order`), in the
analytics (`a_replayed_checkout_does_not_record_a_second_order`), and in
the CI Docker smoke.

A key presented for another customer's cart is refused outright rather than
answered: returning the first attempt would hand one customer another's
order.

## 23. Reservation and expiry result

**Pass.** A cart reserves nothing — intent is not a hold, and reserving on
add would let abandoned baskets strangle a seller's stock
(`adding_to_a_cart_reserves_nothing`, `merging_reserves_nothing`). The hold
is taken at checkout under `lockForUpdate` in ascending `offer_id` order,
and quantities are summed per offer so the customisation seam cannot hold
one offer twice.

Two sweeps close what the inventory sweep alone would leave behind:
`ExpireUnpaidOrders` cancels the order and its children and releases their
holds inside one transaction, and `ExpireCheckoutAttempts` closes checkouts
abandoned before an order existed. Both are scheduled every minute with
`withoutOverlapping`.

`UnpaidOrderExpiryTest` runs every sweep twice and asserts one release, one
movement, `on_hand` untouched, availability restored, no sale, no ledger
entry — and that the released unit is immediately buyable by the next
customer. The same scenario runs in CI against the built image.

## 24. Multi-seller split result

**Pass.** One checkout → one marketplace order → one seller order per
seller, numbered `-01`, `-02` by the seller's position in a grouping that
is sorted by seller id, so the same basket always numbers the same way
whatever order the customer added things in
(`seller_orders_are_numbered_in_the_same_order_the_cart_grouped_them`).
Two listings from one seller are one seller order.

## 25. Financial reconciliation result

**Pass, and enforced by the database.** Three check constraints added in
`2026_05_01_000200_close_the_order_arithmetic`:

- `seller_orders_total_is_exact` — items + shipping + tax − discount = total
- `marketplace_orders_total_is_exact` — the same at parent level
- `seller_orders_commission_split_is_exact` — commission + earning = items

plus `order_items_split_is_exact` from the M4 schema migration. The parent
is additionally reconciled against the sum of its children inside the
placement transaction, so a discrepancy fails the placement rather than
surfacing months later in a payout.

Tests assert the identities hold on awkward prices AND that the constraints
are the authority: `reconciliation_is_the_databases_rule_not_the_applications`
updates a total directly and expects the constraint name in the error.

## 26. Commission rounding rule and result

**Rule:** commission is `round-half-up` on `line_total × rate` computed in
basis points as integers; the seller's earning is the **remainder**, never
computed independently. The two therefore sum to the line exactly by
construction.

Verified end to end through real checkouts on six awkward prices, including
1p (commission 0), 9,973 (1,197), exactly-half 1,250 (150) and
99,999 × 7 = 699,993 (83,999). Also asserted deterministic — the same price
must not split two different ways — and reconciling exactly across a
three-seller order.

No display-time reconciliation exists anywhere; every figure a page shows
was computed once and stored.

## 27. Shipping policy

Charged **per seller order**, because that is what a marketplace ships: two
sellers are two parcels. The rate is
`veritas.checkout.shipping_per_seller_order_minor`, **zero by default** — a
rate card belongs to the sellers, and inventing one in the platform would
be a guess hard-coded into it.

The UI labels the policy actually in force: "Delivery included" at zero,
"Delivery charged once per seller" otherwise, with the note "Each seller
ships separately". `delivery_is_quoted_once_per_seller` asserts 2 × 499 for
two sellers; `two_lines_from_one_seller_are_one_shipping_charge` asserts
one.

## 28. Tax placeholder and contract

**M4 runs no tax engine.** `taxTotal` is zero and the UI renders "Not
calculated" with the note _"Tax is not calculated at this stage."_ — never
a bare "$0.00", which would read as a calculated figure the platform cannot
stand behind. No US tax assumption exists anywhere; `state` is optional at
every level.

The columns (`tax_total_minor`, `tax_amount_minor`, `tax_rate_snapshot`,
`tax_source`) are carried through the order schema and included in the
reconciliation identity, so a real engine populates them without a
migration.

## 29. Analytics events

Six event types fire, each from exactly one place. The cart actions
announce a domain event and know nothing about analytics; the Events
module's listener translates. `CheckoutStarted`,
`CheckoutValidationFailed` and `CheckoutOrderCreated` are recorded by the
checkout controller, which is their only caller.

| Event                        | Fires from                  | Carries                       |
| ---------------------------- | --------------------------- | ----------------------------- |
| `cart_item_added`            | `CartLineAdded` listener    | offer, product, seller, value |
| `cart_item_removed`          | `CartLineRemoved` listener  | offer, product, seller, value |
| `cart_quantity_changed`      | `CartLineQuantityChanged`   | from, to, offer, value        |
| `checkout_started`           | `CheckoutController::show`  | line count                    |
| `checkout_validation_failed` | `CheckoutController::store` | refusal reason                |
| `checkout_order_created`     | `CheckoutController::store` | reference, order value        |

`CheckoutAnalyticsTest` drives the real routes and asserts **exact counts**,
which is the point: no event is recorded twice for one semantic action,
stepping a quantity is not a second add, stepping to zero is a removal and
not a change, a refused checkout records no order event, and a replayed
checkout records one. Every cart event carries the offer, because which
seller at which price is the whole question a ranking model has to answer
later.

Verified live: the real Redis worker drained the jobs and wrote the rows
(`cart_item_added n=2`, `checkout_started n=1`,
`checkout_order_created n=1 value=9000`, backlog 0).

## 30. Audit events

Deliberately unchanged. Order state transitions are recorded in
`order_status_history` — append-only, one row per transition with an actor
type — for both the parent and each child, which the admin screen renders.
The behavioural stream stays separate from the audit log, per M3's §48: an
audit table that also carried every anonymous cart add stops being the
place you look to answer "who cancelled this order, and why".

## 31. Query-count results

Every screen asserts that its cost does not grow with its contents. Each
test compares a one-item page against a three-item page and requires the
**same** count:

| Screen                   | Test                                                         |
| ------------------------ | ------------------------------------------------------------ |
| Cart page                | `the_cart_page_costs_a_fixed_number_of_queries`              |
| Checkout page            | `the_checkout_page_costs_a_fixed_number_of_queries`          |
| Customer order list      | `the_order_list_does_not_load_an_aggregate_per_row`          |
| Customer order detail    | `the_order_screens_cost_a_fixed_number_of_queries`           |
| Seller order list/detail | `the_seller_screens_cost_a_fixed_number_of_queries`          |
| Admin order list/detail  | `the_admin_screens_cost_a_fixed_number_of_queries`           |
| Cart merge               | `a_merge_costs_a_fixed_number_of_queries_however_many_lines` |

Achieved with dedicated read queries, not by loading a large graph:
`BuildOrderDetail` takes three queries for an order of any size, list pages
count children in SQL rather than hydrating an aggregate per row, and
`BuildCartView` takes two lookups for availability and eligibility across
all lines.

## 32. Noindex results

All nine transactional and private paths carry `X-Robots-Tag: noindex,
nofollow`, verified live against the running application and asserted in
five tests plus the CI smoke:

`/cart`, `/checkout`, `/checkout/{ref}/payment`, `/account/orders`,
`/account/orders/{ref}`, `/seller/orders`, `/seller/orders/{ref}`,
`/admin/orders`, `/admin/orders/{ref}`.

A header rather than only a meta tag, because a header reaches a crawler
even when SSR is misconfigured — precisely the moment an accidental index
of somebody's address would happen. The path list lives once in
`Indexability::privatePaths()`.

Confirmed not over-applied: `/` carries no robots header at all and
`/search` keeps its existing `noindex, follow`.

## 33. Queue and Horizon results

**Run against real Redis with a real worker**, not a fake.

- Analytics jobs dispatched by live HTTP requests were picked up and
  written by `queue:work redis --queue=critical,default`; backlog drained
  to `critical=0 default=0`.
- The three sweep jobs were dispatched onto Redis **twice** and executed by
  the worker: the order and its seller order went to `cancelled`, the
  reservation to `released`, one release movement, zero sale movements,
  zero ledger entries, zero failed jobs, queues drained.
- Horizon itself was not started locally (the CI job runs
  `horizon:status` plus `queues:smoke` against the built image); the worker
  used here consumes the same Redis queues Horizon supervises.

## 34. HTTP smoke results

Run against the real built application (`php artisan serve` on the
production bundle, SSR enabled), not controller tests. All steps passed:

storefront home; `/cart` anonymous; `/checkout` redirect on an empty
basket; guest redirects from all three order surfaces; the noindex header;
customer sign-in; add to basket; the basket rendering the product, seller
and `$90.00`; `/checkout`; the checkout POST producing `VC-1` and
redirecting to its payment page; the payment page's wording; the order
list and detail; the seller list and detail with the unpaid-order notice;
the seller's 404 on the parent reference; a second seller's 404 and empty
filter; a second customer's 404 on both the order and the payment page;
admin sign-in with a real TOTP code; `/admin/orders` and
`/admin/orders/VC-1` returning the full hierarchy with commission,
checkout attempt, holds and history.

## 35. SSR smoke results

The real SSR bundle (`bootstrap/ssr/ssr.js`) served every transactional
page. Verified in the first response, with the `data-page` JSON stripped so
only genuine markup was inspected:

| Page                     | `<title>` | SSR markup | Rendered `<h1>`    |
| ------------------------ | --------- | ---------- | ------------------ |
| `/cart`                  | 1         | yes        | "Your basket"      |
| `/checkout`              | 1         | yes        | "Checkout"         |
| `/account/orders`        | 1         | yes        | "Your orders"      |
| `/account/orders/VC-1`   | 1         | yes        | "VC-1"             |
| `/checkout/VC-1/payment` | 1         | yes        | "Awaiting payment" |

A populated cart rendered its product title, store name, `$90.00`, the
quantity control's `aria-label`, the remove button, the header count and
the checkout CTA — all in server markup, so no hydration mismatch. Exactly
one `<title>` per page; shared props present throughout.

The two operating portals do not use SSR by design (`config/inertia.php`:
"Storefront only"), so their pages are client-rendered and were verified
through their Inertia payloads instead.

## 36. Client build, type and lint results

- `npm run build` — clean.
- `npm run build:ssr` — clean, `ssr.js` 117.34 kB.
- `npx tsc --noEmit` — clean, strict with `noUncheckedIndexedAccess` and
  `exactOptionalPropertyTypes`.
- `npx eslint resources/js --max-warnings=0` — clean.
- `npx prettier --check` — clean.

One ESLint finding during M4 was real and was fixed properly: the quantity
stepper reset derived state inside an effect, which causes a cascading
render. It now adjusts during render, so a corrected quantity does not
visibly flicker back.

## 37. PostgreSQL clean migration result

`php artisan migrate:fresh --seed --force` ran clean from an empty
database, including both M4 migrations, followed by
`veritas:seed-demo-catalogue`. Four constraints are asserted **by name** — a test writes a violating row
directly and expects the constraint in the error, so the claim is that
PostgreSQL refuses it rather than that the application chose not to:
`seller_orders_total_is_exact`, `seller_orders_commission_split_is_exact`,
`marketplace_orders_total_is_exact` and
`marketplace_orders_money_is_not_negative`. The supersession of M0's plain
unique on `carts.session_token` is asserted behaviourally instead
(`a_retired_cart_does_not_block_the_browsers_next_one`), and reference
uniqueness by `the_reference_of_every_order_is_unique`.

## 38. Docker local result

**Unverified — environment limitation.** The Docker daemon is not
available in this environment; `docker info` returns only the client
section with no server. No image was built or started here, and this report
makes no claim that one was.

## 39. Docker CI result

**Configured and extended; not executed here.** The `docker` job now has
twenty steps. M4 added three, placed after the M3 discovery smokes:

1. _A customer can fill a basket and reach a payment-pending order_ —
   seeds through the application's own models, drives the real routes, and
   asserts the SSR-rendered basket, the payment-pending wording (including
   that it never claims success), stock held rather than sold, the order
   detail, and idempotency on resubmission.
2. _A seller sees only their half, and nobody else reaches it_ — the four
   isolation attack shapes plus the noindex header on all seven private
   paths.
3. _The unpaid order gives its stock back, once_ — the sweeps run twice
   against real order data with every figure asserted.

The job was re-read for masked failures. It does not mask any: seed output
goes to a file before it is read (piping into `grep` would discard the exit
status — a trap this job hit once before and documents inline), `curl -fsS`
fails on non-2xx, and every assertion is `test`/`grep -q` or an explicit
negation. The workflow parses as valid YAML, and both new fixture scripts
plus every string the new steps grep for were executed locally against the
running application first.

## 40. Bugs uncovered while finishing M4

Six, all found by tests or gates rather than by inspection:

1. **`line_identity` was `varchar(64)`** — a customised line hashes to
   `h:` plus a 64-character digest, 66 characters. The extensibility seam
   would have broken the first time anybody used it. Widened to 96.
2. **M0's plain unique on `carts.session_token`** made a cart lifecycle
   impossible: a browser whose cart was merged could never start another,
   because the retired row held the token forever. Dropped in favour of the
   partial index that keeps the rule that matters — one _live_ cart per
   browser.
3. **`marketplace_orders.ship_state` was `NOT NULL`** — the same US-shaped
   assumption already fixed on `customer_addresses`.
4. **`OrderItemFactory` left a stale commission split** when a test set its
   own line total, which the new `order_items_split_is_exact` constraint
   correctly rejected. The factory now derives the split from whatever
   total it ends up with.
5. **`ConsumeReservation` attributed every movement to one seller order.**
   A multi-seller order's holds share one reference but the sales belong to
   different sellers; the movements would have been misattributed in every
   report downstream. Added `attributed()`, which maps offer → seller order.
6. **A string comparison against `CartIssueCode` values that could never
   match** — the enum's values are uppercase. PHPStan caught it; it would
   have silently disabled the stock-issue filter at order placement.

## 41. Intentional deviations from the M4 prompt

Four, each stated rather than quietly taken:

1. **A second migration rather than editing the first.**
   `2026_05_01_000200_close_the_order_arithmetic` adds the attempt email
   and the three reconciliation constraints. The M4 schema migration was
   already pushed and had run in CI; editing a migration others have run is
   a schema-history hazard.
2. **Seller commission visibility is gated on `finance.view`**, not shown
   to every seller role. §13 says "if seller-facing policy allows"; a
   warehouse account that packs boxes has no reason to see the platform's
   cut, and the role matrix already draws that line elsewhere.
3. **`MarkOrderPaid` exists.** It is the other half of the payment-pending
   invariant — without it nothing proves a hold ever _becomes_ a sale. It
   takes no money, contacts no provider and has no route; M5 calls it from
   a verified provider confirmation.
4. **The seller portal's non-member response is 404, not a redirect**,
   matching the portal's established policy rather than introducing a
   second one for orders.

## 42. Remaining blockers before M5

None blocking. Four things M5 inherits:

1. **No provider is wired.** `PaymentGateway` is bound to
   `FakePaymentGateway`; `payment_attempts` has its idempotency key and
   `checkout_attempt_id` but no rows are created yet. M5 creates the
   attempt, calls the provider, and on confirmation calls `MarkOrderPaid`.
2. **`purchase_completed` never fires.** It is reserved for a captured
   payment, deliberately, and is asserted absent at order creation.
3. **The seller ledger is untouched by M4** — zero entries after a
   placement, asserted. Earnings become available only after capture and
   the clearing period.
4. **Docker remains locally unverified.** The CI job is the only place the
   built image is exercised; if a daemon becomes available the same three
   steps can be run locally unchanged.

---

## Traceability — the eighty required M4 behaviours

Classification: **PASS** (explicit automated coverage) ·
**PASS (equivalent)** (covered by a stronger test, cited) ·
**N/A** (technically inapplicable, explained). No behaviour is MISSING.

### Cart (1–14)

| #   | Behaviour                                             | Status | Evidence                                                                                       |
| --- | ----------------------------------------------------- | ------ | ---------------------------------------------------------------------------------------------- |
| 1   | Anonymous cart created and owned by session           | PASS   | `adding_an_offer_creates_a_cart_bound_to_the_session`                                          |
| 2   | Authenticated cart owned by the user                  | PASS   | `an_authenticated_customer_resolves_their_own_cart_not_the_browsers`                           |
| 3   | One active cart per user                              | PASS   | `resolving_twice_returns_the_same_cart_rather_than_a_second_one`                               |
| 4   | One active cart per browser token                     | PASS   | `a_retired_cart_does_not_block_the_browsers_next_one`                                          |
| 5   | Cart line references the seller offer                 | PASS   | `a_cart_line_points_at_a_seller_offer_not_a_product`                                           |
| 6   | Line identity merges duplicates                       | PASS   | `adding_the_same_offer_twice_combines_into_one_line`                                           |
| 7   | Database refuses a duplicate line                     | PASS   | `the_database_refuses_a_duplicate_line_identity`                                               |
| 8   | Line identity extensible to customisation             | PASS   | `line_identity_leaves_room_for_future_customisation`                                           |
| 9   | Ineligible offer refused (seller/offer/product/store) | PASS   | 4 tests in `CartDomainTest` + `a_suspended_sellers_offer_is_refused_with_a_message_not_a_code` |
| 10  | Manipulated offer id refused                          | PASS   | `a_made_up_offer_id_is_refused_rather_than_erroring`                                           |
| 11  | Stock limit on add                                    | PASS   | `a_quantity_beyond_stock_is_refused_and_names_what_is_left`                                    |
| 12  | Stock limit on update                                 | PASS   | `a_quantity_update_goes_through_the_server_and_is_capped_by_stock`                             |
| 13  | Quantity update and line removal                      | PASS   | `quantity_can_be_updated_and_a_line_removed`, `a_line_can_be_removed`                          |
| 14  | **Cart reserves no inventory**                        | PASS   | `adding_to_a_cart_reserves_nothing`                                                            |

### Cart isolation and merge (15–24)

| #   | Behaviour                                      | Status | Evidence                                                                                                |
| --- | ---------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------- |
| 15  | One cart's line cannot be touched from another | PASS   | `a_line_identity_from_another_session_cannot_be_touched`                                                |
| 16  | Anonymous cart adopted on sign-in              | PASS   | `a_basket_filled_before_signing_in_survives_signing_in`                                                 |
| 17  | Two carts merged on sign-in                    | PASS   | `the_two_carts_are_merged_when_the_customer_already_had_one`                                            |
| 18  | Identical lines combine on merge               | PASS   | `the_same_offer_in_both_carts_becomes_one_line_with_the_combined_quantity`                              |
| 19  | Combined quantity capped at stock              | PASS   | `a_combined_quantity_larger_than_stock_is_capped_and_reported`                                          |
| 20  | Merge never exceeds available inventory        | PASS   | `a_merge_never_puts_more_in_the_cart_than_the_seller_has`                                               |
| 21  | Unavailable offer not carried over             | PASS   | `an_offer_that_became_unavailable_is_not_carried_over`, `an_archived_offer_is_dropped_by_the_same_rule` |
| 22  | Merge issues surfaced to the customer          | PASS   | `the_customer_is_told_afterwards_what_the_merge_could_not_honour` + cart-page notice                    |
| 23  | Browser token dropped after sign-in            | PASS   | `the_browser_token_is_dropped_once_there_is_an_account_behind_it`                                       |
| 24  | Registration merges too                        | PASS   | `registering_carries_the_basket_across_too`                                                             |

### Cart revalidation (25–32)

| #   | Behaviour                                        | Status | Evidence                                                                                                      |
| --- | ------------------------------------------------ | ------ | ------------------------------------------------------------------------------------------------------------- |
| 25  | Cart re-priced from the live offer               | PASS   | `the_view_prices_from_the_live_offer_not_from_what_was_added`                                                 |
| 26  | Price change reported, non-blocking              | PASS   | `a_price_change_is_reported_rather_than_hidden`, `a_price_change_is_reported_but_does_not_block_the_checkout` |
| 27  | Suspended seller reported, blocking              | PASS   | `the_cart_page_blocks_when_a_seller_stops_trading`                                                            |
| 28  | Stock reduction reported                         | PASS   | `stock_running_out_under_a_cart_is_reported`                                                                  |
| 29  | Sold-out reported                                | PASS   | `selling_out_entirely_is_its_own_issue`                                                                       |
| 30  | Deterministic seller grouping                    | PASS   | `lines_are_grouped_by_seller_deterministically`                                                               |
| 31  | Cart read is not an N+1                          | PASS   | `reading_a_cart_costs_the_same_whether_it_holds_one_line_or_eight`                                            |
| 32  | Cart survives an archived offer without erroring | PASS   | `an_unavailable_listing_does_not_break_an_old_order` (order side), cart side via #27                          |

### Checkout (33–46)

| #   | Behaviour                                | Status | Evidence                                                          |
| --- | ---------------------------------------- | ------ | ----------------------------------------------------------------- |
| 33  | Valid checkout produces an order         | PASS   | `a_checkout_produces_a_payment_pending_order_and_holds_the_stock` |
| 34  | Address validated                        | PASS   | `a_missing_address_is_a_field_error_not_a_crash`                  |
| 35  | Address with no state accepted           | PASS   | `an_address_with_no_state_is_accepted`                            |
| 36  | Saved address usable                     | PASS   | `a_signed_in_customer_can_use_a_saved_address`                    |
| 37  | Another customer's saved address refused | PASS   | `another_customers_saved_address_cannot_be_used`                  |
| 38  | Totals are server-authoritative          | PASS   | `the_quote_is_computed_from_the_live_offer_not_from_the_cart`     |
| 39  | Posted price/commission ignored          | PASS   | `the_total_cannot_be_influenced_by_anything_in_the_request`       |
| 40  | Quantity tampering ignored               | PASS   | same test (posts `quantity=999`)                                  |
| 41  | Checkout reserves inventory              | PASS   | `starting_a_checkout_records_an_attempt_and_holds_the_stock`      |
| 42  | Reservation failure is atomic            | PASS   | `a_refusal_holds_no_stock_at_all`                                 |
| 43  | Idempotent on the same key               | PASS   | `the_same_key_returns_the_same_attempt_and_reserves_once`         |
| 44  | Duplicate submission makes one order     | PASS   | `submitting_the_same_idempotency_key_twice_makes_one_order`       |
| 45  | Expired attempt cannot become an order   | PASS   | `an_expired_attempt_cannot_become_an_order`                       |
| 46  | Empty cart cannot check out              | PASS   | `an_empty_cart_cannot_start_a_checkout`                           |

### Concurrency (47–52)

| #   | Behaviour                                  | Status            | Evidence                                                                                                                    |
| --- | ------------------------------------------ | ----------------- | --------------------------------------------------------------------------------------------------------------------------- |
| 47  | Competing checkouts for the last unit      | PASS              | `two_checkouts_racing_for_the_last_unit_leave_the_ledger_exact`                                                             |
| 48  | Stock taken between quote and hold refuses | PASS              | `stock_taken_between_the_quote_and_the_hold_refuses_rather_than_oversells`                                                  |
| 49  | Deterministic lock ordering (no deadlock)  | PASS (equivalent) | `ReserveStock` sorts by `offer_id`; `MergeCarts::lockInOrder`; proved under two connections by `ReservationConcurrencyTest` |
| 50  | Idempotency race resolved by the database  | PASS              | `the_database_refuses_a_second_attempt_under_the_same_key`, `a_request_that_loses_the_race_returns_the_winners_attempt`     |
| 51  | Order-number uniqueness                    | PASS              | `the_reference_of_every_order_is_unique`                                                                                    |
| 52  | Oversell impossible at the ledger          | PASS (equivalent) | `the_database_refuses_an_oversell_even_if_the_application_tries` (M3 invariant, unchanged)                                  |

### Orders (53–64)

| #   | Behaviour                                                       | Status | Evidence                                                                    |
| --- | --------------------------------------------------------------- | ------ | --------------------------------------------------------------------------- |
| 53  | One checkout → one marketplace order                            | PASS   | `one_checkout_across_two_sellers_produces_one_order_with_two_seller_orders` |
| 54  | One seller order per seller                                     | PASS   | same                                                                        |
| 55  | Two lines from one seller → one seller order                    | PASS   | `two_lines_from_one_seller_are_one_seller_order`                            |
| 56  | Numeric suffix on seller orders                                 | PASS   | `seller_orders_are_numbered_within_their_parent`                            |
| 57  | Deterministic seller ordering                                   | PASS   | `seller_orders_are_numbered_in_the_same_order_the_cart_grouped_them`        |
| 58  | Immutable price snapshot                                        | PASS   | `repricing_the_offer_afterwards_leaves_the_order_untouched`                 |
| 59  | Immutable commission snapshot                                   | PASS   | `every_item_carries_its_own_immutable_price_and_commission_snapshot`        |
| 60  | Descriptive snapshots (title, brand, store, slug, SKU, variant) | PASS   | same + `every_order_surface_reads_the_snapshot_after_the_world_moves`       |
| 61  | Snapshot cannot be rewritten                                    | PASS   | `the_order_item_snapshot_cannot_be_rewritten`                               |
| 62  | Order begins payment-pending                                    | PASS   | `the_order_begins_pending_payment_and_still_only_holds_its_stock`           |
| 63  | Placement recorded in history                                   | PASS   | `the_placement_is_recorded_in_the_append_only_history`                      |
| 64  | Cart converted, attempt completed                               | PASS   | `the_attempt_and_the_cart_are_closed_by_a_successful_order`                 |

### Finance (65–70)

| #   | Behaviour                                     | Status | Evidence                                                                                                                                                  |
| --- | --------------------------------------------- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 65  | Seller order rolls up from its items          | PASS   | `a_seller_orders_totals_are_rolled_up_from_its_items`                                                                                                     |
| 66  | Parent equals the sum of children             | PASS   | `the_parent_totals_equal_the_sum_of_the_children`                                                                                                         |
| 67  | Reconciliation enforced by constraint         | PASS   | `the_database_refuses_a_seller_order_whose_totals_do_not_add_up`, `..._commission_split_...`, `reconciliation_is_the_databases_rule_not_the_applications` |
| 68  | Commission rounding deterministic and exact   | PASS   | `the_split_is_deterministic_and_exact` (6 cases), `the_split_on_the_same_price_is_the_same_every_time`                                                    |
| 69  | Multi-seller reconciliation on awkward prices | PASS   | `a_multi_seller_order_reconciles_exactly_on_awkward_prices`                                                                                               |
| 70  | One currency per order                        | PASS   | `every_order_is_written_in_one_currency`                                                                                                                  |

### Payment boundary and expiry (71–76)

| #   | Behaviour                                  | Status | Evidence                                                      |
| --- | ------------------------------------------ | ------ | ------------------------------------------------------------- |
| 71  | Hold becomes a sale in one movement        | PASS   | `payment_turns_the_hold_into_a_sale_in_one_movement`          |
| 72  | Payment webhook delivered twice sells once | PASS   | `a_payment_webhook_delivered_twice_sells_the_units_once`      |
| 73  | Unpaid order releases its stock            | PASS   | `an_unpaid_order_gives_its_stock_back_when_the_window_closes` |
| 74  | Sweep is idempotent (run twice)            | PASS   | `the_whole_unwind_happens_once_however_many_times_it_runs`    |
| 75  | Paid order never swept                     | PASS   | `a_paid_order_is_never_swept`                                 |
| 76  | Cancelled order cannot be marked paid      | PASS   | `a_cancelled_order_can_never_be_marked_paid`                  |

### Security, events and SEO (77–80)

| #   | Behaviour                          | Status | Evidence                                               |
| --- | ---------------------------------- | ------ | ------------------------------------------------------ |
| 77  | Customer order isolation           | PASS   | 4 tests (§17) + live CI smoke                          |
| 78  | Seller order isolation             | PASS   | 4 tests (§18) + live CI smoke                          |
| 79  | Unpaid seller order not actionable | PASS   | `an_unpaid_seller_order_is_visible_but_not_actionable` |
| 80  | Transactional pages are noindex    | PASS   | 5 tests + live verification of all nine paths          |

### Additional coverage beyond the eighty

Admin RBAC separation (`finance_columns_need_the_sensitive_permission`,
`support_can_read_an_order_without_seeing_the_commission`,
`a_role_without_orders_view_is_refused_outright`), cross-realm denial
(customer and seller sessions against `/admin/orders`), analytics
non-duplication (nine tests), snapshot presentation on all three read
surfaces, and query-count assertions on all eight screens.

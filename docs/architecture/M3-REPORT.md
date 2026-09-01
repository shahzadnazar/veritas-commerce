# M3 completion report — inventory, discovery and search

Answers the forty-one questions in the M3 brief, in order. Where a gate
could not be run in this environment it says so rather than claiming a
result.

---

## 1. Branch and final SHA

- Branch: `claude/veritas-marketplace-architecture-vov8c0`
- Final SHA: `168d51a8624ad88ae4e7d5337865e232fe94e29e`

M3 commits, oldest first:

| SHA       | Subject                                                             |
| --------- | ------------------------------------------------------------------- |
| `ceae03b` | Make the Docker smoke fixture a command rather than a shell heredoc |
| `a1bfd59` | Make inventory a double-entry ledger over on_hand and reserved      |
| `9d9a458` | Expire abandoned holds and warn sellers once, not repeatedly        |
| `5041477` | Add seller and platform inventory management                        |
| `2a4fb09` | Implement marketplace search on PostgreSQL                          |
| `289547f` | Build customer discovery: search, category and store listings       |
| `4bc60fc` | Add sitemaps, search analytics and truthful availability            |
| `168d51a` | Complete the M3 test requirements and CI gates                      |

Nothing was force-pushed and no published history was rewritten.

## 2. Package/version changes

**None.** `composer.json`, `composer.lock`, `package.json` and
`package-lock.json` are byte-identical to their M2 state.

Two PostgreSQL extensions are enabled by migration rather than by hand, so
CI and every developer database get them without a setup step:

- `pg_trgm` — trigram similarity, for typo tolerance.

Unchanged and load-bearing: Laravel `13.29.0`, PHP `8.4.19`, PostgreSQL 16,
Redis 7, `laravel/horizon` `v5.48.3`, PHPStan `2.2.12` with Larastan
`3.10.0`, Node 22.

## 3. Docker validation status

**Not validated locally.** The Docker daemon is unavailable in this
environment (`Cannot connect to the Docker daemon at
unix:///var/run/docker.sock`), so no image was built or started here and
this report makes no claim that one was.

Per §0's second branch, the CI job was read and hardened instead. It now
covers, in order: build both images; start Postgres and Redis; install
Composer and npm dependencies; build the SSR bundle; start the SSR process
and the application; `migrate:fresh --seed`; HTTP smoke across the
storefront and both portals; the security-header set; a real product page
with its SEO markup; the M3 discovery surfaces (search, typo tolerance,
autocomplete, category, store); the indexability policy on clean and
faceted URLs; all four sitemaps and `robots.txt`; `inventory:reconcile`
against the built image; an explicit SSR health check; PostgreSQL
reachability from inside the application container; `horizon:status` plus
`queues:smoke`; the failed-jobs table; and teardown.

Two problems were found in that configuration and fixed:

- The product-page smoke seeded its fixture with a `tinker --execute`
  heredoc piped through `tr`, which takes the pipeline's exit status
  instead of tinker's — a seeding failure surfaced as a confusing curl
  error three steps later rather than a failed step. It is now
  `veritas:seed-demo-catalogue`, a command that exits properly, is covered
  by tests, and refuses to run in production.
- The job asserted SSR only through page output, and Postgres only
  implicitly through a successful migration. Both are now checked directly.

`M3QueueRuntimeTest` additionally asserts the pipeline's own content — that
`queues:smoke`, `horizon:status`, `build:ssr` and the SSR service are still
in it — because a gate that quietly stopped running is worse than one that
never existed.

## 4. Inventory schema changes

One migration, `2026_04_01_000100_harden_inventory_ledger`:

| Change                                                                      | Why                                                                                                                                                                  |
| --------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `inventory_balances.reserved` (integer, default 0)                          | Was derived by summing live reservations on every read. Discovery needs availability for every card on a page, which a correlated SUM turns into a query per result. |
| `inventory_balances.available` — **generated column**, `on_hand - reserved` | The formula exists once, in the schema. Every reader — PHP, a discovery query, a psql session — gets the same answer by construction.                                |
| `inventory_balances.notified_state`, `notified_at`                          | Remembers the last stock state a seller was actually told about, so a warning is not re-sent on every save.                                                          |
| `inventory_movements.change` → `on_hand_change`                             | There are two quantities now; the column has to say which.                                                                                                           |
| `inventory_movements.reserved_change`, `resulting_reserved`                 | Double entry: every movement carries a delta for both quantities and stamps both results.                                                                            |
| `inventory_reservations.opened_by_movement_id`, `closed_by_movement_id`     | The hold and its ledger entries reconcile from either direction.                                                                                                     |
| `offers.low_stock_threshold`, `stores.default_low_stock_threshold`          | Per-offer override, per-store default; both nullable so "inherit" and "never warn me" stay distinguishable.                                                          |

## 5. Inventory balance rules

`available = on_hand - reserved`, computed by PostgreSQL. The rules, each
enforced by a CHECK constraint rather than by domain code alone:

- `inventory_on_hand_not_negative` — `on_hand >= 0`
- `inventory_reserved_not_negative` — `reserved >= 0`
- `inventory_reserved_within_on_hand` — `reserved <= on_hand`
- `inventory_movement_changes_something` — a movement that moves neither
  quantity is refused
- `reservations_quantity_is_positive`
- `reservations_resolution_is_dated` — `(status = 'held') = (resolved_at is null)`

`available` cannot go negative because the other three hold. A manual
adjustment cannot invalidate an active reservation: taking stock below what
is already reserved violates `inventory_reserved_within_on_hand`, and the
domain refuses it first with a sentence a seller can read.

`StockLevel` is the one representation every surface consumes, and
`StockState` (`in_stock` / `low_stock` / `out_of_stock`) the one enum — the
seller portal, the storefront, the search index and the structured data all
read it, so nothing re-derives "is it low" from a number and a threshold.

Tests write raw SQL to prove each constraint, because a rule that only
holds when the application is polite is not a rule.

## 6. Movement types

`InventoryMovementReason`, split by who writes it.

Seller-selectable: `opening_stock`, `restock_received`, `count_correction`,
`damaged`, `lost`, `returned_to_supplier`, `manual_edit`, `other`.

Platform: `admin_adjustment`.

System: `order_reservation`, `reservation_release`, `reservation_expired`,
`sale_completed`, `order_cancelled`, `refund_restock`.

There is deliberately **no** `MANUAL_INCREASE` / `MANUAL_DECREASE` pair.
Direction is the sign of the movement, and a row saying only "manual
increase" answers none of the questions an audit asks — "received 20" and
"wrote off 3 as damaged" are both manual and mean entirely different
things. `other` additionally requires a written note, because the code
explains nothing on its own. A form post cannot claim a system reason: a
sale is written by the code that performs one.

Reservation movements change `reserved` and leave `on_hand` alone. They are
movements all the same, because "why did available drop when nothing sold"
is a question the ledger has to answer.

## 7. Reservation lifecycle

```
ReserveStock      held      reserved += q,  movement: order_reservation
ReleaseReservation released reserved -= q,  movement: reservation_release
ExpireReservations expired  reserved -= q,  movement: reservation_expired
ConsumeReservation consumed on_hand -= q AND reserved -= q, one movement
```

A sale is one entry against both columns rather than two, so availability
cannot flicker upward between writes and let a concurrent reservation take
the stock.

Reservations carry offer, location, quantity, `reference`, `expires_at`,
status, `created_at`, `resolved_at` and the two movement pointers. A single
`resolved_at` rather than separate `released_at`/`committed_at`: the status
already says which happened, and two nullable timestamps that can disagree
with it would be redundant state to keep in step.

Every operation is idempotent, and it comes from the data rather than from
the caller: only rows still `held` are selected, FOR UPDATE. Releasing the
same reference three times restores stock once and writes one ledger entry.

Nothing is wired to a cart. §6 asks for domain APIs M4 can use, and these
are them.

## 8. Reservation concurrency strategy

PostgreSQL is the authority. Redis holds no part of the decision — an
in-memory counter that disagreed with the database would oversell precisely
when it mattered.

Three mechanisms, in depth order:

1. **Row locks.** Balance rows are locked `FOR UPDATE` before availability
   is read, so two simultaneous checkouts cannot both see the last unit.
2. **Deterministic lock order.** Locks are taken in ascending `offer_id`,
   so two carts holding the same offers in different sequences cannot
   deadlock.
3. **A CHECK constraint.** `reserved <= on_hand` does not depend on anybody
   remembering to lock.

`ReservationConcurrencyTest` proves it with two genuinely separate database
connections, stepped by hand so the dangerous interleaving is the one
exercised. That required `DatabaseTruncation` rather than `RefreshDatabase`
— an uncommitted transaction is invisible to any other session, so a
concurrency test using it could never fail.

## 9. Low-stock implementation

Thresholds resolve **offer → store → platform default**. Null means
inherit; zero means "I do not want the warning" — a real choice a zero
default would erase.

Events fire on a crossing, never on a level: something that fired while
stock merely _is_ low would mail the seller on every save. They are raised
from `RecordMovement`, the single choke point every stock change passes
through — an adjustment, a hold, a release, a sale — because announcing
from anywhere else means the index learns about availability from only some
of the paths that move it.

`InventoryLow`, `InventoryDepleted`, `InventoryRestored`, plus
`InventoryAdjusted` on every movement for reindexing.

## 10. Seller inventory screens

- **`/seller/inventory`** — every listing, ordered by lowest availability
  first. A stock list sorted by name makes you hunt for the problem; one
  sorted by what is about to run out puts it on the first row. Search by
  product or SKU, filter by stock state.
- **`/seller/inventory/{offer}`** — availability, on hand and reserved as
  three figures, the full movement history with reason, actor, note and
  resulting balance, an opening-stock form before there is any history, an
  adjustment form after it, and the low-stock threshold.

A platform adjustment appears in this history marked as the platform's, and
a test asserts the seller can see it there.

## 11. Admin inventory visibility

`/admin/inventory` and `/admin/inventory/{offer}` — every seller's stock in
one place, worst first, with seller, store, listing, availability and
recent movements. Operational visibility, not warehouse management: the
question it answers is "why can nobody buy this".

A platform adjustment requires the `inventory.adjust` permission and a
written reason of at least five characters, is recorded as
`admin_adjustment` against the admin who made it, is audited, and is
visible to the seller.

## 12. Inventory RBAC

**Seller** (M1's matrix, unchanged and now tested against §46):

| Role                                    | inventory.view | inventory.manage |
| --------------------------------------- | -------------- | ---------------- |
| Owner, Administrator, Inventory manager | yes            | yes              |
| Catalog manager                         | yes            | **no**           |
| Fulfilment manager, Viewer              | yes            | no               |
| Finance manager                         | no             | no               |

A catalogue manager lists products and does not count them, which is
exactly what §46 asks for. A suspended seller keeps every read and loses
every write.

M1's two capabilities were kept rather than split into a third
`inventory.adjust`: every role holding `inventory.manage` would also hold
it, so it would be ceremony. The substantive requirement — that catalogue
rights do not carry stock rights — is met and tested.

**Platform**, three new capabilities:

| Role                                                   | inventory.view | inventory.adjust | inventory.audit |
| ------------------------------------------------------ | -------------- | ---------------- | --------------- |
| Super admin, Marketplace admin                         | yes            | yes              | yes             |
| Seller operations, Catalog moderator, Support, Analyst | yes            | no               | no              |
| Finance admin                                          | no             | no               | no              |

§47 exactly: Support and Analyst read stock and can never change it.

## 13. Search abstraction implementation

`SearchIndex` is the port, unchanged in shape from M2 and widened by three
methods: `index()`, `forget()`, `search()`, `query(SearchQuery)` and
`suggest()`. `PostgresSearchIndex` is the only implementation. No
controller and no catalogue action names it — swapping in OpenSearch is a
second class and a binding change.

`IndexableProductSource` remains the other direction: the catalogue
describes its own products, so the index never becomes a second, quietly
diverging definition of what a product is.

`SearchQuery` and `SearchResults` are engine-agnostic value objects.
Crucially, a `SearchQuery` is **already validated** when it reaches an
adapter, so no engine ever has to defend itself against a hostile URL.

## 14. PostgreSQL search strategy and indexes

One denormalised document per product, carrying everything a results page
would otherwise join back for. Ten indexes:

| Index                                  | Serves                                                         |
| -------------------------------------- | -------------------------------------------------------------- |
| `..._vector` (GIN, tsvector)           | Relevance. **Weighted**: title A, brand B, category C, rest D. |
| `..._title_trgm` (GIN, `gin_trgm_ops`) | Typo tolerance.                                                |
| `..._ancestors` (GIN, bigint[])        | Category pages including descendants, as one containment test. |
| `..._conditions` (GIN, text[])         | Condition filter and facet.                                    |
| `..._identifiers` (GIN, text[])        | Exact barcode lookup.                                          |
| `..._attributes` (GIN, jsonb)          | Category-defined attribute filters.                            |
| `..._discovery`                        | `(is_public, in_stock, lowest_price_minor)`.                   |
| `..._newest`                           | `(is_public, published_at desc)`.                              |
| `..._brand`                            | `(is_public, brand_id)`.                                       |
| `inventory_balances_available`         | Availability per offer, for the eligibility join.              |

The vector is a generated column, so it cannot fall out of step with the
text it summarises. No `LIKE '%term%'` over an unindexed table anywhere.

## 15. Search ranking rules

Deterministic and explainable, as a ladder of ORDER BY terms rather than a
blended score — so "why is this first" always has a one-line answer:

1. Exact identifier match. Someone who typed a barcode knows what they want.
2. Exact normalised title match.
3. Title prefix.
4. `ts_rank` over the weighted vector.
5. `word_similarity` — the misspelling still finds something.
6. In stock above out of stock.
7. Product id, so equal results never reorder between requests.

No ML. No sponsored placement: a customer's organic results are not for
sale in M3. Relevance falls back to newest when there is no query, because
ordering a browse page by "relevance to nothing" is an arbitrary order
presented as a considered one.

## 16. Typo and fuzzy handling

`word_similarity(query, title) > 0.3`, over the trigram index.

`similarity()` was tried first and is wrong here: it compares whole
strings, so "iphnoe" against "iphone 15 pro" scores 0.17 — diluted by the
words the customer never typed — and no threshold separates that from
noise. `word_similarity` finds the best-matching run inside the title:

| Query  | Target                   | `similarity` | `word_similarity` |
| ------ | ------------------------ | ------------ | ----------------- |
| iphnoe | iphone 15 pro            | 0.17         | **0.43**          |
| samsng | samsung galaxy s24       | 0.25         | **0.57**          |
| samsng | cast iron casserole dish | —            | 0.00              |
| iphnoe | cast iron casserole dish | —            | 0.14              |

A test asserts the casserole does not come back, because typo tolerance
that matched it would be worse than none.

## 17. Search filters and facets

Filters: category (including descendants), brand, price range, condition,
availability, and any attribute a moderator marked filterable on that
category. §22's requirement is honoured literally — the filter list is
generated from the category's attribute definitions, never hardcoded in
React, and an attribute with no option list is not offered as a checkbox
rather than offered as a control that does nothing.

Facet counts respect the active context and are computed **minus their own
filter**: a brand facet counted with the brand filter still applied shows
one brand and a column of zeroes, which is a dead end rather than a choice.
Three grouped queries — `unnest` turns the condition array into rows so one
query counts every value. No N+1 faceting.

## 18. Autocomplete

`GET /search/suggestions?q=` — JSON, rate limited to 60/minute, minimum two
characters, at most eight rows, mixing products, brands and categories.
Each suggestion carries its type and destination, so the dropdown can send
a brand to a filtered search and a product straight to its page.

It reads the same index the results page does and filters on `is_public`,
so it cannot suggest something still in moderation — asserted by a test
that puts a draft product in the catalogue and searches for it.

## 19. Category discovery

`/categories/{slug}` is the same engine, cards, facets and sorting as
search, with the category fixed by the route. Descendants are included by
array containment against a GIN index rather than a recursive walk per
request. Breadcrumbs come from the stored path in one query. Child
categories are offered for navigation, hidden ones are not, and a hidden
category is a 404 rather than an empty page.

## 20. Seller-store product discovery

`/stores/{slug}` now lists the seller's own products — the M1 shell is
filled. The seller scope is applied inside the query, not filtered
afterwards, so another seller's offer has no path into the listing. A
product several sellers carry appears, once, in each of their stores; a
suspended offer leaves its seller's store immediately.

## 21. ProductCard / discovery DTO architecture

`SearchHit` (engine) → `ProductCard` (presentation) → one React
`ProductCard` component used by search, category and store pages.

The card computes nothing. Price string, price range, availability and
stock label all arrive decided by the server, so a card and the product
page it links to cannot quote different numbers — and a test asserts
exactly that. Commerce photography stays in full colour.

## 22. Offer-ranking integration

`OfferRankingService` remains the single policy. The product page features
the offer it ranks first; the card shows the lowest eligible price, which
is the same offer. A test drives three sellers at 89.00, 120.00 and 150.00
and asserts the card and the featured offer both land on 89.00 — a
customer clicking a price must not find a different one.

## 23. Out-of-stock public policy selected

**A published product keeps its page, its ranking and its search presence
when it runs out, and says plainly that it cannot be bought.**

The URL, the accumulated authority and the history are worth more than the
momentary stock level, and a 404 on a temporary condition throws all three
away. So: the product page returns 200 with `inStock: false`, search
returns it with `stockState: out_of_stock` and ranks it below buyable
results, and the structured data says `OutOfStock`. A customer who wants
only what they can buy today has the availability filter.

Implemented once and asserted on every surface, so the four pages cannot
disagree.

## 24. Behavioural events implemented

`search_performed` (with result count and `zero_results`),
`search_result_clicked` (with position), `product_viewed`,
`category_viewed`, `seller_store_viewed`.

Every write is queued on the lowest-priority lane. §34 is explicit that
analytics must not block a customer, and a slow insert would show up as a
slow search page — the one place latency is most visible.

Anonymous sessions are a random ULID in the session, generated on first
need. No fingerprinting, no IP hashing, no cross-site identifier. A
signed-in customer carries both identities, which is what stitches
behaviour from before signing in to behaviour after.

Analytics stay out of `audit_logs`, per §48, and a test asserts it: an
audit table that also carries every anonymous product view stops being the
place you look to answer who suspended a seller and why.

## 25. Search analytics implemented

`/admin/catalogue/search-health` — four figures (searches, zero-result
searches, result clicks, click rate) and two lists (most searched, searched
and found nothing), over a configurable window.

Read straight from the event stream. No rollup table and no scheduled
aggregation: at this scale the raw events answer these in milliseconds, and
a pipeline built before there is anything to put through it is a pipeline
to maintain and nothing else.

## 26. SEO and indexability policy

One class, `Indexability`, rather than scattered meta tags — because the
arithmetic demands it. Five brands, four price bands, three sorts and ten
pages is six hundred URLs for one category, all showing subsets of the same
products.

| URL                             | robots            | canonical          |
| ------------------------------- | ----------------- | ------------------ |
| `/categories/kettles`           | `index, follow`   | itself             |
| `/categories/kettles?brand[]=1` | `noindex, follow` | the clean category |
| `/categories/kettles?page=2`    | `noindex, follow` | the clean category |
| `/stores/x` (open)              | `index, follow`   | itself             |
| `/stores/x` (closed)            | `noindex, follow` | itself             |
| `/search?q=…`                   | `noindex, follow` | `/search`          |
| `/products/…` (listed)          | `index, follow`   | itself             |

"follow" throughout: refusing to index a page is not a reason to strand the
products it links to.

Search additionally sends `X-Robots-Tag: noindex, follow` as a header. The
meta tag only reaches a crawler when SSR is running, and a misconfigured
SSR process is precisely when an accidental index of the whole search space
would happen.

One bug the live smoke caught: the clean category page was returning
`noindex`, because the category constraint counted as a customer-applied
filter. On `/categories/kettles` the category is the page's identity; in
`/search?category=kettles` it is a filter. The query now knows which.

## 27. Sitemap implementation

An index at `/sitemap.xml` plus `/products-sitemap.xml`,
`/categories-sitemap.xml` and `/stores-sitemap.xml`. A single flat file
works today and would have to be split the first time the catalogue
outgrows fifty thousand URLs; splitting now costs one route and makes
growth a pagination change.

Products are read from the search index, because that already encodes
exactly one thing — whether the public may see it. A second hand-written
definition of "eligible" is how a sitemap ends up listing products the
storefront 404s. A test walks every URL the product sitemap emits and
asserts each one resolves.

Excluded: search, admin, the seller portal, drafts, suspended products,
hidden categories, closed stores and stores whose seller is suspended.
`robots.txt` is generated rather than static, so the sitemap location
cannot drift from the routes serving it. Cached for 10–15 minutes: a crawl
should not become a load test.

## 28. Structured-data changes

Availability now comes from the inventory ledger. M2 could only say "a
seller lists this" because there was no stock model to ask; there is now,
and a page that claims `InStock` and then cannot fulfil is how a
marketplace earns a manual penalty.

- Product page: `Product` + `Offer` or `AggregateOffer`, availability from
  real stock across eligible offers.
- Category page: `BreadcrumbList` from the real lineage.
- Still no `aggregateRating`, no `review`, no `ratingValue` — asserted by a
  test that greps the emitted JSON, because no review module exists.

## 29. Redis and cache usage

| Used for                                       | Not used for                                  |
| ---------------------------------------------- | --------------------------------------------- |
| Queues and Horizon (six queues)                | Inventory truth — PostgreSQL is authoritative |
| Sitemap URL lists (10 min)                     | Seller financial data                         |
| Sitemap responses (15 min via `Cache-Control`) | Availability, which must never be stale       |
| Sessions and the anonymous session id          |                                               |

Availability is deliberately uncached. It is denormalised into the search
document instead, and a stock change reindexes — a cache TTL on
availability is exactly how a storefront ends up selling what it does not
have.

## 30. Audit events

`inventory.adjusted` — for opening stock, seller adjustments and platform
adjustments alike — carrying actor type and id, the offer, the reason code,
the signed change, the before/after `on_hand`, and any written note.
Platform adjustments always carry a reason.

Reservation holds and releases are recorded in the movement ledger rather
than the audit log: they are system operations at cart scale, and flooding
the audit table with them would bury the decisions it exists to record.
Behavioural analytics stay in `interaction_events`.

## 31. Notifications

`StockLevelChanged`, queued on `emails`, sent to members whose role can
manage inventory — a finance manager cannot restock and does not need it.

The policy is **"worse than the last thing we told you"**. Firing on the
transition alone is not enough to stop the spam §11 warns about, because a
customer adding and abandoning a cart walks an offer between low and empty
on a loop. The balance remembers the last state the seller was told about —
a durable guard that survives a queue retry and a deploy — and an
improvement is recorded silently, which re-arms the warning for the next
genuine decline.

## 32. Exact migrations and constraints

Two migrations:

- `2026_04_01_000100_harden_inventory_ledger`
- `2026_04_01_000200_build_search_documents`

Constraints and indexes added:

| Object                                     | Guarantees                            |
| ------------------------------------------ | ------------------------------------- |
| `inventory_on_hand_not_negative`           | stock never goes negative             |
| `inventory_reserved_not_negative`          | a hold cannot be released twice       |
| `inventory_reserved_within_on_hand`        | never promise units nobody holds      |
| `inventory_movement_changes_something`     | no empty ledger rows                  |
| `reservations_quantity_is_positive`        | a hold is for something               |
| `reservations_resolution_is_dated`         | status and timestamp cannot disagree  |
| `inventory_balances.available` (generated) | one definition of availability        |
| `inventory_balances_available` (index)     | availability per offer, for discovery |
| 9 search-document indexes                  | see §14                               |

`migrate:fresh --seed` runs clean on an empty database.

## 33. Exact automated test count and assertion count

**582 tests, 9,980 assertions, all passing.** The invariant suite is 74
tests and 959 assertions of that total and runs first, on its own.

M3 added 146 tests:

| Suite                            | Tests |
| -------------------------------- | ----- |
| `SearchEngineTest`               | 22    |
| `InventoryLedgerTest`            | 18    |
| `DiscoveryPageTest`              | 17    |
| `InventoryAccessTest`            | 16    |
| `StockAdjustmentTest`            | 15    |
| `SearchSecurityTest`             | 13    |
| `InteractionEventTest`           | 12    |
| `SitemapTest`                    | 10    |
| `ReservationExpiryTest`          | 9     |
| `M3QueueRuntimeTest`             | 6     |
| `StructuredDataAvailabilityTest` | 5     |
| `ReservationConcurrencyTest`     | 3     |
| `DemoCatalogueSeedTest`          | 3     |

All 79 cases named in §49 are covered. Every M0/M1/M2 test still passes;
none was weakened or removed.

## 34. PHPStan / Larastan result

`php tools/phpstan.php analyse` — **level 8 with Larastan, 0 errors**, with
`checkModelProperties: true`. One documented ignore, carried from M2 and
unchanged. No baseline, no silenced namespaces.

Pint: passing across `app`, `config`, `database`, `routes`, `tests`.

## 35. Frontend gates

| Gate                                                                              | Result     |
| --------------------------------------------------------------------------------- | ---------- |
| `tsc --noEmit` (strict, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`) | clean      |
| ESLint `--max-warnings=0`                                                         | clean      |
| Prettier `--check`                                                                | clean      |
| Client production build                                                           | succeeds   |
| SSR production build                                                              | succeeds   |
| `statuses:export --check`                                                         | up to date |

## 36. Search and inventory smoke results

Run locally against PostgreSQL 16 with a seeded catalogue:

| Check                                                                | Result                             |
| -------------------------------------------------------------------- | ---------------------------------- |
| Relevance — exact title outranks partial                             | pass                               |
| Relevance — barcode outranks text                                    | pass                               |
| Fuzzy — "iphnoe" finds iPhone, "samsng" finds Samsung                | pass                               |
| Fuzzy — unrelated products stay out                                  | pass                               |
| Filters — price, brand, category, condition, attribute, availability | pass                               |
| Sorts — relevance, price asc/desc, newest                            | pass                               |
| Autocomplete — answers, excludes drafts, bounded to 8                | pass                               |
| Zero results — handled, suggestion offered, event recorded           | pass                               |
| `inventory:reconcile` after seeding                                  | 3 balances, ledger and holds agree |
| `inventory:reconcile` after reserve/expire/sale cycle                | agrees                             |

## 37. Queue results

| Check                                                       | Result                            |
| ----------------------------------------------------------- | --------------------------------- |
| `queues:smoke` across all six queues, real Redis            | all drained                       |
| `ExpireReservations` through Redis, worked by a real worker | stock returned                    |
| Expiry sweep run three times                                | one restoration, one ledger entry |
| `ReindexProduct` through Redis                              | document written                  |
| `ReindexProduct` twice                                      | one document                      |
| `RecordInteractionEvent` on the `default` queue             | written, and never on `critical`  |
| Retry and failure behaviour (M2 suite)                      | 5/5                               |

## 38. SSR / SEO smoke results

Run locally with the built SSR bundle serving on 13714:

| Check                                         | Result                                        |
| --------------------------------------------- | --------------------------------------------- |
| Product page `<title>` count                  | exactly 1                                     |
| Product canonical                             | 1, pointing at the product                    |
| Product JSON-LD                               | 2 blocks — Product and BreadcrumbList         |
| Product availability                          | `InStock` with stock, `OutOfStock` without    |
| `offerCount` / `lowPrice`                     | 3 / "99.00", matching the database            |
| `aggregateRating` / `review`                  | absent                                        |
| Category page title / robots / BreadcrumbList | 1 / `index, follow` / present                 |
| Category faceted (`?in_stock=1`, `?page=2`)   | `noindex, follow`, canonical to the clean URL |
| Store page title / products rendered          | 1 / present                                   |
| Search `X-Robots-Tag` header                  | `noindex, follow`                             |
| Search meta robots under SSR                  | `noindex, follow`                             |
| `/sitemap.xml`, three sitemaps, `/robots.txt` | 200, correct contents                         |

## 39. Docker result

**Not run.** See §3. No Docker claim is made for this environment; CI is
where the stack is proven, and its configuration was verified and extended.

## 40. Bugs uncovered during M3 and fixes

1. **The CI seed masked its own failures** — piping `tinker --execute`
   through `tr` takes the pipeline's exit status. Replaced with a real,
   tested command.
2. **`similarity()` cannot do typo tolerance on multi-word titles** — it
   compares whole strings and dilutes the match. Switched to
   `word_similarity`.
3. **`CategoryFactory` never set `path`**, which `ancestorIds()` reads, so
   every factory-built category tree was silently flat and any test about
   inheritance or descendants was measuring nothing.
4. **A freshly created `InventoryBalance` read `reserved` as null** until
   refreshed, taking every quantity computed from it with it. Model
   defaults now mirror the schema.
5. **The clean category page was `noindex`** — the route's own category
   counted as a customer-applied filter.
6. **`ModelNotFoundException` extends `RuntimeException`** (carried from
   M2's pattern into the new controllers) — resolved lookups moved outside
   `try` blocks so another seller's id is a 404, not a validation message.
7. **A test fixture wrote `on_hand` straight onto the balance**, building a
   state the application cannot produce — stock with no movement explaining
   it, correctly rejected by `inventory:reconcile`. Fixtures now go through
   the domain.
8. **The concurrency tests committed real rows** and `DatabaseTruncation`
   only cleans before a test, so they handed their data to whatever ran
   next and failed it somewhere unrelated.
9. **Event fakes were installed before fixtures**, so tests counting stock
   events were measuring the fixture's own opening stock.
10. **A signed-in customer hitting a seller route returns 404, not 403** —
    the M1 decision, since "you are not allowed here" would confirm that
    "here" exists. The test now asserts the real behaviour.
11. **Availability was not reindexed on stock changes** — the document
    carries availability precisely so results pages avoid a per-card
    inventory query, which only works if the inventory event invalidates
    it.

## 41. Remaining blockers before M4

None blocking. Open items, in the order they will matter:

1. **Docker is unverified outside CI.** Unchanged from M2, and the first
   thing to do on a machine with a daemon.
2. **Reservations are not wired to a cart.** By design — §6 asks for domain
   APIs M4 can use, and `ReserveStock`, `ReleaseReservation` and
   `ConsumeReservation` are them, with expiry and concurrency already
   proven. M4 supplies the reference and the checkout around it.
3. **Multi-location inventory is schema-ready and single-location in
   practice.** One default location per seller, resolved in exactly one
   place, so a second warehouse is a data change rather than a migration.
4. **Search relevance has not been tuned against real queries.** The
   ranking is deliberately explainable rather than optimal; the
   zero-result list on the search-health page is where the evidence to
   tune it will come from.
5. **Facets do not include price histograms or attribute counts.** Brand,
   condition and availability are counted; attribute facets render their
   option lists without counts, which is honest but less useful.
6. **The homepage is unchanged.** §33 asked not to fabricate best-sellers
   or trending without data, and there is still no purchase history — so
   nothing was invented there.
7. **The buy CTA remains inert** until the cart exists in M4.

# M8 completion report — trust, recommendations and marketplace insight

Answers the questions in the M8 brief, in order. Where a gate could not be
run in this environment it says so rather than claiming a result.

---

## 1. Branch and final SHA

`claude/veritas-marketplace-architecture-vov8c0`.

Nothing was force-pushed and no published history was rewritten. The M7
finance semantics at `3ea0dc8` are untouched: `net_balance` is still
signed, `raw_payout_capacity` is still `min(available, net) - reserved`
and may be negative, `withdrawable_balance` is still `max(0, capacity)`
and never is, and `isNegative()` and `isShort()` remain distinct. The
"already-settled payout is not subtracted again" fix is intact. No M8
code reads or writes any of it — see §2 below.

## 2. M8 commit range

`3ea0dc8..HEAD`.

| SHA       | Subject                                                        |
| --------- | -------------------------------------------------------------- |
| `5815840` | feat: implement verified canonical product reviews              |
| `9b94859` | feat: build the rule-based recommendation service and projections |
| `055e6a8` | feat: build the daily analytics projections and analytics:rebuild |
| (this)    | feat: build the M8 surfaces, moderation and dashboards          |

Four commits rather than the brief's suggested split, for the same reason
M7 used four: each milestone piece has an invariant that only holds when
its whole domain is present. A commit adding review moderation without the
rating recomputation that runs inside the same transaction would be a
commit where the visible rating and the JSON-LD could disagree — which is
the failure the milestone exists to prevent. Each commit is internally
complete and its own tests pass at that commit.

## 3. Package/version changes

**None.** No dependency was added, removed or upgraded. `composer.json`,
`composer.lock`, `package.json` and `package-lock.json` are byte-identical
to M7.

§44's exclusions are therefore satisfied by construction rather than by
policy: there is no OpenAI client, no vector database, no pgvector
extension, no TensorFlow or PyTorch, no GPU workload, no recommendation
microservice, no Kafka and no feature store in the dependency tree,
because nothing was added to it.

## 4. M0–M7 regression result

**Preserved.** Every M0–M7 test still passes, and none was weakened.

Two existing files were **adapted**, both to widen rather than narrow:

1. `ModuleBoundaryTest` — the declared module list gained `Analytics`,
   `Recommendations`, `Reviews` and `Customers`, and four allowance
   entries were added. The allowances are additions to a list the test
   asserts exactly; nothing previously forbidden became permitted for a
   module that already existed.
2. `ReviewStructuredDataTest` and `BuildsReviewableOrders` — extended with
   the multi-buyer fixture and the individual-review markup cases. No
   existing assertion was removed or relaxed.

## 5. The six pre-UI properties (§88)

All six were proved before any dashboard, shelf or review form existed.
Each is a test, not a claim.

| # | Property | Where |
| - | -------- | ----- |
| 1 | A customer cannot manufacture a verified review without purchase and delivery evidence | `ReviewInvariantsTest`, `ReviewSurfaceTest::a_client_claiming_verified_purchase_is_ignored` |
| 2 | One canonical product has ONE rating aggregate, however many sellers offer it | `ReviewInvariantsTest`, CI `rating offers=… summaries=1` |
| 3 | A hidden or rejected review stops affecting the public rating immediately | `ReviewInvariantsTest`, `AdminModerationTest::hiding_a_review_needs_a_reason_and_takes_it_off_the_rating` |
| 4 | Recommendations never duplicate a canonical product because several sellers offer it | `RecommendationInvariantsTest::a_product_offered_by_five_sellers_appears_once` |
| 5 | Recommendations never return a public-ineligible product | `RecommendationInvariantsTest::every_strategy_chain_passes_through_the_same_gate` |
| 6 | Analytics and recommendation rebuilds cannot alter transactional or financial truth | `AnalyticsBoundaryTest`, `RecommendationRebuildTest`, `AnalyticsProjectionTest` |

Property 4 is worth expanding on, because it is enforced by *type* rather
than by a check. `RecommendedProduct` carries no seller id and no offer
id. A shelf that showed one product five times would have to be five rows
with the same `productId`, and `EligibleRecommendationProducts` collapses
candidates to one row per product before anything is rendered. There is no
code path from a strategy to a page that skips it.

Property 6 is enforced three ways at once: `AnalyticsBoundaryTest` proves
statically that the insight modules import no models, call no foreign
domain actions, take no write locks and write only their own six derived
tables; the rebuild tests fingerprint every protected table either side of
a run; and both commands ship a `--verify` flag that does the same
fingerprinting in production and exits non-zero if anything moved.

## 6. Reviews belong to canonical products (§3)

`product_reviews.product_id` references `products`. There is no
`offer_id` column and no `seller_account_id` column on the table.

`order_item_id` and `seller_order_id` *are* stored, and it is worth being
clear why that is not a contradiction: they are the **evidence** that the
review is verified, not the subject of it. A review of a kettle stays a
review of the kettle when the seller who shipped it delists — the rating
does not move, the review does not disappear, and no seller's page ever
claims it. `ReviewEvidence::toArray()` deliberately omits both, so the
evidence never travels out to a client that has no business with it.

## 7. Verified purchase is unfakeable (§4)

By construction rather than by validation, which is the stronger of the
two.

`SubmitReview::__invoke()` takes exactly:

```php
(int $userId, int $productId, int $rating, string $body,
 ?string $title = null, ?ReviewActor $actor = null)
```

There is no `verified` parameter, no `status` parameter, no `orderItemId`
parameter. A hostile client that posts `verified_purchase=true` sends a
field the validator does not accept and the action has nowhere to put.
`ReviewInvariantsTest` asserts that parameter list by reflection, so the
property survives a future edit that adds one absent-mindedly.

The badge is decided by `ReviewEligibility`, a single joined query over
`order_items → seller_orders → marketplace_orders → payments` that
requires all five things at once: the order is the customer's, the payment
is captured or partially refunded, the seller order is delivered or
completed, the line is not fully refunded, and there is no live review
already. `ReviewEvidence` has a **private constructor** with two named
factories — `verified()` and `refused()` — so a `ReviewEvidence` claiming
a verified purchase cannot be constructed except by that query returning
a qualifying line.

`ReviewSurfaceTest::a_client_claiming_verified_purchase_is_ignored` posts
every field a hostile client might try — `verified_purchase`, `status`,
`order_item_id`, `seller_order_id`, `user_id`, `published_at` — and
asserts the stored row took none of them.

## 8. One live review per customer per product (§6)

Enforced in the database, not in the UI.

```sql
create unique index product_reviews_one_live_per_customer
    on product_reviews (user_id, product_id)
    where status <> 'withdrawn';
```

A partial index, because a customer who withdraws a review should be able
to write another one, and a plain unique index would have locked them out
forever. `ReviewStatus::isLive()` returns exactly the statuses the index
covers, and the invariant suite asserts the two agree — a status added to
one and forgotten in the other would silently let a customer hold two
reviews at once.

## 9. Rating is an integer 1–5 (§7)

```sql
alter table product_reviews
    add constraint product_reviews_rating_is_one_to_five
    check (rating between 1 and 5);
```

Asserted directly against the database, with each attempt wrapped in its
own savepoint — the first violation aborts the surrounding transaction in
PostgreSQL, so a naive loop reports `25P02` for every attempt after the
first rather than the constraint name.

## 10. No unnecessary approval queue (§8)

A verified review publishes immediately. `ReviewStatus::Published` is an
entry state; there is no `Pending` and no `Flagged` case at all.

That is a deliberate product decision, and the reasoning is worth
recording: an approval step for every honest review delays all of them by
a working day to catch the rare abusive one, and a marketplace whose
reviews arrive a day late is one whose reviews nobody trusts to be
current. The admin surface is a *review* queue — what is already live,
and what should not be — with `hide`, `reject` and `restore` as three
named decisions rather than a status dropdown. Each carries a written
reason recorded on the review, in an append-only event row, and in the
audit log.

`AdminReviewController` has no method that sets a status directly, so an
operator cannot put a review into a state the domain has no route to.

## 11. Review content is untrusted (§13)

`ReviewText` applies one policy: `html_entity_decode`, then `strip_tags`,
then a control-character strip. The decode comes first on purpose — a body
of `&lt;script&gt;alert(1)&lt;/script&gt;` would survive a bare
`strip_tags` and become live markup the moment something decoded it
downstream.

Length is measured **after** cleaning, so four thousand characters of
`<b>` tags is not a four-thousand-character review; a body that is
entirely markup is refused as too short.

§13 says not to assume React escaping is the only rendering context, and
it is right. Before the text reaches React it travels inside a
`<script type="application/json">` block in the served HTML, where React
has no say at all. Two independent defences hold there:

1. the tags are gone before storage, so there is no `</script>` to emit;
2. `json_encode` escapes forward slashes by default, so a literal
   `</script>` cannot be spelled in the payload even if one arrived by
   another route.

`ReviewSurfaceTest::a_review_cannot_close_the_props_script_block` counts
the `<script` occurrences in the page before and after a hostile review is
posted and asserts the number is unchanged.

## 12. Recommendations return canonical products (§30, §31)

Both rules live in `EligibleRecommendationProducts`, and there is exactly
one implementation. A strategy produces candidate ids — cheap, rough,
possibly stale — and this turns them into products a customer may be
shown:

1. **Dedupe.** Candidates collapse to one row per product, first
   appearance winning, because a chain puts its strongest evidence first.
2. **Eligibility.** The product must be publicly visible *now*, read live
   from the `products` table by the catalogue's own rule, **and** have a
   public search document. Both, not either: the live row is what stops a
   stale index leaking a product withdrawn an hour ago, and the document
   is what supplies the price and image a card needs.
3. **Order.** The caller's ranking is preserved exactly. A gate that
   reordered would make every strategy's ranking a suggestion.

A product with no eligible offers is additionally excluded — it has no
price to quote. That can only ever *remove* candidates, so §31 holds a
fortiori.

## 13. The fallback chain (§39)

`RecommendationService::chains()` reads as a sentence per slot. The
service runs them in order and stops when enough **eligible** products
have been found — eligible, not merely returned, so a strategy that
produced twelve ids of which ten are unpublished has not filled the shelf
and the next strategy still runs.

`NewArrivalsStrategy` ends every chain that needs a guaranteed answer: it
requires no behaviour, no associations and no history, so it works on a
marketplace that opened yesterday.

`RecommendationSet::usedFallback` is surfaced so the admin can see which
shelves are running cold, rather than everybody assuming the best strategy
is working.

## 14. Support thresholds (§37, §38)

Stored raw, thresholded at read time. `product_associations.support`
records every pair the rebuild saw; `AssociationKind::minimumSupport()`
decides what counts as evidence when the pair is read. Lowering the bar
later is a configuration change rather than a rebuild, and the floor is
hard-coded at 2 — a threshold of one is no threshold.

## 15. Weights and windows are centralised (§36)

`config/veritas.php` under `recommendations`: the five signal weights, the
windows, the two support thresholds, the price band and the cache TTL. No
numeric weight appears in a controller or a job.

`PopularitySignal::weight()` reads the configuration on every call rather
than capturing it in a constant, so an operator who changes a weight and
rebuilds gets the weight they configured.

The per-visitor affinity weights stay on `InteractionEventType`, where
they have lived since M0. `PersonalAffinityStrategy` counts in SQL and
weights in PHP precisely so that enum remains the single definition — a
CASE expression built from it would be the same numbers written twice, and
the copy in SQL is the one that goes stale.

## 16. Analytics and commerce are separated (§2, §48, §56, §60)

The insight modules — `Analytics` and `Recommendations` — read orders,
payments, refunds, the seller ledger, the catalogue, offers, interaction
events, reviews and wishlists. They write six tables, all derived:
`product_popularity_scores`, `product_associations`,
`daily_marketplace_metrics`, `daily_product_metrics`,
`daily_seller_metrics`, `daily_search_metrics`. Drop any of them, run the
rebuild, and it comes back identical.

`AnalyticsBoundaryTest` enforces this statically:

1. neither module imports **any** model — a model brings `save()`,
   `update()` and `delete()` with it, and the point is that those are
   unreachable;
2. neither calls another module's Action, with one narrow allowance —
   `Events\Actions\RecordInteraction`, because recording that a shelf was
   shown is the insight layer's own behavioural input and appears nowhere
   in §2's list. A companion test asserts the allowance stays a list of
   one;
3. every raw `DB::table(...)->insert/update/delete` names one of the six;
4. neither takes a write lock — a rebuild that locked order rows could
   block checkout, which is a way for a dashboard to take the marketplace
   down without writing a byte;
5. the six owned tables carry no word from `order`, `payment`, `refund`,
   `ledger`, `payout`, `inventory`, `offer` or `seller_account`, so the
   list cannot silently widen into transactional truth.

§48 is proved by experiment, not by assertion:
`AnalyticsProjectionTest::a_purchase_event_that_never_happened_does_not_move_the_money`
fires fifty fabricated `purchase_completed` events, each carrying a
`value_minor` of 999,999, rebuilds, and asserts GMV is unchanged.

§56 is proved by reconciliation:
`daily_money_columns_reconcile_with_the_m7_finance_summary` sums every
daily row over a period and asserts GMV, refunds, commission and net sales
equal what `SummarisePlatformFinance` reports for the same period. The CI
smoke does the same in the built image and prints
`analytics agrees=yes`.

## 17. The rebuild commands (§60)

Both take `--as-of` (or `--from`/`--to`/`--days`) so a run is reproducible,
and both take `--verify`, which fingerprints every protected table — row
count, maximum id and a checksum over the primary keys — before and after
the run and exits non-zero if anything moved. The two commands share one
`PROTECTED_TABLES` list, and a test asserts they do, so the two cannot
drift.

Neither is incremental. Every window and every day is deleted and
reinserted inside one transaction, which is what makes a second run over
unchanged data produce byte-identical rows and a failed run leave data
stale rather than half-counted. An incremental rollup would be faster and
wrong the first time an event arrived late.

## 18. Seller isolation in analytics (§52)

Every query in `GetSellerAnalytics` is scoped by `seller_account_id` in
its WHERE clause, not filtered afterwards — there is no code path that
reads a rival's row and then decides not to show it. The seller id comes
from the membership, never from the request, so there is no parameter to
tamper with.

The best-sellers table is built from that seller's own order items rather
than from `daily_product_metrics`. That table counts the whole
marketplace, and showing a seller the marketplace's number for a product
they happen to list would hand them their competitors' volume.

`AnalyticsSurfaceTest::a_seller_analytics_payload_never_names_another_seller`
asserts a rival's legal name, public id and product title appear nowhere
in the response body.

## 19. Day boundaries and currency (§70, §71)

`AnalyticsDay` is a platform-timezone day whose boundaries are UTC
instants, exclusive at the top. Both are computed together, in one place,
because a projection that computed them separately is how a day ends up 23
hours long in one table and 25 in another.
`an_event_just_before_local_midnight_belongs_to_that_day` sets the
platform timezone to America/Los_Angeles and asserts an event at 07:30 UTC
on the 4th lands on the 3rd.

Currency is a filter, never a sum across. `daily_marketplace_metrics` is
keyed on `(day, currency)`, the dashboards state which currency they are
showing, and no figure anywhere adds two together.

## 20. SEO (§16, §67, §69, §100)

`aggregateRating` is emitted only when the rating summary reports at least
one published review and the average is numeric. A product nobody has
reviewed emits nothing rather than a zero — 0 is not on the scale, and a
rich result showing zero stars for an unreviewed product is a lie that
costs the domain's standing rather than one page's ranking.

Individual `Review` items accompany the aggregate, capped at five, drawn
from the same payload the page renders. A markup block quoting reviews a
visitor cannot find is the same lie as an invented rating, and it is
avoided the same way: one payload, not two queries that have to agree.

The wishlist and every `account/*` page carry `X-Robots-Tag: noindex,
nofollow` as a response header as well as a meta tag. The header is what
actually protects them — it reaches a crawler even when SSR is
misconfigured, which is precisely the moment an accidental index would
happen.

## 21. Out of scope (§103)

Nothing was built for: coupons, promotions or discount codes; sponsored
listings or paid placement; machine-learning ranking; embeddings; a vector
database; Kafka; microservices; Kubernetes; seller *service* reviews;
seller responses to reviews; AI moderation; RMA or returns portal; a
mobile app.

One boundary is worth naming because it was a judgement call rather than
an exclusion: the wishlist button appears on the product page and the
wishlist page, and **not** on search or category cards. Adding it there
would have meant changing `SearchHit` and `ProductCard`, which are M3
shapes with M3 tests, for a modest gain. It is a deliberate stopping
point, not an oversight.

The tree was audited against that decision after the fact, and one piece
of scaffolding was found and removed: `GetWishlist::savedAmong()`, a bulk
"which of these products are saved" helper written for a page of cards,
with a test but no production caller. A tested, documented method for a
surface that does not exist is worse than no method — the next person
reads it as evidence the surface is coming. `has()` remains, because the
product page asks about one product.

`WishlistTest::listing_cards_carry_no_wishlist_control` now asserts the
decision directly: `ProductCard.tsx` and the search, category and store
pages contain no wishlist control, and `savedAmong()` does not exist. A
later milestone that wants listing hearts will re-open the decision on
purpose rather than by accident.

## 22. Test matrix

| File | Tests | Covers |
| ---- | ----: | ------ |
| `Reviews/ReviewInvariantsTest` | 22 | Pre-UI properties 1–3; evidence, one-per-customer, rating constraint, immutability |
| `Reviews/ReviewSurfaceTest` | 17 | §4 unfakeable badge, §13 untrusted text, edit/withdraw, author isolation |
| `Reviews/ReviewStructuredDataTest` | 10 | §16 page/markup agreement, §69 no zero rating, Review items |
| `Reviews/AdminModerationTest` | 13 | §8 review-not-approval queue, permissions, reasons, audit, history |
| `Recommendations/RecommendationInvariantsTest` | 19 | Pre-UI properties 4–5; dedupe, eligibility, chain, determinism, thresholds |
| `Recommendations/RecommendationRebuildTest` | 14 | Weights, windows, symmetry, paid-only pairs, idempotency, §60 boundary |
| `Analytics/AnalyticsProjectionTest` | 19 | §56 reconciliation with M7, §48 fabricated events, day boundaries, search |
| `Analytics/AnalyticsSurfaceTest` | 10 | §52 seller isolation, permissions, read-only routes |
| `Customers/WishlistTest` | 16 | Idempotency, isolation, unavailable products, HTTP, noindex |
| `Invariants/AnalyticsBoundaryTest` | 6 | §2 structural separation of insight from commerce |
| **Total** | **146** | |

## 23. Gate results

Every gate below was run in this environment on the final tree, except
where it says otherwise.

| Gate | Result |
| ---- | ------ |
| PHPUnit (full suite) | **1,269 tests / 13,960 assertions, all passing** |
| Laravel Pint | **passed** — no files needed fixing |
| PHPStan + Larastan, level 8 | **passed** — 0 errors |
| TypeScript `tsc --noEmit` | **passed** — 0 errors |
| ESLint (`--max-warnings=0`) | **passed**, after a fix — see below |
| Prettier `--check` | **passed**, after a fix — see below |
| Vite client build | **passed** |
| Vite SSR build (`build:ssr`) | **passed** — `bootstrap/ssr/ssr.js`, 157 kB |
| `php artisan statuses:export` | **current** — no diff after regeneration |
| `reviews:reconcile-ratings` | **clean** — "Every product rating summary matches its reviews." |
| `finance:reconcile-sellers` | **clean** — "Seller finance reconciles in USD." (M7, unaffected) |
| `inventory:reconcile` | **clean** |
| `recommendations:rebuild --verify` | **exit 0** — no transactional table changed |
| `analytics:rebuild --verify` | **exit 0** — no transactional table changed |
| M8 CI smoke script, run locally | **every assertion held** — see below |
| Docker local | **unverified — no daemon** |

The test count moved from **1,123** at the M7 baseline to **1,269**: 146
new tests, no test removed and no assertion weakened.

### Two gates that were not clean when this report was first written

ESLint and Prettier are in `package.json` and were not run during M8
development; both failed on the first invocation afterwards, and both are
now fixed.

ESLint's `react-hooks/exhaustive-deps` caught a real defect rather than a
style point. `RecommendationShelf` derived `const products = set?.products
?? []` and used it as a `useEffect` dependency. That fallback allocates a
fresh array on every render, so the dependency never compared equal and
the effect re-ran each time — only the `recorded` ref stopped a second
impression beacon going out. The effect now depends on the `set` prop
itself and reads the products inside, which makes the dependency genuinely
stable and returns the ref to guarding what it was written for: React's
double-invoked effects in development.

Prettier reformatted eight files, all added by M8. No behaviour changed.

The lesson is recorded rather than smoothed over: a gate that exists in
`package.json` and is not in the milestone's own checklist is a gate that
does not run.

### The M8 smoke, run locally

Docker cannot start in this environment, so the containerised CI job could
not be exercised. The smoke *script* was run against the local PostgreSQL
and Redis after the full M4 → M7 seeding chain, which verifies its logic
and its assertions but not the built image. Output:

```
fixture product=1 buyer=1 seller_order=2
product_slug=m4-smoke-kettle-449537
review id=1 verified=yes status=published order_item=set
unverified reason=not_purchased
rating offers=1 summaries=1 average=5.00 count=1 verified=1
hidden changed=yes has_rating=no count=0
restored has_rating=yes count=1
recommendations count=3 distinct=3 contains_anchor=no strategies=new_arrivals
recommendations ineligible=0
reco_rebuild idempotent=yes transactional_unchanged=yes
reco_verify exit=0
analytics gmv_rolled=52500 gmv_m7=52500 refunds_rolled=9000 refunds_m7=9000 commission_rolled=5220 commission_m7=5220
analytics agrees=yes
analytics_verify exit=0
reconcile problems=0
reconcile_command Every product rating summary matches its reviews.
```

`strategies=new_arrivals` is the fallback chain working as designed: the
anchor's category had no comparable products in the seeded catalogue, so
the chain fell through to the strategy that always has an answer. The
shelf is still three distinct products, none of them the anchor and none
of them ineligible.

The HTTP assertions in the new CI step were also checked against a local
`artisan serve`, which is how two of them were corrected before being
committed: an unauthenticated moderation POST returns 419 (CSRF) rather
than 302 in this configuration, and a signed-in customer reaching
`/seller/analytics` gets 404 rather than 302 — the seller portal's
deliberate "a guessed page does not exist" rule. Both assertions now say
what actually happens and why.

**Docker local: unverified — no daemon.** `service docker start` fails
with `ulimit: error setting limit (Operation not permitted)`, as it has
since M0. The CI job that builds the image, starts the stack, migrates,
drains the queues, checks SSR and runs every smoke is unchanged and gains
three M8 steps; it has not been executed here.

## 24. Stripe

Unchanged from M7. `stripe/stripe-php` v21.3.1 is installed, the driver
is the fake one by default, and no M8 code touches payments. No network
call to Stripe was made in this milestone, and none is needed for
anything M8 added.

## 25. One PHPUnit run at a time

The M8 progress run demonstrated a real hazard: two PHPUnit runs against
`veritas_test` do not merely interleave, they migrate against each other.
One run's `migrate:fresh` drops tables the other is mid-transaction on,
PostgreSQL reports a deadlock on the DDL rather than on anything a test
did, and what remains is a half-migrated schema whose failures look like
defects in code that is fine. Diagnosing it cost a full re-run.

Three things now hold the line.

**CI was already serial, and now says so.** Steps within a GitHub Actions
job never overlap, so the invariants step and the full-suite step have
always run one after the other. The workflow now records that this is a
requirement rather than a coincidence, and what to do if the suites are
ever split across jobs or run with `--parallel`: give each worker its own
`DB_DATABASE`.

**A guard makes the failure loud and immediate.** `tests/bootstrap.php`
takes a PostgreSQL session-level advisory lock keyed on the database name.
A second run gets `pg_try_advisory_lock` returning false, prints what is
happening and how to fix it, and exits 1 — in under a second, instead of
deadlocking minutes later on a confusing DDL error. The lock is held by a
PDO connection kept alive for the process, so PostgreSQL releases it when
the process ends, including when it crashes.

`VERITAS_ALLOW_CONCURRENT_TESTS=1` opts out, because a per-worker database
is the other correct answer and somebody who has built that should not
have to fight this.

**It was tested by doing it.** A holder process takes the lock; a second
`phpunit` invocation exits 1 with the explanation; the holder is released
and a fresh run passes. The guard also caught a genuine mistake during
this very verification — an attempt to run a suite while the full run was
still going — which is the behaviour it exists for.

## 26. What M9 inherits

Four things are worth knowing before the next milestone builds on this.

**The eligibility gate is the extension point.** Adding a recommendation
strategy means implementing three methods and adding a class name to one
chain. It cannot introduce a way to recommend something unpublished,
because the gate is not optional — a strategy returns ids and has no
route to a page except through it.

**The projections are disposable.** Every one of the six can be dropped
and rebuilt. Nothing in the marketplace depends on them for truth, which
is what makes changing their shape a low-risk operation rather than a
migration with a data-loss window.

**Search impressions are recorded but not yet emitted.** The
`search_result_shown` event type exists and `daily_product_metrics`
counts it, so the click-through-rate column is wired end to end — but the
search page does not emit the event yet, so that column reads zero. It is
a deliberate seam rather than a gap: the projection is ready for the day
the discovery pages start reporting impressions.

**The recommendation cache stores a ranking, never a payload.** A shelf
is rebuilt from cached ids on every read and re-passes the eligibility
gate each time, so a product withdrawn since the ids were computed
disappears on the next request rather than five minutes later. Any future
change to caching should preserve that: cache the work, never the
decision.

**Two things remain unverified, and neither is a defect.** Docker cannot
start in this environment — `service docker start` fails with `ulimit:
error setting limit (Operation not permitted)` — so the containerised CI
job has never been executed here, in any milestone. And the real Stripe
test network has never been reached, because no credentials or outbound
connectivity to it exist here; the fake driver is the default and every
payment test runs against it. Both are carried forward as **unverified**
rather than assumed, and both stay that way until an environment supplies
what they need.

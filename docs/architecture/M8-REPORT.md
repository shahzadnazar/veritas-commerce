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

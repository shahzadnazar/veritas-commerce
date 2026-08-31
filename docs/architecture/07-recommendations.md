# 07 · Recommendations & the suggestion system

The Phase 1 spec defers "AI recommendations" — correctly, because a recommender with no interaction history recommends noise. But the *data* it needs must be captured from the first day of traffic, or the model that ships in month six is trained on nothing.

**So the Phase 1 commitment is: capture everything, ship rules.** Tiers 2 and 3 then arrive without a re-architecture.

## 7.1 The event stream — build this now

One append-only table, one queued writer, no analytics vendor required.

```
interaction_events
  id            bigserial
  occurred_at   timestamptz
  actor_key     text        -- user_id if known, else a rotating anonymous id
  is_anonymous  boolean
  session_id    text
  type          text        -- see the taxonomy below
  product_id    bigint null
  variant_id    bigint null
  seller_id     bigint null
  category_id   bigint null
  query         text null   -- for search events
  position      int null    -- rank of the item when it was clicked
  context       text        -- home_featured | category | search | pdp_related
                            -- | store_page | cart | email
  value_minor   bigint null -- for purchase events
  metadata      jsonb
```

**Event taxonomy** (the minimum that supports every technique below):

| Event | Weight in the model | Notes |
|---|---|---|
| `product_viewed` | 1 | the volume driver |
| `product_impression` | 0 | needed to compute click-through, not affinity |
| `search_performed` | — | query, result count, filters applied |
| `search_result_clicked` | 2 | with rank position — the training signal for ranking |
| `zero_results` | — | the highest-value merchandising report there is |
| `added_to_cart` | 4 | |
| `removed_from_cart` | −2 | |
| `checkout_started` | 5 | |
| `purchased` | 10 | the ground truth |
| `store_visited` | 1 | powers store affinity |

**Privacy from the start** ([09](09-security.md) covers this fully): anonymous actors get a rotating pseudonymous id, never a durable fingerprint; the table carries no PII; users can request deletion, which nulls `actor_key` while preserving the aggregate row for the model; a "do not personalise" toggle in account settings excludes the user from Tier 3 entirely and is honoured server-side.

**Volume management.** Events are written on the `index` queue, batched. A partition by month plus a 13-month retention policy keeps the table manageable; aggregates outlive the raw rows.

## 7.2 Tier 1 — rules and co-occurrence (Phase 1, ships at launch)

No machine learning, no cold-start problem, and it covers the surfaces the prototype already draws.

| Surface | Algorithm | Fallback |
|---|---|---|
| **"More from this seller"** (product detail, drawn) | Same seller, published, in stock, excluding this product, ordered by recent sales then recency | Newest from seller |
| **"Featured this week"** (home, drawn) | Admin-curated list — the prototype states both home rails are admin-controlled | — |
| **"New this week"** (home, drawn) | `published_at DESC`, in stock, deduplicated to max 2 per seller so one prolific seller cannot own the rail | — |
| **Related products** (product detail) | Same leaf category + brand, then same leaf category, price within ±40%, in stock | Category bestsellers |
| **Search autocomplete** | Prefix match on product title and store name + the top completed queries from `search_performed` | Popular queries (the prototype's "Popular right now" chips) |
| **Cart cross-sell** (Phase 1.1) | Items co-purchased with anything in the cart | Category bestsellers |
| **Empty states** | The prototype's "Popular right now" chips, driven by 7-day query counts | Static seeded list |

**"Customers who bought this also bought"** becomes available as soon as there are orders — it is a SQL job, not a model:

```sql
-- nightly: item-item co-purchase counts, written to product_affinities
INSERT INTO product_affinities (product_id, related_product_id, score, kind)
SELECT a.product_id, b.product_id,
       count(*)::float / sqrt(pa.total * pb.total),   -- cosine normalisation
       'co_purchase'
FROM order_lines a
JOIN order_lines b ON a.sub_order_id = b.sub_order_id
                  AND a.product_id < b.product_id
JOIN product_order_totals pa ON pa.product_id = a.product_id
JOIN product_order_totals pb ON pb.product_id = b.product_id
GROUP BY a.product_id, b.product_id, pa.total, pb.total
HAVING count(*) >= 3        -- suppress coincidence
```

The normalisation matters: without it, the single most popular product in the catalogue is "related" to everything.

## 7.3 Tier 2 — collaborative filtering (Phase 2, ~3 months of traffic)

Once there are roughly **10k+ users with 3+ interactions each**, item-item collaborative filtering becomes worthwhile.

- **Model:** implicit-feedback item-item similarity (cosine over the weighted interaction matrix), or ALS if the matrix warrants it. Trained offline nightly on a worker; nothing is trained in a web request.
- **Serving:** precomputed top-50 neighbours per product in `product_affinities`, read from Redis. Serving cost is a cache lookup, so it works on the product page's critical path.
- **Personalised home rail:** "Picked for you" = union of neighbours of the user's recent interactions, minus items already purchased, ranked by affinity × recency decay, diversified so no seller or category takes more than 30% of the rail.
- **Cold start:** a new user gets category bestsellers; a new product gets content-based neighbours (same category, brand, price band) until it has interactions.

## 7.4 Tier 3 — embeddings and learned ranking (Phase 3)

- **Content embeddings.** Product title + description + category path embedded into a vector, stored in Postgres via **`pgvector`** with an HNSW index. This gives semantic "similar products" that work for a brand-new listing with zero interactions, and semantic search that survives vocabulary mismatch ("cordless kettle" vs "electric jug").
- **Hybrid retrieval.** Keyword (Meilisearch/OpenSearch) and vector recall are unioned, then re-ranked. Keyword alone misses synonyms; vector alone loses exact-SKU precision. The union beats either.
- **Learning to rank.** Features per candidate: text relevance, affinity score, price competitiveness, seller rating, conversion rate, recency, availability, distance. Trained on `search_result_clicked` with its recorded rank position, using position-debiasing. This is where the `position` column on the event table earns its place — without it, the training data is unusable.
- **Serving architecture:** a `RankingPort` sitting behind `SearchPort` so ranking can be swapped, A/B tested, or turned off per request without touching retrieval.

## 7.5 Guardrails — non-negotiable at every tier

These are what separate a recommender that helps from one that quietly damages the marketplace:

1. **Never recommend an unavailable item.** Availability is checked at serve time, not at model time. A recommendation rail full of "Out of stock" is worse than an empty rail.
2. **Never recommend across a suspension.** Suspended sellers' products leave every rail immediately.
3. **Seller diversity cap.** No single seller takes more than 30% of any rail. A marketplace whose home page is one store is not a marketplace, and it destroys seller trust faster than any commission change.
4. **Explainability.** Every rail carries a human-readable reason — "Because you viewed", "From this seller", "Popular in Home & Kitchen". This is a UX requirement and also the fastest debugging tool you will have.
5. **No dark patterns.** No fake scarcity, no fabricated "23 people are viewing". The prototype's design language is plain and factual; recommendations must not undercut it.
6. **Feedback loop control.** Popularity-driven rails self-reinforce. An exploration slot (10% of impressions given to promising low-exposure items) keeps the long tail alive and gives new sellers a path to their first sale.
7. **Measured, or off.** Every rail is instrumented for impressions, clicks and attributed revenue. A rail that does not beat its fallback in an A/B test is removed. "It looks smart" is not a metric.

## 7.6 Admin controls

Merchandising is a business function, not a model parameter. Admin gets:
- Curate the Featured and New rails (already in Phase 1 — the prototype states both are admin-controlled).
- Pin or exclude a product from recommendations.
- Blocklist category pairs that must never co-recommend.
- A zero-results report — the single most actionable merchandising artefact, straight from `search_performed`.
- A per-rail performance table: impressions, CTR, attributed revenue.

## 7.7 Build order

| When | What | Depends on |
|---|---|---|
| **Phase 1** | Event table + writer; rule-based rails; search autocomplete; popular queries; zero-results report | nothing |
| **Phase 1, week 8+** | Co-purchase affinities (nightly SQL job) | first orders |
| **Phase 2** | Item-item CF, "Picked for you", cart cross-sell, A/B framework | ~3 months of traffic |
| **Phase 3** | `pgvector` embeddings, hybrid retrieval, learned ranking | catalogue scale + search volume |

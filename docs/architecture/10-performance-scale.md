# 10 · Performance & scalability

## 10.1 Targets

| Surface | Metric | Target |
|---|---|---|
| Storefront pages | TTFB p75 | ≤ 300ms cached, ≤ 500ms uncached |
| Storefront pages | LCP p75 mobile | ≤ 2.0s |
| Search / category | server time p95 | ≤ 250ms |
| Product detail | server time p95 | ≤ 200ms |
| Checkout submit | p95 end-to-end | ≤ 1.5s incl. payment call |
| Seller / admin tables | p95 | ≤ 400ms at 100 rows |
| Background email | queued → sent p95 | ≤ 30s |
| Availability | monthly | 99.9% |

## 10.2 The caching layers

```
1. Cloudflare edge      anonymous HTML, 60s + stale-while-revalidate 600s
                        static assets, immutable, 1 year (hashed filenames)
                        images, 30 days
2. Application cache    Redis: product payloads, category trees, facet counts,
   (Redis)              store pages, settings, commission rate — all tagged
3. Query cache          per-request memoisation; no repeated identical query
4. Database             Postgres shared buffers, tuned; pg_stat_statements on
5. Client               SWR-style revalidation on Inertia partial reloads
```

**Cache invalidation is by tag, and driven by domain events**, never by TTL alone:

| Event | Invalidates |
|---|---|
| `ProductPublished` / `ProductUpdated` | `product:{id}`, `category:{id}`, `store:{slug}`, search index |
| `StockMovementRecorded` | `product:{id}` availability fragment only — not the whole page |
| `SellerSuspended` | `store:{slug}` (→404), every `product:{id}` for that seller, search index |
| `CommissionRateChanged` | `settings:commission` |
| `CategoryUpdated` | `category:*`, navigation fragment |

Anonymous storefront HTML is edge-cached; authenticated pages set `Cache-Control: private, no-store`. The header's cart count is fetched client-side after paint so it never blocks or fragments the cached HTML — the single most common cause of an uncacheable ecommerce storefront.

## 10.3 Query discipline

- **N+1 is a build failure**, not a code review note. `Model::preventLazyLoading()` is enabled in local, CI and staging, so a lazy load throws.
- Every list endpoint declares its eager loads explicitly.
- **Query budgets per route**, asserted in tests: product detail ≤ 8 queries, category listing ≤ 10, seller order list ≤ 12, admin dashboard ≤ 20. Exceeding the budget fails CI.
- Counts on dashboards come from **maintained aggregates**, not `COUNT(*)` over millions of rows. `seller_daily_stats` and `platform_daily_stats` are rolled up by a scheduled job; the dashboards read them. The prototype's dashboards show figures, not charts, which makes this straightforward.
- Cursor pagination for anything the customer scrolls; offset pagination only for admin tables where a page number is meaningful (the prototype shows numbered pagination — capped so `?page=100000` cannot table-scan).
- Reads route to a replica once one exists: `Queries/` classes use a `read` connection, `Actions/` always use the primary. Read-after-write inside a request sticks to the primary for the rest of that request.

## 10.4 Frontend performance

- Route-level code splitting: a storefront visitor never downloads seller or admin code. Separate Vite entry points per area.
- The design system is tree-shakeable; no barrel file re-exporting everything.
- Images through the media pipeline: AVIF → WebP → JPEG, `srcset` at 400/800/1200/1600, explicit dimensions, lazy below the fold, `fetchpriority="high"` on the LCP hero.
- Fonts self-hosted, subset, preloaded, `font-display: swap`.
- **Skeletons that mirror real geometry**, shown only after 200ms — faster responses skip them entirely (the design system's rule, which is also a CLS control).
- No client-side chart library on the storefront; the portals' Phase 1 data display is CSS bars and figures, exactly as designed.

## 10.5 Background work isolation

Four queues on separate worker pools ([02](02-system-architecture.md#25-background-work)) so a burst of image processing cannot delay a payment webhook. Horizon dashboards expose per-queue wait time; an alert fires when `critical` wait exceeds 10 seconds.

Scheduled jobs:

| Job | Cadence | Purpose |
|---|---|---|
| `ReleaseExpiredHolds` | every minute | free stock from abandoned checkouts |
| `ReconcileSellerBalances` | nightly | ledger vs cache; alert on any delta |
| `ReconcileStockLevels` | nightly | movement replay vs `on_hand` |
| `RollUpDailyStats` | hourly | dashboard aggregates |
| `RebuildSitemaps` | nightly | SEO |
| `BuildProductAffinities` | nightly | recommendations |
| `NotifyUpcomingRateChange` | daily | 7-day seller notice |
| `FullSearchReindex` | weekly | drift correction |
| `ExpireStaleCarts` | daily | housekeeping |

## 10.6 Load expectations and capacity

Planning figures for the pilot, with the first rung of the ladder pre-decided so nobody is deciding under load:

| Stage | Products | Orders/day | Peak RPS | Shape |
|---|---|---|---|---|
| Pilot | ~10k | ~100 | ~20 | 2 app containers, 1 Postgres (4 vCPU), 1 Redis, 2 workers |
| Growth | ~200k | ~2,000 | ~200 | 4–6 app containers, Postgres 8 vCPU + 1 replica, Meilisearch, 4 workers |
| Scale | 1M+ | 20,000+ | 2,000+ | Autoscaled app tier, Postgres + 2–3 replicas (partition orders by month), OpenSearch, per-queue worker pools, edge caching aggressive |

**Load testing before launch** (k6 against staging), on the three journeys that matter: browse → product → cart, checkout submit under concurrency (specifically two customers racing the last unit), and the seller order queue at 500 open orders.

## 10.7 What we will not do prematurely

- No microservices, no Kubernetes, no service mesh — the spec is explicit and correct.
- No read replica before the primary shows sustained pressure.
- No dedicated search engine before Postgres FTS shows measured latency problems.
- No CQRS or event sourcing. Append-only tables give us the audit properties without the operational cost.
- No sharding. Postgres on a large instance handles far more than this marketplace will see in Phase 1–2.

Each of these has a **named trigger metric** in [02](02-system-architecture.md#27-the-scale-ladder). Climbing a rung without the metric is the failure mode; refusing to climb when the metric fires is the other one.

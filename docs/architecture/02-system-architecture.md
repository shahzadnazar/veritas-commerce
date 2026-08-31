# 02 · System architecture

## 2.1 The shape: one deployable, hard internal boundaries

Phase 1 is a **modular monolith**. One Laravel application, one database, one deploy — but organised so that each business capability is a module with an explicit public surface and no reach-through into another module's tables.

```
                 Visitors · Sellers · Staff
                            │
              Cloudflare  (DNS · CDN · WAF · bot · rate limit)
                            │
                    Load balancer (TLS terminate)
        ┌───────────────────┼────────────────────┐
   Storefront          Seller portal         Admin portal
   Inertia+React SSR   Inertia+React         Inertia+React
        └───────────────────┼────────────────────┘
                   Laravel application
   ┌──────────────────────────────────────────────────────┐
   │ Identity │ Sellers │ Catalogue │ Stock │ Cart │ Orders│
   │ Payments │ Commission │ Payouts │ Notifications │ Media│
   │ Search   │ Recommendations │ Admin/Reporting          │
   └──────────────────────────────────────────────────────┘
        │             │            │            │
   PostgreSQL      Redis       Queue workers   Object store
   (primary +    (cache ·     (Horizon:        (R2/S3 —
    replicas)     session ·    emails, media,   images, docs)
                  locks)       search index,
                               webhooks)
                            │
              Stripe Connect  ·  Email (Postmark/SES)  ·  Sentry
```

**Why a monolith and not services.** At Phase 1 volume, service boundaries cost more than they return: distributed transactions across order → stock → commission, N deploy pipelines, and cross-service tracing, all before there is traffic. The spec is right. The discipline that makes it safe is that the boundaries are real in code from day one, so extraction later is mechanical.

## 2.2 Module boundaries

Each module lives at `app/Modules/<Name>/` with this internal shape:

```
app/Modules/Orders/
├── Contracts/          # interfaces other modules may type-hint
├── Data/               # DTOs crossing the boundary (spatie/laravel-data)
├── Models/             # Eloquent models — never referenced outside this module
├── Actions/            # single-purpose write operations (PlaceOrder, AdvanceStatus)
├── Queries/            # read models for the UI; returns DTOs, not models
├── Events/             # OrderPlaced, OrderShipped, OrderRefunded
├── Listeners/
├── Policies/
├── Http/               # controllers, requests, resources for this module
├── Database/           # migrations, factories, seeders owned by this module
└── OrdersServiceProvider.php
```

**The rules, enforced by a Deptrac layer test in CI:**

1. A module may depend only on another module's `Contracts/`, `Data/` and `Events/`. Never its `Models/`.
2. Cross-module writes go through an Action or a domain Event. No module writes another's tables.
3. Cross-module reads go through a `Query` class returning a DTO.
4. Shared kernel (`app/Support/`) holds Money, Ulid, statusTone, and nothing with business meaning.

| Module | Owns | Publishes |
|---|---|---|
| **Identity** | users, sessions, password resets, 2FA, roles | `UserRegistered`, `CurrentUser` DTO |
| **Sellers** | seller applications, sellers, stores, policies, suspension | `SellerApproved`, `SellerSuspended`, `StoreSummary` |
| **Catalogue** | categories, brands, products, variants, images, moderation | `ProductPublished`, `ProductRejected`, `ProductCard` |
| **Stock** | stock levels, holds, movements | `StockHeld`, `StockReleased`, `LowStockDetected` |
| **Cart** | carts, cart lines, pricing snapshots at add-time | `CartCheckedOut` |
| **Orders** | orders, sub-orders, lines, status history, shipments | `OrderPlaced`, `OrderStatusAdvanced`, `OrderRefunded` |
| **Payments** | payment attempts, payments, refunds, provider webhooks | `PaymentCaptured`, `PaymentFailed`, `RefundIssued` |
| **Commission** | rate schedule, rate history, snapshot writer | `CommissionRateScheduled`, `CommissionSnapshotted` |
| **Payouts** | seller ledger, balances, payout requests and decisions | `EarningPosted`, `PayoutRequested`, `PayoutDecided` |
| **Notifications** | email templates, sends, preferences | — |
| **Media** | uploads, variants, grayscale derivation, CDN URLs | `MediaReady` |
| **Search** | index writers, query port, facets | — |
| **Recommendations** | event capture, candidate generation, ranking | — |
| **Reporting** | admin dashboards, CSV exports, reconciliation | — |

## 2.3 Frontend architecture

**One React + TypeScript codebase, three Inertia areas.**

```
resources/js/
├── design-system/     # the 44 components from design/prototype, typed
│   ├── tokens.ts      # generated from design/prototype/_ds/.../styles.css
│   ├── primitives/    # Button, Input, Field, Select, Toggle, Segmented, Tag…
│   ├── patterns/      # Table, Filters, Pagination, StatCard, Alert, Toast,
│   │                  # Modal, Drawer/Sheet, FormSection, EmptyState, Skeleton
│   └── statusTone.ts  # THE single status→tone map (Phase 6 finding 1)
├── storefront/        # pages, layouts, SSR entry
├── seller/            # pages, layouts, sidebar
├── admin/             # pages, layouts, queue rails
└── shared/            # money formatting, dates, hooks, Inertia helpers
```

- **Storefront runs Inertia SSR** (`@inertiajs/react` server render via a Node side-process) so crawlers and first paint get complete HTML. This is the SEO requirement in the spec, and it is non-negotiable — see [06](06-seo-and-search.md).
- **Seller and admin portals run client-side only.** They are behind auth, never crawled, and skipping SSR halves their infrastructure surface.
- **No component is duplicated across areas.** The consistency review found 38 of 44 components appear in at least two apps; the six that do not are genuinely single-purpose. A component that exists in two areas lives in `design-system/` and takes a `density` prop (`comfortable` for storefront, `compact` for portals) — that prop is the only expression of the density step.

## 2.4 Request lifecycle

**Read (storefront product page):**
```
Cloudflare edge cache (anonymous, 60s, stale-while-revalidate)
  → app: route → controller → Catalogue\Queries\ProductDetail
      → Redis cache (product payload, 5 min, tagged by product id)
        → Postgres (single query with eager loads, no N+1)
  → Inertia SSR renders HTML  → response with ETag + Cache-Control
```

**Write (place order) — the critical path:**
```
POST /checkout
 1. validate request (address, payment intent)
 2. DB transaction:
      a. re-price the cart from live product/variant rows (never trust the client)
      b. SELECT … FOR UPDATE the affected stock rows
      c. create stock holds; abort if any line is short
      d. create order + one sub-order per seller + lines with price snapshots
      e. read the effective commission rate; write commission snapshot per sub-order
      f. create payment_attempt row (status: pending)
 3. COMMIT
 4. call Stripe (outside the transaction, with the order's idempotency key)
 5. on capture: PaymentCaptured → convert holds to decrements, write stock
    movements, post nothing to the ledger yet, notify seller + customer
    on failure: PaymentFailed → release holds, record the attempt, allow retry
```

Money is posted to the seller ledger on **completion** (Delivered), not capture — matching the spec's "order marked complete → order total split". See [04](04-money-and-commission.md) for the exact trigger and its open decision.

## 2.5 Background work

Horizon with **four isolated queues** so one slow job class cannot starve the others:

| Queue | Work | Priority |
|---|---|---|
| `critical` | payment webhooks, order state transitions, stock reconciliation | highest, own workers |
| `default` | email sends, notifications, ledger postings | high |
| `media` | image derivatives, grayscale, thumbnails, CDN warm | low, own workers |
| `index` | search index writes, recommendation event batching | low |

Every job is **idempotent and keyed** — a webhook replayed three times must not post three ledger rows. Jobs carry `tries`, `backoff`, and a dead-letter table that admin can inspect.

## 2.6 Environments

| Env | Purpose | Data | Payments |
|---|---|---|---|
| `local` | development | seeded demo data | Stripe test |
| `ci` | tests | factories only | mocked port |
| `staging` | client review, UAT, the prototype's sign-off gate | anonymised copy | Stripe test |
| `production` | live | real | Stripe live (Phase 1 runs test mode per spec) |

## 2.7 The scale ladder

Straight from the spec, made concrete. Each rung is a config or infra change, not a rewrite.

| Trigger | Action | Effort |
|---|---|---|
| p95 latency rises under traffic | Run more app containers behind the LB; sessions and cache already in Redis so the app is stateless | hours |
| DB CPU > 60% sustained | Managed Postgres with 1–2 read replicas; route `Queries/` to the replica via a read connection | days |
| Catalogue > ~200k products or facet latency > 200ms | Swap `SearchPort` from Postgres FTS to Meilisearch, then OpenSearch | days |
| Queue depth persistent | Move workers to their own instances; scale per queue | hours |
| Media egress cost or LCP regression | Cloudflare Images / R2 with resize-on-the-edge | days |
| One module dominates load | Extract it — it already has Contracts, Events and its own tables | weeks, and only with evidence |
| Storefront traffic dwarfs portals | Split the storefront into its own Next.js/Remix frontend against the same backend, per the spec's own growth path | weeks |
| Mobile apps proven | Add an API layer over the existing Actions | weeks |

**The rule:** no rung is climbed without a metric that says so. Premature extraction is the failure mode this architecture exists to prevent.

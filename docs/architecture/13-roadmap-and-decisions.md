# 13 · Roadmap & decision register

## 13.1 Technology stack — recommendation and rationale

The spec sets the baseline. We keep it, with one substitution and pinned versions.

| Layer | Choice | Why | Considered instead |
|---|---|---|---|
| Backend | **Laravel 11 (PHP 8.3)** | Spec baseline. Batteries-included for exactly this shape of product: queues, scheduling, policies, mail, storage, migrations. Fast to Phase 1, and it scales horizontally without ceremony | NestJS, Rails, Django — all viable, none better here, and all discard the spec's decision for no gain |
| UI | **React 19 + TypeScript (strict) via Inertia 2** | Spec baseline. One component library serving three areas without three SPAs or three API contracts | Next.js — better for a storefront-only product, but would fork the codebase into two stacks in Phase 1 |
| Storefront rendering | **Inertia SSR** | The spec's SEO requirement, met without a second application | Static generation — a live-stock marketplace invalidates too aggressively |
| Admin | **Third Inertia area, shared components** ⚠️ *substitution* | The prototype designs a bespoke admin (queue rails, required-reason dialogs, commission preview, snapshot money panels) against the Modernist system. Filament cannot reach that fidelity without fighting it, and would give us a second, divergent component library | **Filament v3** (spec's choice) — faster for generic CRUD, wrong for a designed admin. See Decision 2 |
| Database | **PostgreSQL 16** | Spec baseline. Correct choice: strong constraints, `numeric`, partial and generated indexes, FTS, JSONB, `pgvector` later | MySQL — weaker constraint and index story for this data model |
| Cache / sessions / locks | **Redis 7** | Standard, and required for the stateless app tier | — |
| Queues | **Laravel Horizon on Redis** | Spec's "built-in queue service", with per-queue isolation and a dashboard | SQS — adds latency and a console for no Phase 1 gain |
| Search | **Postgres FTS → Meilisearch → OpenSearch** behind one port | Ladder in [06](06-seo-and-search.md); no engine before its metric | Algolia — excellent, per-record pricing punishes a large catalogue |
| Object storage | **Cloudflare R2** | Spec baseline. Zero egress fees, S3-compatible, sits with the CDN | S3 + CloudFront |
| Payments | **Stripe Connect** behind `PaymentGateway` | Spec baseline and recommendation. Handles the split, seller KYC and automated payouts when we want them | PayPal Commerce, Adyen — all fit the same port |
| Email | **Postmark** (transactional) | Best-in-class deliverability for order mail; the thing customers notice when it fails | SES — cheaper, worse deliverability defaults |
| Edge | **Cloudflare** — DNS, CDN, WAF, bot, rate limit | Spec baseline | — |
| Errors / APM | **Sentry** | Spec's error-monitoring requirement | — |
| CI/CD | **GitHub Actions** | — | — |
| Hosting | **Laravel Forge or Cloud on a major provider** | Boring and recoverable; no orchestration complexity in Phase 1 | Kubernetes — explicitly out of scope, correctly |

## 13.2 Repository layout

```
veritas-commerce/
├── app/
│   ├── Modules/          Identity Sellers Catalogue Stock Cart Orders
│   │                     Payments Commission Payouts Notifications
│   │                     Media Search Recommendations Reporting
│   └── Support/          Money, Ulid, References, statusTone (shared kernel)
├── resources/
│   ├── js/
│   │   ├── design-system/    tokens · primitives · patterns · statusTone
│   │   ├── storefront/  seller/  admin/       Inertia pages per area
│   │   └── shared/
│   └── css/tokens.css        generated from the design system
├── routes/               storefront.php  seller.php  admin.php  webhooks.php
├── database/migrations/  (module migrations are auto-discovered)
├── tests/                Unit Feature Invariants Isolation E2E
├── design/prototype/     the delivered mockups + design system (reference)
├── docs/
│   ├── architecture/     these documents
│   └── source/           the original spec and prototype guide
└── deptrac.yaml          module boundary enforcement
```

## 13.3 Delivery plan

Twelve weeks to the Phase 1 definition of done, sequenced so the riskiest thing — money — is proven early and the last weeks are hardening, not building.

| Milestone | Weeks | Deliverable | Exit criteria |
|---|---|---|---|
| **M0 · Foundation** | 1 | Repo, modules, CI, tokens generated, design-system primitives, auth scaffolding, Deptrac and lint gates green | A styled page renders from tokens; CI blocks a boundary violation |
| **M1 · Identity & sellers** | 2–3 | Customer auth, admin auth with 2FA, seller application, admin review queue, approval, store setup with live preview, tenant isolation suite | A seller applies, admin approves, the public store page is live and isolation tests pass |
| **M2 · Catalogue** | 3–5 | Categories, brands, products, variants, images/media pipeline, draft/published, product review queue, storefront listing + product detail + store page, Postgres FTS search and facets | A seller publishes a product with variants; a customer finds it by search and by category |
| **M3 · Commerce core** | 5–7 | Cart, checkout, Stripe test mode, **stock holds and oversell prevention**, order + sub-orders, **commission snapshot**, order confirmation, seller order queue, status machine with tracking, customer tracking | The E2E customer and seller journeys pass, including the last-unit concurrency test |
| **M4 · Money** | 7–9 | Seller ledger, earnings statement, payout request and admin decision, refunds with reversal at stored rate, commission settings with forward dating and rate history, admin financial screens, reconciliation jobs | All money invariants pass; the admin E2E spec's two assertions pass |
| **M5 · Completeness** | 9–10 | Remaining admin screens (dashboard, sellers, taxonomy, orders, payments, settings), notifications matrix, all page states, demo seeder | Every one of the 39 screens exists and matches the prototype |
| **M6 · Responsive & a11y** | 10–11 | Six responsive patterns applied to all 39 screens, axe clean, keyboard passes | Lighthouse and axe gates green at 375 / 834 / 1280 |
| **M7 · Hardening & launch** | 11–12 | Load tests, penetration test, SEO (sitemaps, structured data, robots), backups and restore drill, runbooks, monitoring, staging UAT sign-off | The Phase 1 definition of done, in full |

**Phase 1 definition of done** (from the spec, unchanged): a customer can register, find a product, add it to the cart and complete a test checkout · the order is visible to the correct seller and to the admin · the seller can process it and see sales, commission and earnings · the seller can request a payout and the admin can review it · the admin dashboard shows the marketplace figures · permissions prevent anyone reaching an area they should not · the main pages work on modern desktop and mobile browsers — **all without editing the database by hand.**

## 13.4 Decision register

Twelve decisions need an owner. Four come from the prototype overview, six from the consistency review's handoff section, two from this analysis. Each carries a recommendation, so silence defaults to the recommendation rather than to a blocked build.

| # | Decision | Owner | Recommendation | Cost of deferring |
|---|---|---|---|---|
| **1** | **Multi-seller order numbering** | Product + Eng | One customer-facing number `VC-24081` with per-seller sub-orders `VC-24081-A`. It matches every screen already drawn and is what the data model in [03](03-data-model.md) implements | Cheap now, expensive after launch — the number appears in every email, support conversation and carrier record |
| **2** | **Admin: Filament or a third Inertia area** | Eng + Product | **Third Inertia area.** The prototype's admin is a designed product, not generic CRUD. Filament would create a second component library that drifts from the design system | Deciding late means rebuilding 15 screens. Decide before M1 |
| **3** | **Tax calculation source** | Finance | Flat rate per shipping state for the pilot, stored on the order like every other figure, with `tax_source` recorded. A provider slots behind the same field | Moderate — the checkout UI is identical either way; only the number's origin moves |
| **4** | **Guest checkout account claim** | Product | A signed single-use claim link in the confirmation email. Checkout and confirmation already support guests; only the email and a claim route are new | Low — can ship after launch without touching any designed screen |
| **5** | **When does earning become withdrawable** | Finance | Post to the ledger at **Delivered**, withdrawable after a **7-day clearing window**. Protects against refund-after-payout, the most expensive marketplace failure | Must be decided before M4. Changing it later means re-deriving every balance |
| **6** | **Product review: on by default at launch?** | Product | **On for a seller's first 5 listings, then off.** Gets moderation quality without a queue that scales with the catalogue. The settings toggle already allows either | Low — the toggle exists; only the default and the auto-graduation rule are new |
| **7** | **Refund authority** | Product + Ops | Admin-only in Phase 1, as the prototype draws it (the seller's order screen says "refunds are handled by the marketplace team") | Low, but must be consistent between the two screens that mention it |
| **8** | **Email templates** | Product + Design | The matrix of sends is specified; the artwork is not. Commission a template set against the design system in M5 | Medium — order emails are the most-read surface in the product |
| **9** | **Returns / RMA beyond "Request a return"** | Product | Out of Phase 1 scope as written. The button opens a support contact flow; a real RMA workflow is Phase 2 | Low if stated now; high if a customer expects a process that does not exist |
| **10** | **Shipping cost model** | Product + Finance | Per-seller flat rate with a free-shipping threshold (both already in store setup and platform settings). Live carrier rates need weights and dimensions — explicitly Phase 1.1 | Low — the fields exist |
| **11** | **Domain, brand assets, legal entity** | Client | Needed for M0 (token rename, email sending domain, SPF/DKIM/DMARC) and for the storefront footer and invoices | Blocks email deliverability setup if left late |
| **12** | **Production payment mode at launch** | Client + Finance | The spec says test mode for the pilot. Confirm whether launch is a closed pilot (test mode) or takes real money (live mode, which pulls Stripe Connect onboarding and KYC into M4) | High — live mode adds seller identity verification to the seller onboarding flow |

## 13.5 Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Balance or commission drift | Low | Critical | Append-only ledger, invariant tests on every commit, nightly reconciliation with paging alerts |
| Cross-seller data leak | Medium | Critical | Three isolation layers + an adversarial test suite ([08](08-identity-roles-stores.md#83-tenant-isolation)) |
| Oversell on a hot SKU | Medium | High | Row-locked holds with deterministic lock ordering; a concurrency load test in the gate |
| Scope creep past Phase 1 | **High** | High | The scope boundary in [01](01-product-scope.md#15-phase-1-scope-boundary) and the feedback protocol in [12](12-quality-observability-delivery.md#128-the-client-feedback-protocol); every new ask is classified before it is estimated |
| Design drift across three areas | Medium | Medium | One shared component library, `statusTone()`, no-raw-hex lint, visual regression tests |
| SEO debt from faceted URLs | Medium | High | The indexation policy in [06](06-seo-and-search.md#64-indexation-control) shipped at launch, not retrofitted |
| Stripe Connect onboarding friction | Medium | Medium | Depends on Decision 12; if live mode, KYC enters the seller flow and M1 grows |
| Deliverability of order email | Medium | High | Postmark, dedicated sending domain, SPF/DKIM/DMARC configured in M0 — depends on Decision 11 |
| Recommender degrades the storefront | Low | Medium | Guardrails in [07](07-recommendations.md#75-guardrails); every rail A/B tested against its fallback or removed |

## 13.6 What we need before writing application code

1. **Decisions 1, 2, 5, 11 and 12** — they change the data model, the admin stack, the ledger and onboarding respectively.
2. Confirmation that the **Phase 1 scope boundary is frozen** (the prototype guide's sign-off checklist).
3. Brand assets and the domain.
4. A Stripe account (test at minimum) and a Postmark/SES account.

Everything else can proceed under the recommendations above, and will, unless told otherwise.

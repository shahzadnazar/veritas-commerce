# Veritas Commerce Marketplace — Build Architecture

**Status:** Draft for approval · **Date:** 31 August 2026 · **Branch:** `claude/veritas-marketplace-architecture-vov8c0`

This directory is the engineering answer to three inputs:

| Input | What it fixes |
|---|---|
| `docs/source/Marketplace_Phase1_Product_Spec.md` (and the identical `.docx`) | Product scope, commission model, technology baseline, what is explicitly out of scope. |
| `docs/source/Veritas_Commerce_Marketplace_Prototype_Guide.pdf` | Governance: what the prototype is and is not, the decisions the client must lock, the sign-off checklist and the feedback protocol. |
| `design/prototype/` (the UI mockups + design system zip) | 39 screens, 44 components, the token sheet, the status taxonomy, six responsive patterns, and a 14-point consistency audit with five findings. |

Read them in order:

| # | Document | What it settles |
|---|---|---|
| 01 | [Product scope & journeys](01-product-scope.md) | What we are building, for whom, screen by screen; Phase 1 vs the Amazon-level north star. |
| 02 | [System architecture](02-system-architecture.md) | Modular monolith, module boundaries, deployment topology, the scale ladder. |
| 03 | [Data model](03-data-model.md) | Every core table, the invariants, indexes, and why money is an integer. |
| 04 | [Money, commission & payouts](04-money-and-commission.md) | The snapshot rule, the ledger, refund reversal, payout lifecycle, reconciliation. |
| 05 | [Inventory](05-inventory.md) | On hand / held / available, oversell prevention, append-only movements. |
| 06 | [SEO & search](06-seo-and-search.md) | SSR strategy, URL scheme, structured data, facet crawl control, the search engine ladder. |
| 07 | [Recommendations & suggestions](07-recommendations.md) | The suggestion system: three tiers from rules to embeddings, event pipeline, guardrails. |
| 08 | [Identity, roles & store management](08-identity-roles-stores.md) | Three guards, the RBAC matrix, seller lifecycle, tenant isolation. |
| 09 | [Security](09-security.md) | Threat model, OWASP mapping, payment/PII handling, compliance. |
| 10 | [Performance & scalability](10-performance-scale.md) | Caching layers, query budgets, capacity targets, the growth path. |
| 11 | [Design system implementation](11-design-system.md) | Turning `design/prototype` into code: tokens, 44 components, `statusTone()`, responsive patterns, accessibility. |
| 12 | [Quality, observability & delivery](12-quality-observability-delivery.md) | Testing pyramid, CI/CD, monitoring, backups, runbooks. |
| 13 | [Roadmap & decision register](13-roadmap-and-decisions.md) | Milestones, definition of done, the three decisions taken, and the nine that still need an owner. |

## Decisions taken so far

| # | Decision | Outcome |
|---|---|---|
| 2 | Admin stack | A third Inertia area sharing the design system — not Filament |
| 5 | When earning becomes withdrawable | Posted at Delivered, withdrawable after a 7-day clearing window |
| 12 | Payment mode at launch | Stripe test mode for the Phase 1 pilot, as the specification states |

Two decisions still block the start of implementation: **order numbering (1)** and **domain, brand assets and legal entity (11)**. See [13](13-roadmap-and-decisions.md#137-what-we-still-need-before-writing-application-code).

## The one-paragraph version

Veritas Commerce is a three-audience marketplace — customer storefront, seller operating portal, admin control centre — built as **one Laravel application organised into hard-bounded internal modules**, served through Inertia + React + TypeScript with server-side rendering for the storefront, on PostgreSQL with Redis, queues, object storage and Stripe Connect behind a provider-agnostic payment port. The commercial core is a **commission snapshot**: when an order completes it permanently stores its own total, commission amount, seller earning and the rate that produced them, and nothing recalculates them ever again. Every financial and inventory movement is an **append-only row**, never an update. That single discipline is what makes the marketplace auditable, and it is the constraint every other decision in these documents bends around.

## What we changed from the source documents, and why

1. **Rebrand.** Every prototype screen says `MARKETHUB` / `markethub.com`. The product is **Veritas Commerce**. This is a token-level and copy-level rename, not a design change — see [11-design-system.md](11-design-system.md#brand-rename).
2. **Ambition vs Phase 1.** The brief says "no functionality invented beyond Phase 1". The stated goal is an Amazon-level marketplace. These are not in conflict: Phase 1 is the *foundation*, and every document below marks Phase-1 scope separately from the *scale path* so we build the foundation in a shape that does not have to be torn out. Nothing outside Phase 1 gets built now; everything outside Phase 1 gets a seam now.
3. **Admin panel technology.** The spec proposes Filament. The prototype designs a bespoke admin against the Modernist design system (queue rails, required-reason dialogs, commission preview, snapshot money panels). Filament cannot reach that fidelity without fighting it. Admin is built as a **third Inertia area** sharing the same component library — Decision 2, settled 31 Aug 2026.
4. **Findings adopted.** All five findings from the Phase 6 consistency review are carried into implementation requirements, including the single `statusTone()` helper and the no-raw-hex / accent-contrast lint rules.

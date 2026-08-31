# M0 — executable foundation: completion report

**Branch:** `claude/veritas-marketplace-architecture-vov8c0` · **Date:** 31 August 2026

All ten verification gates pass. Nothing below is claimed as implemented unless it exists in the repository and its gate is green.

## 1. Versions selected

| Layer | Version | Note |
|---|---|---|
| PHP | 8.4.19 | |
| Laravel | 13.29.0 | **Changed from the plan's Laravel 11** — 13 is current; see §11 |
| Inertia (server) | inertiajs/inertia-laravel 3.3.1 | |
| Inertia (client) | @inertiajs/react 2.3.27 | |
| React | 19.2.8 | |
| TypeScript | 5.9.3, `strict` + `noUncheckedIndexedAccess` + `exactOptionalPropertyTypes` | |
| Vite | 8.2.2 | Three entry points + one SSR entry |
| Tailwind | 4.x, via `@tailwindcss/vite` | |
| PostgreSQL | 16.13 | |
| Redis | 7 | |
| Testing | PHPUnit 12.5.34 | **Changed from Pest** — see §11 |
| Code style | Laravel Pint 1.30.5, Prettier 3.9.6, ESLint 9.39.5 | |
| Routing helper | tightenco/ziggy 2.6.4 | |

## 2. Repository structure

```
app/
├── Modules/                19 domain modules (see §3)
├── Support/                shared kernel — Money, Reference, HasPublicId,
│                           StatusTone, StatusRegistry
├── Http/Middleware/        HandleInertiaRequests, EnsureSellerMembership,
│                           EnsureAdminPermission
├── Console/Commands/       ExportStatusPresentation
└── Providers/
resources/
├── css/                    tokens.css (Modernist values) + app.css
└── js/
    ├── design-system/      primitives · patterns · layout · generated/
    ├── storefront/         main.tsx · ssr.tsx · pages/
    ├── seller/             main.tsx · pages/
    ├── admin/              main.tsx · pages/
    └── shared/
routes/                     storefront.php · seller.php · admin.php · console.php
database/                   11 migrations · 25 factories · 3 seeders
tests/                      Invariants/ · Unit/ · Feature/
docker/                     app + node Dockerfiles, postgres init
design/prototype/           the delivered UI and design system (reference)
docs/architecture/          the 14 planning documents and this report
```

## 3. Modules created

`Identity · Customers · Sellers · Stores · Catalog · Offers · Inventory · Cart · Orders · Fulfillment · Payments · Commission · Ledger · Payouts · Search · Events · Notifications · Audit · AdminPortal`

Each has `Contracts/ Data/ Models/ Actions/ Queries/ Events/ Policies/ Enums/ Http/ Database/`. Boundaries are enforced by `tests/Invariants/ModuleBoundaryTest.php`: a module may use another's Contracts, Data, Enums and Events, never its Models, with a short documented allowlist for pairs that are genuinely coupled (tenancy, store↔seller, membership↔user).

Modules that are directory-only at M0 — no behaviour yet, by design: Customers, Cart, Fulfillment, Search, Notifications, Audit (the audit *table* exists; the writer lands with the actions that need it).

## 4. Database — 48 tables

**Identity** users · password_reset_tokens · sessions · admin_users · customer_addresses
**Sellers** seller_applications · seller_accounts · stores · store_slug_history · seller_memberships · seller_account_events
**Catalogue** categories · brands · brand_aliases · products · product_variants · product_media
**Offers** offers · offer_media
**Inventory** inventory_locations · inventory_balances · inventory_reservations · inventory_movements
**Orders** reference_sequences · marketplace_orders · seller_orders · order_items · order_status_history · shipments
**Money** commission_rules · seller_ledger_entries · seller_bank_accounts · payout_requests
**Payments** payment_attempts · payments · refunds · provider_webhook_events
**Other** carts · cart_items · interaction_events · audit_logs · platform_settings
**Framework** cache · cache_locks · jobs · job_batches · failed_jobs · migrations

Money is `bigint` minor units plus an explicit `char(3)` currency, everywhere. Every externally visible record carries a ULID `public_id`. Two constraints are worth naming: a **partial unique index** enforces one open payout request per seller, and a **unique (provider, event_id)** makes webhook replay impossible to double-post.

## 5. Key relationships

```
User ──< SellerMembership >── SellerAccount ──< Store
                                    │
Category ──< Product ──< ProductVariant       │
                │              │              │
                └──────< Offer >──────────────┘        canonical product,
                          │                            many seller offers
                          ├──< InventoryBalance
                          ├──< InventoryReservation
                          └──< InventoryMovement

MarketplaceOrder (VC-24081)
  └──< SellerOrder (VC-24081-01, -02, -03)   one per seller
         ├──< OrderItem          ← the financial snapshot lives here
         ├──< OrderStatusHistory   (append-only)
         └──< Shipment

SellerAccount ──< SellerLedgerEntry (append-only) ──> PayoutRequest
CommissionRule (append-only) ──> order_items.commission_rule_id
```

## 6. Enums and state machines

Workflows: `SellerApplicationStatus` · `SellerStatus` · `OfferStatus` · `MarketplaceOrderStatus` · `SellerOrderStatus` · `PayoutStatus` · `LedgerEntryStatus` · `ReservationStatus` — each owns its own `allowedTransitions()` and `isTerminal()`.

Labels: `LedgerEntryType` · `InventoryMovementReason` · `PaymentStatus` · `CommissionScope` · `AdminRole` · `AdminPermission` · `SellerRole` · `InteractionEventType`.

`SellerOrderStatus` also declares the rules the UI and the actions both read: `requiresTracking()`, `isCustomerCancellable()`, `postsEarning()` (Delivered only), `holdsInventory()`.

## 7. Authorization model

- **Two realms.** Customers and sellers share the `web` guard; platform staff use a separate `admin` guard, table, session cookie and shorter idle expiry. A customer session is worthless against `/admin` — proven by test.
- **Seller scope** is derived from an accepted `SellerMembership`, never from the request. `BelongsToSellerAccount` adds a global scope and *overrules* a supplied `seller_account_id` while acting as a seller.
- **Staff roles** Owner / Operations / Finance / Support, with a 14-permission matrix asserted test-by-test against the documented table.
- **Escape hatch** `CurrentSeller::withoutScope()` — explicit, named, used only by admin reads.

## 8. Shared frontend components

`Button` (4 variants, flush-left labels, loading holds width) · `Field` + `Input` / `Textarea` / `Select` · `StatusBadge` · `Table` + `Column` + `NoFigure` · `Modal` (focus trap, Escape, names its consequence) · `EmptyState` / `ErrorState` / `SuccessState` · `TableSkeleton` / `CardGridSkeleton` · `Wordmark` · `StorefrontLayout` · `PortalLayout`.

Shells: storefront Home + Auth/Login, seller Dashboard, admin Dashboard + Login. Product imagery renders in full colour — the prototype's grayscale treatment is a presentation choice for the mockups, not carried into the commerce UI.

## 9. Test results

```
Invariants   68 tests    705 assertions   PASS
Unit         19 tests   7037 assertions   PASS
Feature      10 tests     61 assertions   PASS
──────────────────────────────────────────────
TOTAL        97 tests   7803 assertions   PASS
```

The eight required invariants, and where each is proven:

| # | Invariant | File |
|---|---|---|
| 1 | Seller A cannot reach Seller B's data | `SellerIsolationTest` (7) |
| 2 | Commission config changes never alter historical snapshots | `CommissionSnapshotTest` (6) |
| 3 | Offer price changes never alter historical order items | `PriceSnapshotTest` (3) |
| 4 | A payout cannot exceed the available ledger balance | `PayoutBalanceTest` (11) |
| 5 | A replayed webhook cannot double-post | `WebhookIdempotencyTest` (7) |
| 6 | Reservations prevent overselling | `InventoryTest` (9) |
| 7 | Release restores reserved inventory exactly | `InventoryTest` |
| 8 | Every UI status has a shared presentation mapping | `StatusPresentationTest` (4) |

Plus `StateMachineTest` (9), `AuthorizationTest` (9), `ModuleBoundaryTest` (3).

## 10. Gates run before declaring M0 complete

| Gate | Result |
|---|---|
| Migrations on a clean PostgreSQL database (`migrate:fresh --seed`) | PASS — 48 tables |
| Pint code style | PASS |
| Architectural invariants | PASS |
| Unit suite | PASS |
| Feature suite | PASS |
| Full backend suite | PASS |
| `statuses:export --check` | PASS |
| TypeScript `--noEmit` (strict) | PASS |
| ESLint (`--max-warnings=0`) | PASS |
| Prettier `--check` | PASS |
| Production build, client + SSR | PASS |

Also smoke-tested over real HTTP: `/` → 200, `/admin` guest → 302 to `/admin/login`, `/seller` guest → 302 to `/login`, `/admin/login` → 200, portals carry `noindex`, storefront does not.

## 11. Architecture decisions that changed during implementation

| # | Change | Reason |
|---|---|---|
| 1 | **Laravel 13.29, not Laravel 11** | 11 is superseded; 13 is what `composer create-project` installs today. No architectural consequence — the modular-monolith shape is unchanged. |
| 2 | **PHPUnit 12.5.34, not Pest** | Pest cannot be installed in this session: `codeload.github.com` returns 403 under the environment's egress policy, and Pest's dist archives resolve there. PHPUnit ships with the framework and works. The tests are unaffected in substance; converting to Pest later is mechanical. |
| 3 | **A repo-native `ModuleBoundaryTest`, not Deptrac** | Same egress restriction. The test asserts the same rule and has the advantage of running inside the normal suite. |
| 4 | **No PHPStan/Larastan gate at M0** | Same restriction. Mitigated by TypeScript `strict` on the frontend, Pint on the backend, and `Model::preventLazyLoading` + `preventSilentlyDiscardingAttributes` failing loudly outside production. **This is a real gap** — see §12. |
| 5 | **`BelongsToSellerAccount` overrules a supplied `seller_account_id`** rather than only filling a blank one | The first version let a supplied id win, which its own test caught: a request could have planted a row in another seller's account. While acting as a seller, ownership is now forced. |
| 6 | **`RecordWebhookEvent` uses `INSERT … ON CONFLICT DO NOTHING`** rather than catching the unique violation | In PostgreSQL a failed statement aborts the surrounding transaction, so catching the violation inside a handler already in a transaction poisoned every later statement. Caught by the idempotency test. |
| 7 | **`GetSellerBalance` counts an open payout reservation twice** — as a debit against available *and* as the held figure | The first version reported held correctly but left the money still available, so a seller could request it twice. Caught by `PayoutBalanceTest`. |
| 8 | **Generated files excluded from Prettier** | Prettier reformatted `generated/statuses.ts`, breaking the byte-for-byte CI comparison that makes the status map trustworthy. |
| 9 | **Inventory is keyed by `offer_id`, not a product/variant id** | Stock belongs to a seller's listing, not to the canonical product — three sellers offering one iPhone hold three independent stock positions. |

## 12. Blockers and gaps before M1

**Environment (blocking CI parity, not the code):**
1. `codeload.github.com` is 403 under this session's egress policy, so Pest, PHPStan/Larastan and Deptrac cannot be installed here. The CI workflow runs on GitHub-hosted runners with normal egress, so **adding a PHPStan job to `.github/workflows/ci.yml` is the first M1 task** — the level-8 gate the architecture calls for is currently absent.

**Product decisions still open** (from the decision register; none blocked M0):
2. **Decision 1** is implemented as recommended (`VC-24081` / `VC-24081-01`) and now has a schema behind it. Confirm it, or changing it gets more expensive from here.
3. **Decision 11** — domain, legal entity and brand assets. M0 uses configuration with development placeholders throughout (`config/veritas.php`, `platform_settings`), so nothing is hard-coded, but real values are needed for email authentication in M1.

**Scope notes for M1:**
4. Authentication is scaffolded but the credential flows (registration, reset, guest-order claim, seller application submission, admin 2FA verification) are M1 — the admin login controller authenticates but does not yet verify the TOTP code it accepts.
5. `docker compose` is written but unbuilt in this environment (no image pull was attempted); it needs one `docker compose up` on a machine with registry access before it is trustworthy.

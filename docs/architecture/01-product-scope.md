# 01 · Product scope & journeys

## 1.1 What the product is

A general-goods multi-vendor marketplace, USD, US shipping, where independent sellers run their own branded stores inside one storefront, one cart and one checkout, and the platform earns a commission on every completed sale.

Three surfaces, one application, one design system:

| Surface | Audience | Density | Purpose |
|---|---|---|---|
| **Customer storefront** | Shoppers, guests and registered | 15px body, 24px gaps, full-bleed imagery | Discover, evaluate, buy, track |
| **Seller portal** | Approved marketplace sellers | 14px body, 13px tables, 2px gaps, 240px sidebar | Apply, set up store, list, stock, fulfil, earn, withdraw |
| **Admin control centre** | Platform staff (Owner, Operations, Finance, Support) | Highest density, every row reviewable | Approve, moderate, monitor, set commission, settle |

The density step between storefront and portals is the **only** intentional visual divergence. Same tokens, same components, different breathing room.

## 1.2 Screen inventory (39 screens, from the prototype)

**Customer storefront — 14 screens**
Home · Category listing · Search results · Empty search · Loading · Product detail · Seller store page · Cart · Empty cart · Checkout · Order confirmation · Auth (sign in / register / reset / check-email) · Account profile · Saved addresses · Order history · Order tracking

**Seller portal — 10 screens**
Application (form / submitted / approved / rejected states) · Dashboard · Store setup with live preview · Product list · Add product · Edit product · Inventory + movement history · Order list · Order detail · Earnings statement · Payouts

**Admin portal — 15 screens**
Login (2FA) · Dashboard · Sellers list · Seller detail · Application review queue · Product review queue · Categories · Brands · All orders · Order detail · Payments · Refunds · Commission settings · Seller earnings · Payout requests · Platform settings

**Responsive — 9 mobile + 1 tablet reference implementations**, with all 39 screens tagged against six named patterns (see [11-design-system.md](11-design-system.md#responsive)).

## 1.3 The three journeys — must work end to end and connect

```
CUSTOMER   register/guest → search & filter → pick variant → cart → checkout
           → test payment → confirmation + order number → track Placed→Delivered

SELLER     apply → approved → store setup (name, slug, logo, banner, policies)
           → list products with variants + stock → receive order in queue
           → pack → add carrier + tracking → ship → see commission & earning
           → request payout

ADMIN      review application → approve seller → review & approve products
           → set commission rate → monitor orders & payments → handle refunds
           → approve or reject payout
```

They **connect**: an order placed in the storefront appears in the correct seller's queue and in the admin table under the same number; a payout requested by a seller lands in the admin queue and its decision returns to the seller's ledger. Testing these three end-to-end chains is the acceptance criterion for Phase 1, not screen-by-screen sign-off.

## 1.4 The rule that ties the product together

> When an order completes it stores its own **order total**, **platform commission**, **seller earning** and the **commission rate that produced them**. Nothing recalculates. Change the platform rate tomorrow and every historical order, statement and revenue figure stays exactly as it was.

This is surfaced in the UI in four places — seller order detail, admin order detail, the earnings ledger, and the commission settings screen — because the prototype treats it as a product feature, not an implementation detail. See [04-money-and-commission.md](04-money-and-commission.md).

## 1.5 Phase 1 scope boundary

**In scope now** (from the spec, unchanged):
accounts · seller onboarding & approval · store branding & policies · catalogue with categories and brands · products with variants, images, draft/published · inventory with movements · cart · checkout with Stripe test mode · orders with status history · payments and refunds · commission snapshot · seller earnings ledger · payout requests and admin decisions · email notifications · role-based access · responsive web · demo data.

**Explicitly deferred** (do not build, but leave a seam):

| Deferred | Seam we leave now |
|---|---|
| iOS / Android apps | All business logic behind service classes; an HTTP API layer is a thin addition, not a rewrite |
| Multiple warehouses | `stock_levels` keyed by `(variant_id, location_id)` with a single default location row |
| Courier integrations | `shipments` table with carrier + tracking as free text; a `carrier_code` column reserved |
| Automatic bank payouts | `payouts` records the decision; a `settlement_ref` column and a `PayoutGateway` port are reserved |
| Fraud engine | Every payment attempt, address and device fingerprint already stored as its own row |
| AI recommendations / chatbot | Event stream (`product_viewed`, `added_to_cart`, `ordered`) captured from day one — see [07](07-recommendations.md) |
| Microservices / Kubernetes | Module boundaries enforced in code so extraction is mechanical — see [02](02-system-architecture.md) |
| Multi-country tax | `tax_amount_minor` + `tax_rate_snapshot` + `tax_source` on every order line |

**Phase 1.1 convenience features** (deferred, seams already designed): bulk CSV upload · packing slips · duplicate product · weight & dimensions · time-series charts · reviews & ratings · wishlist · coupons · admin activity log.

## 1.6 "Amazon-level" — what that actually commits us to

The phrase is a north star, not a Phase 1 requirement. Concretely it means the foundation must not block these later, and each is addressed in the document named:

| Amazon-level capability | Where the foundation is laid |
|---|---|
| Catalogue of millions of SKUs, sub-second facets | [06 · search ladder](06-seo-and-search.md) — Postgres FTS → Meilisearch → OpenSearch behind one `SearchPort` |
| Personalised recommendations everywhere | [07 · recommendations](07-recommendations.md) — event stream from day one, three-tier rollout |
| Organic search as the primary acquisition channel | [06 · SEO](06-seo-and-search.md) — SSR, URL scheme, structured data, facet crawl control from launch |
| Thousands of concurrent sellers, isolated | [08 · tenant isolation](08-identity-roles-stores.md) and [09 · security](09-security.md) — global scopes + policy tests |
| Money that reconciles to the cent, forever | [04 · ledger invariants](04-money-and-commission.md) |
| Never oversell | [05 · inventory](05-inventory.md) — atomic holds under row locks |
| Horizontal scale without a rewrite | [02 · scale ladder](02-system-architecture.md) and [10 · performance](10-performance-scale.md) |

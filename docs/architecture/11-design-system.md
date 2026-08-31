# 11 · Design system implementation

The design system is not a mood board — `design/prototype/` contains a working token sheet, 44 components, a status taxonomy, six responsive patterns and a completed 14-point audit. This document turns it into code without losing anything.

## 11.1 What we were given

```
design/prototype/
├── 00 Overview.dc.html          6 files, 3 journeys, 4 open decisions
├── 01 Design System.dc.html     13 sections, 44 components, status taxonomy
├── 02 Customer Storefront.dc.html   14 screens, live cart & checkout state
├── 03 Seller Portal.dc.html         10 screens, application → payout
├── 04 Admin Portal.dc.html          15 screens, queues & commission
├── 05 Responsive.dc.html            9 mobile + 1 tablet, 6 patterns, 39 mapped
├── 06 Consistency Review.dc.html    14 checks, 5 findings, reuse map
└── _ds/modernist-…/
    ├── styles.css               THE token sheet — source of truth
    ├── readme.md                the system's own rules
    └── _ds_manifest.json        machine-readable token list
```

Every prototype screen carries **dev annotations** describing behaviour a static mockup cannot show — what validates when, what demands a reason, what is append-only, what must never be recalculated. Those annotations are extracted into the module documents in this directory; they are requirements, not commentary.

## 11.2 Brand rename

Every screen reads `MARKETHUB` and `markethub.com`. The product is **Veritas Commerce**.

- The wordmark is set in Archivo 800 with the second half in the accent — `MARKET` + accent `HUB` becomes **`VERITAS`** + accent **`COMMERCE`**. Same construction, same weight, same colour rule.
- All copy strings move into i18n files at build time (`en.json`), so the marketplace name is one key (`brand.name`), not 200 hard-coded strings. Store URLs become `veritascommerce.com/store/{slug}`.
- Order references change from `MH-` to **`VC-`**; applications stay `APP-`, payouts stay `PO-`.
- **Nothing else about the design changes.** The Modernist system stays exactly as specified.

## 11.3 Tokens — one source, generated, never hand-copied

`styles.css` is the source of truth. A build step parses it into typed artefacts so no value is ever transcribed:

```
design/prototype/_ds/…/styles.css
        │  npm run tokens:build
        ├─► resources/css/tokens.css      CSS custom properties (the runtime)
        ├─► resources/js/design-system/tokens.ts   typed constants for TS
        └─► tailwind.config.ts            theme extension mapping to var(--…)
```

The full token set:

| Group | Values |
|---|---|
| Ground | `--color-bg #f3f2f2`, `--color-surface #eae9e9` |
| Ink | `--color-text #201e1d`, `--color-divider` = 40% ink |
| Accent | `--color-accent #ec3013` + a 100–900 OKLCH ramp |
| Neutral | 100–900 ramp (`#f8f4f4` → `#2d2b2b`) |
| Type | Archivo 800 headings / 400–600 body, self-hosted |
| Space | 4 · 8 · 12 · 16 · 24 · 32 · 56 |
| Radius | **0px everywhere. No exceptions.** |
| Elevation | `--shadow-sm/md/lg`; flat is the default |

**Two lint rules make this stick** (both called for by the Phase 6 review):

1. **No raw hex, no raw font name, no raw px where a token exists** — anywhere in `resources/`. A stylelint rule plus an ESLint rule on inline styles.
2. **Accent text below 18px must come from `--color-accent-700` or darker.** The accent-on-ground pair clears 3:1 — enough for icons, large text and chrome, not for body copy. The prototype flags the low-stock line and the commission figure as the two places that sail close.

## 11.4 The 44 components

Built once in `resources/js/design-system/`, consumed by all three areas. From the prototype's reuse map, **38 of 44 appear in at least two applications** — those live in the shared library and take a `density` prop. The six single-purpose ones live with their area.

| Group | Components |
|---|---|
| **Actions** | Button (primary · secondary · ghost · destructive · icon · block · loading), Dropdown menu |
| **Forms** | Field + Label, Input, Textarea, Select, Multi-select, Checkbox, Radio, Toggle, Segmented control, Search input, Date range, Image uploader, FormSection (numbered) |
| **Data** | Table (+ sticky header, row rules, tabular money), Filters bar, Active-filter chips, Pagination, StatCard, CommissionSplitBar, StatusBreakdownBars, OrderTrackingRail, Specification list |
| **Structure** | Storefront header (64px) + category row, Portal sidebar (240/64), Page-title bar, Breadcrumbs, Tabs, Card, ProductCard, SellerCard, QueueList (rail + record) |
| **Feedback** | Tag / status badge, Alert (3 tones), Toast, Modal, ConfirmationDialog (+ required-reason variant), Drawer / bottom sheet, Tooltip |
| **States** | Skeleton (card grid · table at real column widths · form submitting), EmptyState, ErrorState, SuccessState |

Component rules carried from the prototype into the implementation:

- **One primary action per view**, always rightmost in an action row, always the action that advances the flow.
- **Destructive actions never take the solid accent fill at rest** — only the confirm button inside their confirmation dialog.
- **Button labels are flush left**, including in full-width buttons. Nothing is centred.
- **Loading buttons swap the label to the present participle and hold their width** so the layout does not jump.
- **Validation on blur, then live once a field has errored.** Errors are a 2px accent border *plus* a message under the field — never a tooltip, never colour alone.
- **Tables**: uppercase 11px header, 1px row rules, money right-aligned and `tabular-nums`, status second-from-last, row action last, and **an em dash rather than `$0.00`** when no figure was ever captured. That distinction (a failed payment never produced a commission) is a correctness rule, not a formatting preference.
- **Filters write to URL query params** so a filtered view is shareable and back-button safe.
- **Modals are for short decisions and always name the consequence.** Anything longer than two fields becomes a drawer or a page.
- **Toasts**: bottom-left, 4s, max 3 stacked, never for an error that needs a decision. Customer toasts may offer Undo; portal toasts confirm only.

## 11.5 `statusTone()` — Phase 6 finding 1, fixed before the first screen

The audit's only "fix before build": the status→tone mapping currently lives in three separate lookup tables, one per prototype file. They agree today; the first status added after handoff will only be added to one.

```ts
// resources/js/design-system/statusTone.ts — the ONLY mapping in the product
export type Tone = 'neutral' | 'pending' | 'critical' | 'inactive';

export const STATUS_TONE = {
  // product
  draft: 'inactive', published: 'neutral', pending_review: 'pending',
  rejected: 'critical', archived: 'inactive',
  out_of_stock: 'critical', low_stock: 'pending',
  // order
  placed: 'neutral', processing: 'pending', packed: 'pending',
  shipped: 'pending', delivered: 'neutral',
  cancelled: 'inactive', refunded: 'critical',
  // payment
  payment_pending: 'pending', paid: 'neutral',
  payment_failed: 'critical', payment_refunded: 'critical',
  // seller
  seller_pending: 'pending', approved: 'neutral',
  seller_rejected: 'critical', suspended: 'critical',
  // payout
  requested: 'pending', payout_approved: 'neutral',
  payout_rejected: 'critical', payout_cancelled: 'inactive',
} as const satisfies Record<string, Tone>;
```

A mirrored PHP enum-to-tone map is generated from the same source, and **a test asserts every status value the API can emit has a mapping**. Only four semantic fills exist — never green, amber or blue. In a mono system status is carried by fill weight and label, not hue.

## 11.6 The status taxonomy (complete, from the prototype)

| Domain | States |
|---|---|
| **Product** | Draft · Published · Pending review · Rejected · Archived · Out of stock · Low stock (≤5) |
| **Order** | Placed · Processing · Packed · Shipped · Delivered · Cancelled · Refunded |
| **Payment** | Pending · Paid · Failed (retry allowed) · Refunded |
| **Seller** | Pending · Approved · Rejected · Suspended |
| **Payout** | Requested (balance held) · Approved (balance reduced) · Rejected (reason recorded, balance released) |

## 11.7 Layout metrics

| Metric | Value |
|---|---|
| Storefront container | 1280 max |
| Portal container | fluid, 40 gutter |
| Grid | 12 col · 24 gap |
| Sidebar | 240 / 64 collapsed |
| Header height | 64 storefront · 56 portal |
| Product grid | 4-up ≥1200 · 3 · 2 · 2 |
| Breakpoints | 1200 / 900 / 600 |
| Section rule | 2px divider |
| Density step | storefront 15px/1.55 body, 24px gaps · portals 14px/1.45, 13px tables, 2px gaps |

## 11.8 Responsive — six patterns, all 39 screens mapped {#responsive}

| Key | Pattern | Behaviour |
|---|---|---|
| **A** | Stacked flow | One column, full-width fields and cards, 44px targets, no sticky bar |
| **B** | Sticky action bar | Content scrolls; the primary action pins to the bottom edge carrying its live value |
| **C** | Sheet | Filters, sorts and required-reason dialogs rise from the bottom over a scrim; keyboard-safe |
| **D** | Rows as cards | Each table row becomes a card with four fields and one action — **a different component, not a squeezed table** |
| **E** | Nav drawer + tab bar | Storefront gets a four-item tab bar; portals get a drawer behind the hamburger |
| **F** | Wide-table fallback | Headline figures, a CSV escape hatch, and a sentence explaining why this needs a wider screen |

Rules that hold at every width: touch targets never below 44px · body never below 14px, table text never below 13px · the primary action always visible without scrolling · no horizontal scroll except the one place it is allowed (seller status filter chips) · nothing needed to complete a task is removed on mobile, it moves · 2px rules and 0px radius everywhere.

Notable specifics: the product page's **buy bar is `position: sticky; bottom: 0`** and always shows the live total — the one element that must never require scrolling. The gallery becomes a swipe carousel with dots, not a thumbnail rail. The add-product form splits into four steps with a persistent draft so a seller can start on a phone and finish on a desktop. Admin's wide analytical tables are **not** rebuilt for 375px — they show the fallback, which is more honest than a table nobody can read.

## 11.9 Accessibility — WCAG 2.2 AA

- Colour is never the sole carrier of meaning: every status badge has a text label; every error has a message, not just a border.
- **Focus is visible and themed**: `:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 2px }`. Never the default blue, never removed.
- Full keyboard operability, including modals (focus trap, Escape closes, focus returns to the trigger) and the sheet pattern.
- Semantic HTML: real `<table>` for tables, real `<button>` for actions, real `<label>` bound to every input, one `<h1>` per page, landmarks on every region.
- `aria-live="polite"` on toasts and on the cart count; `aria-busy` on skeletons.
- Contrast: ink on ground is well past 4.5:1; the **accent is chrome-only below 18px** (§11.3 rule 2).
- Reduced motion honoured — the skeleton shimmer and all transitions respect `prefers-reduced-motion`.
- Automated axe checks in CI on every page state, plus a manual keyboard-and-screen-reader pass on the three journeys before launch.

## 11.10 Page states — specified per screen, not generically

The prototype draws all four for real screens, and the Phase 6 review closed the gap on tables and forms:

- **Loading**: skeletons that mirror the real layout, shown after 200ms only. The table skeleton uses the real column widths with only the first column shimmering — a full grid of moving bars reads as noise. The form-submitting state locks fields to 45% and holds the button width. Never a full-page spinner on a route with a known shape.
- **Empty**: never a dead end. Empty search offers three exits; empty cart, empty product list and empty order-history views each name the action that resolves them.
- **Error**: states what was *not* changed ("Nothing has been changed — try again"), and offers Retry plus a support route.
- **Success**: names the consequence and the next two actions (Track order / Keep shopping).

## 11.11 Handoff completeness

The consistency review's verdict is that a developer can build this without guessing, and the list of what is specified is worth keeping as an acceptance checklist: every token · layout metrics · every Phase 1 status and its tone · component states (default, hover, focus, active, disabled, loading, error) · page states per screen · validation rules **and their copy** (ZIP, compare-at price, payout minimum, required reasons) · which actions demand a reason and where that reason surfaces afterwards.

What remains open is product decisions, not design gaps — they are collected in [13](13-roadmap-and-decisions.md).

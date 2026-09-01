# M2 completion report — catalogue, offers and moderation

Answers the thirty questions in the M2 brief, in order. Where a gate could
not be run in this environment it says so rather than claiming a result.

---

## 1. Branch and final SHA

- Branch: `claude/veritas-marketplace-architecture-vov8c0`
- Final SHA: `373fa69746a0ad55095384543c9e802ccbbbc91c`

M2 commits, oldest first:

| SHA       | Subject                                                                 |
| --------- | ----------------------------------------------------------------------- |
| `ca042ae` | Raise static analysis to level 8 under Larastan                         |
| `f896d0f` | Establish the queue runtime: Horizon, six queues, and proof they drain  |
| `30e38ba` | Bind object storage behind a port, with public and private disks        |
| `83d94be` | Finish the seller document flow on the private disk                     |
| `251ad2f` | Add the category hierarchy and the attribute schema                     |
| `a02e742` | Extend the canonical product: moderation, identifiers, variants, brands |
| `66723d0` | Add product proposal, moderation and the seller offer lifecycle         |
| `0ba191a` | Wire catalogue side effects: search, notifications and media processing |
| `df2de6d` | Build the canonical product page and its SEO foundation                 |
| `0d3e898` | Build the seller and admin catalogue screens                            |
| `7fca647` | Close the M2 test requirements and the gaps they found                  |
| `373fa69` | Serve the storefront through SSR in the container stack                 |

Nothing was force-pushed and no published history was rewritten.

## 2. Exact package/version changes

One production dependency added across the whole milestone:

- `laravel/horizon` `^5.48` (resolved `v5.48.3`)

Nothing else changed. `package.json` is byte-identical to its M1 state.
Two development packages that M1 could not install in this environment are
now present and are what CI analyses with:

- `phpstan/phpstan` `2.2.12`
- `larastan/larastan` `3.10.0`

Unchanged and worth stating because the milestone depends on them:
Laravel `13.29.0`, PHP `8.4.19`, `inertiajs/inertia-laravel` `v3.3.1`,
PHPUnit `12.5.34`, Pint `1.30.5`, Node `22`.

## 3. Infrastructure gaps closed

The four M1 gaps, and what each now is:

**Static analysis.** Level 8 with Larastan, zero errors, one documented
ignore. `phpstan.base.neon` holds the shared parameters and the level;
`phpstan.larastan.neon` adds the extension and `checkModelProperties`;
`phpstan.neon` is the fallback for an environment without Larastan and
drops to level 6 with a comment saying exactly why. `tools/phpstan.php`
picks the strict configuration whenever the extension is present, and CI
fails the build if it is absent rather than quietly running the weaker one.

The single ignore is `instanceof.alwaysTrue` in `app/Support/Guards.php`,
where the runtime type genuinely can differ from the declared one. Reaching
level 8 meant fixing real defects, not silencing namespaces: the model
annotator was marking every `datetime` and `array` cast nullable regardless
of the column, which made six genuine null checks look redundant.

**Docker.** The daemon is unavailable in this environment, so Docker was
never built or run locally and this report does not claim it was. CI builds
the images, starts Postgres, Redis, the application and now the SSR
process, migrates, smokes HTTP and the SEO output, starts Horizon, proves
every queue drains, checks the failed-jobs table exists, and tears down.

**Queues.** Redis with six named queues and one Horizon supervisor each.
See §4.

**Storage.** An `ObjectStore` port with two disks behind it. See §5.

## 4. Queue configuration and smoke result

Six queues, declared once in `App\Support\Queues`: `critical`, `emails`,
`catalogue`, `media`, `search`, `default`. `config/horizon.php` gives each
its own supervisor with its own retry, backoff, timeout, memory and `nice`
values — a thousand queued image derivatives must not put a payment webhook
behind them, and one shared pool would give exactly that however the queues
were ordered. Media is allowed 512 MB and five minutes and is deprioritised
at the OS level; a state transition gets 60 seconds and five tries.

The dashboard sits at `admin/queues` behind `['web', 'auth:admin',
'admin.mfa', 'admin.can:platform.queues.view']`. Horizon ships open to any
signed-in session, which here would mean any customer, and job payloads
carry ids and email addresses.

`php artisan queues:smoke` dispatches a heartbeat to every declared queue
and waits for a worker to run it. `horizon:status` says a supervisor is
alive; this says work is picked up, which is the thing that breaks.

Result, run against the local Redis with a worker draining all six:

```
  ok critical
  ok emails
  ok catalogue
  ok default
  ok search
  ok media

Every queue was drained.
```

Retry and failure behaviour is covered against a real Redis in
`tests/Feature/Infrastructure/QueueRuntimeTest` (5 tests): a job survives
the round trip, lands on the queue it names, every declared queue has a
supervisor, a failing job is retried and then recorded in `failed_jobs`,
and a duplicate dispatch is not enqueued twice.

## 5. Storage/R2 abstraction and access model

`App\Modules\Media\Contracts\ObjectStore` is the only way domain code
touches bytes: `put`, `putContents`, `url`, `temporaryUrl`, `readStream`,
`exists`, `delete`, `fromReference`. No Cloudflare type appears anywhere in
the domain, and the R2 binding is configuration — `VERITAS_STORAGE_DRIVER`
flips both disks from `local` to `s3` against an R2 endpoint, with the same
code path either side.

Visibility is a disk, not a flag, so an ACL mistake is structurally
impossible rather than merely discouraged:

- `media` — public, has a `url`, serves product photography.
- `documents` — private, `visibility: private`, `serve: false`, **no `url`
  key at all**. `Storage::disk('documents')->url(...)` throws rather than
  returning a guessable address.

`UploadPolicy` validates from the bytes, never the extension, the
client-supplied MIME or the filename. Keys are generated
(`collection/yyyy/mm/{ulid}.{ext}`) and keep nothing of the uploaded name.
Images are checked for real dimensions; a truncated or zero-byte file is
refused; each collection has its own size budget. Private objects are read
through a controller that authorises first and streams second, or through a
short-lived signed URL where the driver supports one.

Covered by `tests/Feature/Infrastructure/ObjectStorageTest` (14 tests).

## 6. Seller document upload implementation

Registration paperwork lands on the private disk through
`UploadApplicationDocument`, is listed with `ResolveDocumentDownload`, and
is removed by `RemoveApplicationDocument`. Sellers reach only their own
application's documents; admins reach any, but only with
`seller.view_sensitive` — a reviewer without it sees the filename and gets
no download link, and the route refuses them if they construct one.

Uploads are validated to `application/pdf`, `image/jpeg`, `image/png`,
`image/webp` by content, capped by size, and refused entirely once the
application has been decided. Upload and removal are both audited, without
the contents.

`tests/Feature/Sellers/ApplicationDocumentTest` (17 tests) covers all of
it, including cross-applicant isolation with real ids, a seller session
attempting the admin download route, an executable renamed as a PDF, and
the case where the disk _can_ sign — proving the expiring link is used
rather than a public one.

This was finished before any catalogue media work, so product images reuse
one storage pattern rather than inventing a second.

## 7. Category schema

`categories` is a materialised-path tree: `parent_id`, `path`, `depth`,
`position`, `is_visible`, plus SEO fields. Path and depth are maintained by
`SaveCategory`, which repaths the whole subtree when a category moves.

Cycles are prevented twice: `SaveCategory` walks the proposed ancestry and
refuses a move that would make a category its own ancestor, and the
database carries `categories_not_own_parent`. The controller turns the
refusal into a validation message on `parent_id`, not a 500.

A hidden category has no public page (404) and no product may be
_published_ into one — a product there would be reachable by its own
address and by nothing else. Approving into a hidden category is still
allowed: accepting a product into the catalogue and putting it on the
storefront are different decisions.

## 8. Attribute schema

Attributes are defined once and reused: `attributes` (code, name,
`data_type`, unit, `is_filterable`, `is_searchable`, `is_variant_defining`,
position) with `attribute_options` for enumerated types, joined to
categories through `category_attributes` carrying `is_required`,
`is_variant_defining` and `position`. A category inherits its ancestors'
attributes; the child's own assignment is what makes an inherited attribute
required.

Values are **not** a JSON blob. `product_attribute_values` has one typed
column per shape — `value_text`, `value_int`, `value_decimal`,
`value_boolean`, `value_date`, `attribute_option_id` — so anything worth
filtering on stays structurally queryable. Three database constraints hold
the model together:

- `product_attribute_values_one_value` — exactly one value column is
  populated per row.
- `product_attribute_values_product_unique` — one value per attribute per
  product (or per variant).
- `attribute_options` unique on `(attribute_id, value)`.

`SaveAttributeValues` validates against the category's schema before
anything is written: an attribute the category does not use is refused, a
select value outside the declared options is refused, a non-numeric value
for a number is refused, and clearing a value deletes the row rather than
storing a blank.

## 9. Brand architecture

`brands` gains `normalised_name` (lowercased, whitespace collapsed) with a
unique index, so `Apple`, `APPLE` and `apple  ` are one brand. The slug is
separate because a slug is a URL and may be edited for other reasons.

A seller who cannot find their brand proposes one: it is created with
`proposed_by_seller_account_id` set and `approved_at` null, usable
immediately on their own proposal but not presented as a marketplace brand
until someone with `catalog.brand.manage` accepts it. Both the proposal and
the approval are audited with their actors.

## 10. Canonical product model

One canonical product, many seller offers — never one product per seller.
`products` carries the marketplace's own record: title, `normalised_title`,
slug, description, category, brand, the identifier set, SEO fields,
moderation state and the merge pointer.

Ownership is the marketplace's. `created_by_seller_account_id` is
provenance, exposed as the `proposedBy` relation, and confers no editing
right once a proposal is accepted — `UpdateCanonicalProduct` refuses a
non-admin actor the moment the product leaves `draft`/`changes_requested`.
The authorised half is an admin route behind `catalog.product.review` that
records the previous and new values.

Merging is future-proofed without being built: `merged_into_product_id`
and `merged_at`, with `products_not_merged_into_self` and
`products_merge_is_dated`. A merged product keeps its row, its offers and
its slug history; reads follow the pointer and the old URL 301s to the
survivor. Nothing is deleted, so SEO authority and media provenance
survive. No automatic merging exists.

## 11. Variant architecture

Variants are points in the category's variant axes, not duplicated
products. `product_variants` carries `option_values` plus a generated
`option_signature` with
`product_variants_option_signature_unique`, so the same combination cannot
exist twice on one product. `SaveProductVariant` refuses a variant that
omits an axis or names a value outside it, and only a single-valued
comparable attribute type may define an axis — a paragraph or a multi-select
cannot be a coordinate.

A variant belongs to exactly one product, and an offer naming a variant of
a different product is refused by the action _and_ by a plpgsql trigger,
`offers_variant_matches_product_check`, so a direct write cannot create the
inconsistency either.

## 12. Identifier/deduplication rules

Identifiers are optional and unique when present: `gtin`, `upc`, `ean`,
`isbn`, `mpn`, `model_number`, each with a partial unique index so the
handmade products that legitimately have none do not collide with each
other.

`FindDuplicateProduct` is deterministic and ranked, with no fuzzy matching
and nothing statistical:

1. A matching barcode (GTIN/UPC/EAN/ISBN), check-digit validated —
   conclusive. Refused outright, with a link to list against the existing
   product instead.
2. Same brand plus the same part number — conclusive.
3. Same normalised title, brand and category — **suggestive only**. It does
   not refuse anything; it attaches the candidate to the proposal for a
   moderator to look at.

Nothing is ever auto-merged on a resemblance, and a decision a moderator
cannot explain to the seller it affected is not made.

## 13. Product moderation lifecycle

`ProductStatus`: `draft → pending_review → {approved | rejected |
changes_requested}`, `approved → {published | suspended | archived}`,
`published → {suspended | archived}`, `suspended → {published | archived}`,
`rejected → {draft | archived}`. `TransitionProduct` is the only path, it
validates against the enum's own table, and it writes the history row in
the same transaction as the change it describes.

`changes_requested` is a first-class state, not a rejection with softer
wording — telling a seller their product was rejected when one field needs
correcting is untrue and unrecoverable in the reporting afterwards. All
three negative outcomes require a written reason, enforced by the state
machine rather than by the form.

Approval is idempotent under concurrency: the row is locked `FOR UPDATE`,
an already-decided product returns unchanged with no second history row and
no second event, and the transition itself would refuse a double approval
even on a path that skipped the action. Two moderators clicking Approve
produce one catalogue entry.

Accepting and publishing are deliberately separate decisions, so a launch
can be staged.

## 14. Seller offer lifecycle

An offer is the seller's own price, condition, SKU, handling time and
state against a canonical product or one of its variants. Money is integer
minor units everywhere — `price_minor`, `compare_at_price_minor`, currency
alongside — converted from a decimal only at the HTTP boundary.

Database-enforced: `offers_price_is_positive`,
`offers_compare_at_above_price`, `offers_seller_product_unique` (one offer
per seller per product/variant), `(seller_account_id, seller_sku)` unique
so a SKU is a seller's own namespace and two sellers may use the same
string, and the variant/product trigger above.

`OfferStatus` is independent of `ProductStatus`. Whether a customer can buy
is one question answered in one place — `OfferEligibility` — which requires
a published, unmerged product, an approved seller, an open store and a
published offer. The product page, the category page, the search index and
the seller store all read that same query, so "product rejected", "seller
suspended", "offer manually inactive" and "zero stock" stay four distinct
answers to "why can nobody buy this".

Ranking lives in `OfferRankingService`, not in a React component or a SQL
fragment copied between pages: cheapest first, condition breaks a tie, and
the order is deterministic for equal offers.

## 15. Admin catalogue screens

Custom React + TypeScript + Inertia, using the existing Modernist design
system. No Filament.

- **`Catalogue/Products`** — the moderation queue, defaulting to what is
  waiting on the team rather than to every product ever published, with
  status, category and title/barcode filters.
- **`Catalogue/ProductReview`** — one proposal in full: title, category,
  brand, identifiers, specification, variants, media, proposing seller and
  the complete moderation history. Actions are approve, accept-without-
  publishing, publish, request changes, reject and suspend; the three that
  need a reason go through the shared `ReasonDialog`, which states the
  consequence in words above the button that causes it.
- **`Catalogue/Taxonomy`** — categories with their hierarchy, attribute
  definitions, category–attribute assignment and brand approval.

Buttons a role cannot use are absent, but that is a courtesy: the route
middleware and the controller both check the same permission again.

## 16. Seller catalogue screens

- **`Catalogue/Search`** — the entry point, and search-first by
  construction. "Propose this product" appears only in the empty state.
- **`Catalogue/Propose`** — specification fields driven by the chosen
  category, so the form cannot be built until one is picked. It runs the
  same deterministic duplicate check the submit would and shows what the
  catalogue already holds, with a link to list against it, while that is
  still the cheaper option. Doubles as the correction screen for a proposal
  a moderator sent back, showing the note that was left.
- **`Catalogue/Offers`** and **`Catalogue/OfferForm`** — the seller's own
  listings; the price is typed as a decimal and converted to minor units at
  the boundary.

## 17. Public product page

`/products/{slug}` resolves one canonical product with every eligible
offer on it, ranked, with a price range across sellers. An unpublished,
suspended or unlisted-category product has no page at all — 404, not a
shell. A merged product 301s to its survivor; a renamed one 301s from its
old address.

The suite asserts the query count is _identical_ for a product with one
seller and one with eight — the equality is the guarantee, not a magic
number — and bounds the fully loaded page (four images, three variants,
five sellers) at 25 queries. The buy CTA is present and disabled: the cart
is M4 and nothing here pretends otherwise.

## 18. SEO implementation

- One `<title>` per page, server-rendered. The Blade shell emits its
  fallback title only when SSR is off, so a crawler never sees two.
- One canonical link per page, always to the canonical product URL.
- Product JSON-LD built from real database values only: name, description,
  URL, category, and an `Offer`/`AggregateOffer` whose price comes from the
  offers that exist. **No `aggregateRating` and no `review` is ever
  emitted**, because the database cannot truthfully support either yet.
  A product nobody lists emits no price at all rather than a zero.
- BreadcrumbList from the real category lineage.
- A seller's offer never gets a page of its own, so no duplicate
  indexable page is created per seller.
- Category pages carry their own title, canonical and robots directives;
  arbitrary faceted query combinations are not indexable by default.
- Slugs come from the title, never the id, and are unique against both live
  products and retired addresses. `product_slug_history` keeps every old
  slug and no other product may ever take one.

## 19. Audit events

`catalogue.product.proposed`, `catalogue.product.approved`,
`catalogue.product.rejected`, `catalogue.product.changes_requested`,
`catalogue.product.suspended`, `catalogue.product.edited`,
`catalogue.product.image_added`, `catalogue.category.created`,
`catalogue.category.updated`, `catalogue.brand.proposed`,
`catalogue.brand.approved`, and `catalogue.offer.{status}` for every offer
transition.

Each entry records the actor type and id, the subject, the reason where one
was required, and the previous and new values where they are meaningful —
a canonical edit stores `{from, to}` per changed column. An edit that
changes nothing writes nothing: a log full of no-ops is a log nobody reads.

Private seller documents never appear in a catalogue audit payload; a test
asserts it across every `catalogue.*` entry.

## 20. Notifications

`ProductDecided` tells the proposing seller what a moderator decided and
why. It is queued on the `emails` queue, dispatched after commit, and sent
once per decision even when the decision also publishes. A product the
platform added itself notifies nobody.

No test sends real mail: the suite fakes the notifier and asserts the
notification implements `ShouldQueue`, so a synchronous send would fail the
build rather than reach a provider.

## 21. Permissions added

Eight admin capabilities, deliberately not granted together:

`catalog.view`, `catalog.product.review`, `catalog.product.approve`,
`catalog.product.reject`, `catalog.product.suspend`,
`catalog.category.manage`, `catalog.attribute.manage`,
`catalog.brand.manage` — plus `platform.queues.view` for Horizon.

Distribution:

| Role                             | Holds                                                                            |
| -------------------------------- | -------------------------------------------------------------------------------- |
| Super admin                      | all                                                                              |
| Catalog moderator                | all eight                                                                        |
| Marketplace admin                | view, review, approve, reject, suspend — **not** the three taxonomy capabilities |
| Support, Analyst                 | `catalog.view` only                                                              |
| Seller operations, Finance admin | none                                                                             |

Reviewing a proposal and restructuring the taxonomy every seller lists
against are different acts of trust.

## 22. Database migrations and constraints

Five migrations:

- `2026_03_01_000100_create_catalogue_attribute_tables`
- `2026_03_01_000200_extend_canonical_product`
- `2026_03_01_000300_extend_product_variants`
- `2026_03_01_000400_add_offer_constraints`
- `2026_03_01_000500_create_product_search_documents`

Constraints and indexes, all enforced by the database rather than by
request validation alone:

| Object                                                          | Guarantees                                        |
| --------------------------------------------------------------- | ------------------------------------------------- |
| `categories_not_own_parent`                                     | no category is its own parent                     |
| `product_attribute_values_one_value`                            | exactly one typed value column per row            |
| `product_attribute_values_product_unique`                       | one value per attribute per product/variant       |
| `brands_normalised_name_unique`                                 | one brand per normalised name                     |
| `products_{gtin,upc,ean,isbn}_unique`                           | partial unique per identifier                     |
| `product_variants_{...}_unique`, `product_variants_gtin_unique` | identifiers unique at variant level               |
| `product_variants_option_signature_unique`                      | one variant per option combination                |
| `products_not_merged_into_self`, `products_merge_is_dated`      | a merge is coherent and dated                     |
| `product_media_one_primary`                                     | one primary image per product                     |
| `product_slug_history.old_slug` unique                          | a retired address is never reissued               |
| `offers_price_is_positive`, `offers_compare_at_above_price`     | money is sane                                     |
| `offers_seller_product_unique`                                  | one offer per seller per product/variant          |
| `offers_variant_matches_product_check` (trigger)                | a variant belongs to the product it is sold under |
| `category_attributes (category_id, attribute_id)` unique        | one assignment per pair                           |
| `attribute_options (attribute_id, value)` unique                | one option per value                              |
| `product_search_documents` generated `tsvector` + GIN           | search without a second datastore                 |

`migrate:fresh --seed` runs clean on an empty database.

## 23. Exact test count/assertion count

**433 tests, 9,347 assertions, all passing.** The invariant suite is 74
tests and 917 assertions of that total and runs first on its own.

Catalogue and infrastructure coverage:

| Suite                       | Tests |
| --------------------------- | ----- |
| `CanonicalProductTest`      | 25    |
| `OfferLifecycleTest`        | 19    |
| `CategorySchemaTest`        | 19    |
| `PublicProductPageTest`     | 18    |
| `ApplicationDocumentTest`   | 17    |
| `SellerCatalogueAccessTest` | 15    |
| `CatalogueSideEffectsTest`  | 15    |
| `AdminCatalogueAccessTest`  | 14    |
| `ProductModerationTest`     | 14    |
| `ObjectStorageTest`         | 14    |
| `CatalogueAuditTest`        | 11    |
| `QueueRuntimeTest`          | 5     |
| `ProductPageSsrTest`        | 3     |

All 52 required cases in the brief are covered. The SSR case runs the real
built bundle in a real node process rather than asserting against
client-side markup, and it fails rather than skips if the bundle is
missing — a green suite that quietly stopped checking the rendered output
is worse than a red one that says the build has not been run.

## 24. Static analysis result

`php tools/phpstan.php analyse` — **level 8 with Larastan, 0 errors**, with
`checkModelProperties: true`. One documented ignore, described in §3. No
baseline file and no silenced namespaces.

Pint: passing across `app`, `database`, `routes`, `tests`, `config`.

## 25. Frontend gate results

| Gate                                                                              | Result   |
| --------------------------------------------------------------------------------- | -------- |
| `tsc --noEmit` (strict, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`) | clean    |
| ESLint `--max-warnings=0`                                                         | clean    |
| Prettier `--check`                                                                | clean    |
| Client production build                                                           | succeeds |
| SSR production build                                                              | succeeds |

## 26. Queue/storage gate results

| Gate                                                | Result      |
| --------------------------------------------------- | ----------- |
| `queues:smoke` across all six queues, real Redis    | all drained |
| Retry/failure behaviour (`QueueRuntimeTest`)        | 5/5         |
| Public product media (`ObjectStorageTest`)          | 14/14       |
| Private document access (`ApplicationDocumentTest`) | 17/17       |

## 27. HTTP/SSR/SEO smoke results

Run locally against `php artisan serve` with SSR enabled and the built
bundle serving on 13714:

| Check                               | Result                                          |
| ----------------------------------- | ----------------------------------------------- |
| Storefront `/`                      | 200                                             |
| Published product page              | 200                                             |
| Category page                       | 200                                             |
| Unknown product slug                | 404                                             |
| `<title>` count on the product page | exactly 1                                       |
| Title content                       | `Aeris Cordless Kettle 1.2L — Veritas Commerce` |
| `rel="canonical"` count             | exactly 1, pointing at the product URL          |
| `application/ld+json` blocks        | 2 — Product and BreadcrumbList                  |
| `aggregateRating` / `review`        | absent                                          |
| Product JSON-LD price               | `"99.00"` `"USD"` from `price_minor = 9900`     |
| Availability / condition            | `InStock` / `NewCondition`, from the offer row  |
| Category page robots                | `index, follow`; title and canonical present    |
| `X-Robots-Tag` on a storefront page | absent (indexable)                              |

## 28. Docker validation result

**Not validated locally.** The Docker daemon is unavailable in this
environment (`Cannot connect to the Docker daemon at
unix:///var/run/docker.sock`), and image registry access was already
blocked in M1. No claim is made that a Docker build passed here.

CI is where it is proven, and the job now covers: build both images, start
Postgres and Redis, `composer install`, `npm ci` and `npm run build:ssr`,
start the SSR service and the application, `migrate:fresh --seed`, HTTP
smoke across storefront and both portals, the security-header set, the
live product-page SEO smoke described in §27, Redis reachability from the
application, `horizon:status` plus `queues:smoke`, the failed-jobs table,
and teardown.

The compose stack gained an `ssr` service this milestone, and `node_modules`
moved from an anonymous volume to a named one — the SSR bundle imports
React at runtime, so the process serving it needs the install the build
container made.

## 29. Bugs discovered during M2 and their fixes

1. **The model annotator marked every `datetime` and `array` cast
   nullable** regardless of the column, so six genuine null checks looked
   redundant to PHPStan. Nullability now comes from the column.
2. **`Str::slug('1.2L')` produced `12l`**, reading as a different capacity.
   `ProductSlug::normalise` now replaces non-alphanumerics with spaces
   before slugging.
3. **`OfferFactory` created a second canonical product per offer** by
   defaulting `product_variant_id` to its own factory. It now derives the
   variant from the product the offer names.
4. **`ProductFactory` computed its slug and `normalised_title` from the
   generated title rather than the final one**, so any state overriding
   `title` produced a row whose duplicate-detection key described a
   different product. Both are now closures over the resolved attributes.
5. **`ModelNotFoundException` extends `RuntimeException`**, so another
   seller's offer id was caught by the `SaveOffer` handler and returned as
   a validation message instead of a 404. The lookup now happens before the
   try.
6. **A product could be published into a hidden category**, leaving it
   reachable by its own address and by nothing else. `TransitionProduct`
   now refuses it; approving into one is still allowed.
7. **Canonical editing had no authorised path at all.** §9 forbids a seller
   changing a product other sellers list against, which is only coherent if
   somebody can — `UpdateCanonicalProduct` and an admin route now provide
   it, audited with the previous values.
8. **`Storage::fake('documents')` enabled signing**, unlike production, so
   a test was passing for the wrong reason. The fake now mirrors the real
   disk, and the signing case is a separate test.
9. **A test helper leaked a session** — the "a guest cannot reach this"
   case was authenticated, because the fixture used `actingAs`.
10. **`DB::enableQueryLog()` does not clear prior entries**, which made the
    product page look like an N+1 it was not. The performance assertions
    now flush first.
11. **The Search module imported Catalog's models**, breaking the module
    boundary. An `IndexableProductSource` port was introduced and
    `BuildIndexableProduct` moved into Catalog.
12. **The two seller catalogue controllers lived in the Sellers module** and
    reached into Catalog and Offers models. They now live in the modules
    that own their aggregates.
13. **A trait property conflict** — `public $queue = ...` alongside
    `use Queueable` is a fatal error; the queue is set via `onQueue()` in
    the constructor.
14. **`dropConstrainedForeignKey` does not exist**; the method is
    `dropConstrainedForeignId`.
15. **A structured-data assertion matched a substring** and would have
    passed on faker text containing the word "review". It now checks for
    the key recursively.
16. **A unique-violation assertion matched a column name** in the failing
    SQL rather than the index, so it could pass on the wrong constraint.

## 30. Remaining blockers before M3

None blocking. Open items, in the order they will matter:

1. **Docker is unverified outside CI.** Nothing about the stack has been
   observed running locally in this environment. The first thing to do on a
   machine with a daemon is run the compose stack end to end.
2. **Media processing has no derivative sizes yet.** `ProcessProductImage`
   validates, measures and marks an image ready; it does not yet generate
   the responsive variants a product gallery will want.
3. **Search is a Postgres adapter behind the port.** `tsvector` with a GIN
   index is right for this stage and the `SearchIndex` contract means
   swapping in a dedicated engine is a new adapter, not a migration — but
   relevance tuning has not been attempted.
4. **Merging is schema-ready and has no operator flow.** The pointer, the
   constraints and the redirect behaviour exist; deciding a merge in the
   admin does not.
5. **Inventory is not wired to offer eligibility.** Stock exists in the M0
   schema and `OfferEligibility` deliberately does not consult it yet, so
   "zero stock" is not yet one of the reasons an offer is invisible. That
   belongs with the cart in M4.
6. **The buy CTA is inert by design** until the cart exists.

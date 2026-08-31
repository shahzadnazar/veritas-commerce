# 06 · SEO & search

Organic search is the cheapest acquisition channel a marketplace has, and it is won or lost in the first build. The spec commits to a server-rendered storefront; this document turns that into a specification.

## 6.1 Rendering strategy

| Surface | Rendering | Reason |
|---|---|---|
| Storefront (home, category, search, product, store page) | **Inertia SSR** — full HTML on first request | Crawlers, LCP, social previews |
| Storefront (cart, checkout, account) | Client-side after auth | `noindex`, no SEO value |
| Seller portal | Client-side | Behind auth |
| Admin portal | Client-side | Behind auth |

SSR runs as a Node side-process (`php artisan inertia:start-ssr`) supervised alongside the app. If it dies, requests fall back to client rendering rather than erroring — degraded SEO beats an outage — and the fallback fires an alert.

## 6.2 URL scheme

Clean, stable, human-readable, and designed so a slug change never breaks a link.

```
/                                       home
/c/{category}                           top-level category
/c/{category}/{subcategory}             second level (Phase 1 is two deep)
/p/{product-slug}-{public_id}           product detail
/store/{store-slug}                     seller store page
/store/{store-slug}/{category}          store, filtered
/search?q=                              search results
/brands/{brand-slug}                    brand page
```

- The product URL carries the ULID suffix so the **title can change without breaking the URL** and without a slug-uniqueness fight across 400+ sellers. A request with a stale slug but a valid id **301s** to the canonical form.
- Store slugs are globally unique and validated live during store setup. Changing one writes a `store_slug_history` row and the old URL **301s forever**. Same for category slugs and merged brands (`brand_aliases`).
- Trailing slashes normalised, lowercase enforced, no `index.php`, no session ids in URLs.

## 6.3 Per-page SEO contract

Every indexable route implements a `SeoContract` returning a typed object, so no page can ship without deciding these:

```php
final class SeoMeta {
    public string  $title;          // ≤ 60 chars, template-driven
    public string  $description;    // ≤ 155 chars
    public string  $canonical;
    public bool    $noindex;
    public ?string $ogImage;        // absolute, 1200×630
    public array   $structuredData; // JSON-LD graph
    public array   $hreflang;       // reserved for Phase 2
}
```

| Page | Title template | Structured data |
|---|---|---|
| Home | `Veritas Commerce — {n} products from {m} independent sellers` | `WebSite` + `SearchAction` (sitelinks search box), `Organization` |
| Category | `{Category} — {n} products \| Veritas Commerce` | `BreadcrumbList`, `ItemList` |
| Product | `{Title} by {Store} \| Veritas Commerce` | `Product` with `Offer` (price, currency, availability, condition, seller), `BreadcrumbList`, `AggregateRating` once reviews ship |
| Store page | `{Store} — {n} products \| Veritas Commerce` | `Store` / `Organization`, `BreadcrumbList`, `ItemList` |
| Search | `"{query}" — search results` | none |
| Brand | `{Brand} products \| Veritas Commerce` | `Brand`, `ItemList` |

**Product structured data is the highest-value item on this list** — it drives rich results with price and availability, and it must reflect live stock (`InStock` / `OutOfStock`) and the *variant-level* price the page is showing.

## 6.4 Indexation control — the marketplace trap

Faceted navigation generates a combinatorial explosion of URLs. Left alone, it burns crawl budget and creates thin duplicate pages. The policy:

| URL shape | Directive |
|---|---|
| `/c/{cat}` and `/c/{cat}/{sub}` | index, follow, self-canonical |
| One facet applied (e.g. `?brand=aeris`) | index, follow, self-canonical — these have search demand |
| Two or more facets, or any price/availability facet | `noindex, follow`, canonical to the clean category |
| Any `?sort=` | canonical to the unsorted URL |
| Pagination `?page=2+` | self-canonical, `noindex` past page 3, `rel=prev/next` in the sitemap logic |
| `/search` | `noindex, follow` — always |
| Cart, checkout, account, order, seller portal, admin | `noindex, nofollow` + `Disallow` in robots.txt |
| Suspended seller's store | **404**, not an empty shell (the prototype specifies this) |
| Draft, pending-review, rejected or archived product | 404 (never 200 with a placeholder) |

`robots.txt` disallows `/cart`, `/checkout`, `/account`, `/seller`, `/admin`, `/*?*sort=`, and permits everything else.

## 6.5 Sitemaps

A sitemap index, regenerated nightly by a queued job and pinged to search engines:

```
/sitemap.xml                 index
  /sitemap-products-1.xml    50,000 URLs per file, lastmod = published/updated
  /sitemap-categories.xml
  /sitemap-stores.xml
  /sitemap-brands.xml
  /sitemap-static.xml        home, help, terms, privacy, sell-with-us
```

Only `status = published` products with `available > 0` **or** a defined restock intent are included; a sitemap full of 404s and out-of-stock pages degrades trust in the whole domain. Image sitemap entries are attached to product URLs.

## 6.6 Core Web Vitals budget

Enforced in CI with Lighthouse CI against staging; a regression fails the build.

| Metric | Budget (mobile, p75) |
|---|---|
| LCP | ≤ 2.0s |
| INP | ≤ 200ms |
| CLS | ≤ 0.05 |
| TTFB | ≤ 500ms |
| JS shipped to a product page | ≤ 180KB gzipped |

How we hit it:
- **Images**: AVIF/WebP with fallbacks, explicit `width`/`height` on every `<img>` (CLS), `loading="lazy"` below the fold, `fetchpriority="high"` on the LCP image, responsive `srcset` generated by the media pipeline. The design system's grayscale treatment is a **CSS filter**, not a second rendered asset.
- **Fonts**: Archivo self-hosted, `font-display: swap`, preloaded, subset to Latin. No Google Fonts request on the critical path (the prototype's `@import` is replaced at build time).
- **JS**: route-level code splitting per Inertia page; the design system tree-shakes; no chart library on the storefront.
- **Zero layout shift on skeletons** — the design system's rule that skeletons mirror real geometry is a performance requirement, not only an aesthetic one.

## 6.7 Search — the engine ladder

One interface, three implementations, swapped when a metric says so.

```php
interface SearchPort {
    public function query(SearchQuery $q): SearchResults;   // hits + facets
    public function suggest(string $prefix, int $limit): array;
    public function index(ProductDocument $doc): void;
    public function remove(string $productId): void;
}
```

| Stage | Engine | When | Capability |
|---|---|---|---|
| **1 — launch** | PostgreSQL FTS + `pg_trgm` | up to ~100–200k products | Weighted title/keywords/description, typo tolerance via trigram, facets by SQL aggregate |
| **2 — growth** | Meilisearch | facet latency > 200ms, or synonyms/typo quality complaints | Instant search, typo tolerance, synonyms, custom ranking, faceted counts |
| **3 — scale** | OpenSearch / Elasticsearch | multi-million SKUs, learning-to-rank, multi-region | Full relevance tuning, LTR models, aggregations, vector hybrid |

**Search behaviour specified by the prototype**, which holds at every stage:
- Search hits **product title, seller name and SKU**.
- The query stays in the header input so it can be refined without navigating back.
- Zero results **keeps the filters visible** — over-narrow filters are the most common cause, not a bad query — and offers three exits: clear filters, browse the category, or a popular query.
- Filters write to **URL query params** (`?cat=&seller=&in_stock=1&sort=`), server-rendered, so a filtered view is shareable and back-button safe.
- Sort options: Relevance, Price low→high, Price high→low, Newest. Pagination is 24 per page.

**Indexing.** Product writes raise `ProductPublished` / `ProductUpdated` / `ProductArchived`; a queued indexer on the `index` queue updates the document. A nightly full reindex reconciles drift. The index document is denormalised — title, description, keywords, category path, brand, seller name, price range across variants, availability, condition, `published_at` — so a query never joins.

## 6.8 What is deliberately not in Phase 1

Multi-language and `hreflang`, international pricing, AMP, review rich results (no reviews yet), and personalised search ranking (see [07](07-recommendations.md)). Each has a seam: the SEO contract already carries `hreflang`, and the search port already returns a ranked list that a re-ranker can reorder.

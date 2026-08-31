# Veritas Commerce Marketplace

A multi-vendor marketplace: a customer storefront, a seller operating portal and an admin control centre, built as one application over one design system, where the platform earns a commission on every completed sale.

**Status:** M0 complete — the executable foundation is in place and all ten verification gates pass. See [the M0 report](docs/architecture/M0-REPORT.md).

## Start here

| | |
|---|---|
| **[docs/architecture/](docs/architecture/README.md)** | The build plan — 14 documents covering scope, architecture, data model, money, inventory, SEO, recommendations, identity, security, performance, design system, delivery and the decision register |
| **[docs/source/](docs/source/)** | The original Phase 1 product specification and the prototype review guide |
| **[design/prototype/](design/prototype/)** | The delivered UI: 39 screens, 44 components, the Modernist design system token sheet, six responsive patterns and the consistency audit. Open `00 Overview.dc.html` in a browser |

## The rule everything else follows

When an order completes it stores its own **total**, **platform commission**, **seller earning** and the **commission rate that produced them**. Nothing recalculates. Change the platform rate tomorrow and every historical order, statement and revenue figure stays exactly as it was.

Every financial and inventory movement is an append-only row. Nothing is ever overwritten.

## Running it locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed        # needs PostgreSQL and Redis
npm run dev                             # or: docker compose up
```

Verification gates, all of which CI runs:

```bash
./vendor/bin/pint --test                # PHP code style
./vendor/bin/phpunit --testsuite=Invariants   # the architectural invariants
./vendor/bin/phpunit                    # everything
php artisan statuses:export --check     # the status map is current
npm run typecheck && npm run lint && npm run format:check
npm run build:ssr                       # production client + SSR build
```

## Stack

Laravel 13 · React 19 + TypeScript (strict) via Inertia 2, SSR for the storefront · PostgreSQL 16 · Redis 7 · Vite 8 with one bundle per area · Tailwind 4 over the Modernist token sheet · a provider-agnostic payment port (fake driver at M0, Stripe from M3).

See [13 · Roadmap & decisions](docs/architecture/13-roadmap-and-decisions.md) for the rationale, the twelve open decisions and the twelve-week delivery plan.

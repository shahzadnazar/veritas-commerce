# Veritas Commerce Marketplace

A multi-vendor marketplace: a customer storefront, a seller operating portal and an admin control centre, built as one application over one design system, where the platform earns a commission on every completed sale.

**Status:** architecture and analysis complete, awaiting decisions before implementation begins.

## Start here

| | |
|---|---|
| **[docs/architecture/](docs/architecture/README.md)** | The build plan — 14 documents covering scope, architecture, data model, money, inventory, SEO, recommendations, identity, security, performance, design system, delivery and the decision register |
| **[docs/source/](docs/source/)** | The original Phase 1 product specification and the prototype review guide |
| **[design/prototype/](design/prototype/)** | The delivered UI: 39 screens, 44 components, the Modernist design system token sheet, six responsive patterns and the consistency audit. Open `00 Overview.dc.html` in a browser |

## The rule everything else follows

When an order completes it stores its own **total**, **platform commission**, **seller earning** and the **commission rate that produced them**. Nothing recalculates. Change the platform rate tomorrow and every historical order, statement and revenue figure stays exactly as it was.

Every financial and inventory movement is an append-only row. Nothing is ever overwritten.

## Stack

Laravel 11 · React 19 + TypeScript via Inertia (SSR for the storefront) · PostgreSQL 16 · Redis · Horizon · Cloudflare R2 · Stripe Connect behind a provider-agnostic port · Cloudflare edge.

See [13 · Roadmap & decisions](docs/architecture/13-roadmap-and-decisions.md) for the rationale, the twelve open decisions and the twelve-week delivery plan.

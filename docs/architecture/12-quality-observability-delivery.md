# 12 · Quality, observability & delivery

## 12.1 Testing strategy

The pyramid, weighted toward what actually breaks in a marketplace:

| Layer | Tool | Coverage target | What it proves |
|---|---|---|---|
| **Invariant tests** | Pest | 100% of the invariants in [03](03-data-model.md#34-invariants) | Money and stock can never drift |
| **Unit** | Pest | ≥ 85% of Actions and domain services | Commission split, rounding, state machines |
| **Feature / HTTP** | Pest | every route | Auth, validation, policies, responses |
| **Tenant isolation** | Pest | every seller-scoped route | Seller A cannot reach seller B |
| **Route × role matrix** | Pest | every admin route × 4 roles | Authorisation cannot be forgotten |
| **Query budget** | Pest | key routes | No N+1 regressions |
| **Component** | Vitest + Testing Library | design-system components | States, a11y roles, keyboard |
| **E2E** | Playwright | the three journeys | The product actually works |
| **Visual regression** | Playwright screenshots | key screens × 3 breakpoints | Design system doesn't drift |
| **Accessibility** | axe-core in Playwright | every page state | WCAG 2.2 AA |
| **Load** | k6 | checkout, browse, seller queue | Concurrency, the last-unit race |

**The E2E suite is the definition of done.** Three specs, matching the spec's three journeys:

```
customer.spec.ts   register → search → filter → variant → cart → checkout
                   → declined card → retry → success → confirmation
                   → track → cancel before packed → refund message
seller.spec.ts     apply → (admin approves) → store setup → add product
                   with variants → publish → receive order → pack
                   → ship without tracking (blocked) → add tracking → ship
                   → see commission snapshot → request payout
admin.spec.ts      login with 2FA → review application → approve
                   → review product → approve → change commission rate
                   (forward-dated, historical order unchanged — asserted)
                   → issue refund (earning reversed at stored rate — asserted)
                   → approve payout → reject payout with reason
```

Two assertions in that admin spec are the whole architecture in miniature: **changing the rate must not alter a historical order**, and **a refund must reverse at the stored rate, not today's**. If those pass, the commercial model is correct.

**Test data**: factories for everything, plus a `DemoSeeder` producing realistic sellers, products, orders, ledger entries and payouts — the spec requires dashboards that "look meaningful", and demo data is a deliverable, not a convenience.

## 12.2 Code quality gates

Every PR must pass, and none of these is advisory:

| Gate | Tool |
|---|---|
| Static analysis | PHPStan / Larastan **level 8** |
| Code style | Laravel Pint, Prettier |
| Types | TypeScript `strict`, no `any` without an inline justification |
| Lint | ESLint, stylelint (incl. the no-raw-hex and accent-contrast rules) |
| Module boundaries | Deptrac — a module reaching another's `Models/` fails the build |
| Dependency audit | `composer audit`, `npm audit` |
| Secrets | gitleaks pre-commit and in CI |
| Bundle size | size-limit budget per entry point |
| Web vitals | Lighthouse CI against staging |

## 12.3 CI/CD

```
push → lint · static analysis · unit · feature · component      (~4 min)
     → tenant isolation · route×role · invariants · query budget (~2 min)
     → build assets · bundle budget
     → [main] deploy to staging → migrate → E2E + axe + Lighthouse
     → manual approval → deploy to production (blue/green)
                       → migrate → smoke tests → traffic shift
```

- **Migrations are always backward-compatible for one release.** Expand → deploy → backfill → contract. A column is never dropped in the same release that stops writing to it, so a rollback is always safe.
- **Feature flags** (a simple database-backed flag service) gate anything half-built. The prototype's "not designed yet" placeholders map directly to flags.
- **Zero-downtime deploys**: blue/green with health checks; queue workers drain gracefully before replacement.
- **Rollback is one command** and is exercised in staging monthly, not documented and never tried.

## 12.4 Observability

| Concern | Tool | What we watch |
|---|---|---|
| Errors | Sentry (PHP + JS, source-mapped) | New issue types, regression rate, release health |
| APM | Sentry Performance or Laravel Pulse | p95 by route, slow queries, N+1 detection |
| Logs | Structured JSON to a log platform | Correlation id per request, actor, no PII |
| Queues | Horizon | Wait time and failure rate per queue |
| Uptime | External synthetic checks | Home, product, checkout, seller login, admin login |
| Business metrics | A dashboard fed by the daily rollups | GMV, orders, conversion, payout backlog, failed-payment rate |

**Alerts that page someone** — deliberately few, all business-critical:

1. Checkout error rate > 2% over 5 minutes.
2. Payment webhook processing lag > 5 minutes.
3. **Seller balance reconciliation mismatch** — any delta, any seller.
4. **Stock reconciliation mismatch** — movement replay ≠ on hand.
5. `critical` queue wait > 60s.
6. Site availability check failing from two regions.
7. Error rate > 10× the 7-day baseline.
8. Database connections > 80% of pool.

Alerts 3 and 4 have no threshold because a penny of drift in a financial ledger is a defect, not noise.

**Correlation.** Every request carries an id, propagated into logs, jobs, Sentry and outbound provider calls, so one order can be traced from click to ledger entry.

## 12.5 Backups & disaster recovery

| Asset | Backup | Retention | RPO / RTO |
|---|---|---|---|
| PostgreSQL | Automated daily snapshot + continuous WAL (point-in-time) | 30 days | RPO 5 min / RTO 1h |
| Object storage | R2 versioning + cross-region replication | 90 days | RPO ~0 / RTO 15 min |
| Secrets | Managed store with versioning | — | — |
| Code | Git, mirrored | — | — |

**Restores are tested quarterly** into a scratch environment, with a written result. An untested backup is not a backup. The DR runbook covers: total region loss, database corruption, accidental mass delete, and provider outage (payments, email) with a documented degraded mode for each.

## 12.6 Runbooks

Written before launch, one page each: payment provider outage · webhook backlog · balance mismatch · stock mismatch · a seller reporting missing orders · suspected account takeover · a bad deploy · certificate expiry · queue flood. Each names the detection signal, the immediate containment action, the diagnostic query, and who to escalate to.

## 12.7 Environments and access

| Env | Who deploys | Data | Access |
|---|---|---|---|
| local | anyone | seeded | — |
| staging | CI on merge to `main` | anonymised copy | team + client (UAT gate from the prototype guide) |
| production | CI after manual approval | live | deploy pipeline only; no routine human DB access |

Production database access requires a break-glass procedure that is logged and reviewed. Engineers debug from logs, APM and read replicas — not from a psql prompt on the primary.

## 12.8 The client feedback protocol (from the prototype guide)

The PDF sets out how review feedback is handled, and it is worth keeping as the working agreement through build:

- Feedback identifies **the page, the exact screen/section, the requested change and the business reason** — not visual preference alone.
- Each item is classified: *prototype correction* (fix within the agreed revision cycle) · *implementation detail* (handled during engineering, no redesign) · *business-rule clarification* (confirm before implementation, because it changes workflow logic) · *new feature* (a later phase unless already in signed scope) · *visual preference* (consolidated into QA polish).
- Requested changes are consolidated into **one revision list**, not delivered piecemeal.
- The sign-off checklist freezes the baseline before development: the three-role model · both journeys · admin controls · approval/rejection states · product publishing rules · required fields, images, pricing, variants · inventory and cancellation behaviour · order statuses and transitions · commission policy · payout workflow · refund policy · payment and shipping methods for Phase 1 · terminology · desktop and mobile direction · branding and design system.

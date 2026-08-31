# 09 · Security

A marketplace holds three things worth attacking: **customer payment journeys**, **seller money**, and **the commission control**. The threat model below is ordered by what an attacker actually gains.

## 9.1 Threat model

| # | Threat | Impact | Primary control |
|---|---|---|---|
| T1 | Seller reads or mutates another seller's orders / stock / earnings | Catastrophic — ends marketplace trust | Tenant isolation, 3 layers ([08](08-identity-roles-stores.md#83-tenant-isolation)) |
| T2 | Price or commission tampering at checkout | Direct financial loss | Server-side re-pricing; client totals never trusted |
| T3 | Payout fraud — inflated request, redirected bank details | Direct cash loss | Ledger-derived balance, admin approval, bank-change cooling-off + re-verification |
| T4 | Commission rate changed by an unauthorised actor | Revenue loss, seller dispute | Owner/Finance policy, append-only rate history, 7-day forward dating, no back-dating |
| T5 | Account takeover (customer, seller, admin) | Varies; admin is total | Argon2id, breach check, rate limiting, mandatory admin TOTP, session invalidation on password change |
| T6 | Stored XSS via seller-controlled content (titles, descriptions, policies) | Session theft at scale | Output escaping by default, strict CSP, sanitised rich text, no `dangerouslySetInnerHTML` |
| T7 | Malicious file upload posing as a product image | RCE / stored payload | Content-type sniffing, re-encode every image, serve from a separate origin |
| T8 | Webhook forgery / replay | Fake payments, fake refunds | Signature verification + `event_id` unique index + amount re-verification |
| T9 | Enumeration of orders, sellers, users | Data harvesting, competitive intel | ULID public ids, 404-not-403, rate limits |
| T10 | Card data exposure | PCI incident, existential | Card data never touches our servers — Stripe Elements only, SAQ-A scope |
| T11 | Scraping the catalogue | Competitive, cost | Cloudflare bot management, rate limits, no bulk API |
| T12 | Insider / staff error | Financial and reputational | Role separation, required reasons, append-only audit, 2FA |

## 9.2 Application security controls

**Authentication**
- Argon2id password hashing with tuned memory/time cost.
- Breached-password rejection at registration and change (k-anonymity range query — the password never leaves our server).
- **Admin: mandatory TOTP**, 30-minute idle session expiry, account lock for 15 minutes after repeated failures, and an error message that never reveals which of email or password was wrong. All three are stated in the prototype; all three are real controls.
- Login throttling by IP **and** by account, so distributed attempts on one account are still caught.
- Session fixation prevented by regenerating the session id on every privilege change; all sessions invalidated on password change.

**Authorisation**
- A Policy for every model; `authorize()` in every controller action; no controller reaches the database without a policy check.
- The route×role test matrix from [08](08-identity-roles-stores.md#82-roles).
- Global tenant scopes plus 404-not-403 on cross-tenant access.

**Input & output**
- Form Request validation on every write, with allow-lists. No `Model::update($request->all())` anywhere — enforced by a static-analysis rule.
- All Eloquent models declare `$fillable`, never `$guarded = []`.
- Every query is parameterised. Raw SQL requires a code-owner review.
- React escapes by default; `dangerouslySetInnerHTML` is banned by lint. Seller descriptions are plain text with line breaks (the prototype specifies exactly this), not HTML — which removes the entire sanitisation problem rather than managing it.
- **CSP**: `default-src 'self'`; scripts from self and Stripe; images from self and the media CDN; `frame-ancestors 'none'`; no `unsafe-inline` (Inertia SSR emits a nonce). Reported to a collection endpoint before being enforced.
- Security headers: HSTS with preload, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` denying camera/mic/geolocation.
- CSRF tokens on every state-changing request; `SameSite=Lax` session cookies, `Secure`, `HttpOnly`.

**File uploads**
- Extension **and** MIME sniffed from content, not from the header.
- Every image is **re-encoded** through an image library on upload. This destroys polyglot payloads and EXIF (which can carry a seller's home GPS coordinates — a real privacy leak in seller-uploaded photos).
- Size caps enforced server-side (5MB per the design system), dimension minimums checked (800×800 for product images).
- Stored in object storage on a **separate origin** with `Content-Disposition: attachment` for anything non-image, so a stored file can never execute in the app's origin.
- Uploads are pre-signed direct-to-R2 with a short TTL and a server-issued policy, so large files never traverse the app servers.

**Rate limiting** (Cloudflare at the edge, Laravel as the backstop)

| Endpoint | Limit |
|---|---|
| Login (customer / seller) | 5 / min / IP, 10 / hour / account |
| Admin login | 3 / min / IP, lock after 5 |
| Password reset request | 3 / hour / email |
| Registration | 5 / hour / IP |
| Checkout submit | 10 / hour / session |
| Payout request | 5 / day / seller |
| Search | 60 / min / IP |
| Product write (seller) | 120 / hour / seller |
| Everything else | 300 / min / IP |

**Business-logic controls** — the ones a generic checklist misses:
- Cart totals are **recomputed server-side** at checkout from live product rows. The client's number is display only.
- Stock is verified under a row lock inside the transaction ([05](05-inventory.md)).
- The commission rate is read server-side at snapshot time; it is never accepted from a request.
- Payout amount is validated against the **ledger-derived** balance, not a cached column, and the one-open-request rule is a database constraint.
- **Bank account changes** trigger re-verification and surface as context on the payout queue (the prototype shows "recently changed bank details" under the seller name — that is an anti-fraud control, not decoration).
- Refunds can never exceed the captured amount for that order; partial refunds accumulate against a remaining balance.
- Order state transitions are validated against an explicit state machine. Shipped requires a tracking number — server-side, matching the UI rule.

## 9.3 Data protection

| Data | Treatment |
|---|---|
| Passwords | Argon2id, never logged, never in an error report |
| Card numbers | **Never received.** Stripe Elements tokenises in the browser |
| Tax IDs (EIN), bank details | Encrypted at rest with a rotatable app key (Laravel encrypted casts); shown masked (`••4417`); decryption is audited |
| Email, phone, addresses | Encrypted at rest at the volume level (managed Postgres); redacted in logs |
| Session tokens | Redis, TLS in transit, rotated on privilege change |
| Interaction events | No PII; pseudonymous actor key ([07](07-recommendations.md#71-the-event-stream)) |
| Backups | Encrypted, separate credentials, restore tested quarterly |
| PII in logs and Sentry | Scrubbed by an allow-list serialiser — the default is to redact |

**Compliance posture**
- **PCI DSS SAQ-A** — achieved by never touching card data. Any future change that would route a PAN through our servers is an architecture-level decision requiring sign-off.
- **GDPR / CCPA** — export and deletion endpoints; deletion anonymises rather than destroys where financial records must be retained, and that retention is disclosed.
- **1099-K reporting** for US sellers — the earnings ledger already holds everything needed; the report is a query, not a data-model change.
- **Sales tax** — Phase 1 uses a flat rate per shipping state stored on the order (Decision 3). The `tax_source` column records which method produced the figure, so a provider integration later is distinguishable in history.

## 9.4 Infrastructure security

- **Cloudflare** in front of everything: DNS, TLS, WAF with the OWASP core ruleset, bot management, DDoS mitigation, and rate limiting at the edge before traffic costs us anything.
- TLS 1.2+ only, HSTS preloaded. Internal traffic between app and database over a private network with TLS.
- Database is **not publicly reachable**; access via a bastion or the platform's private networking. No developer holds production credentials for routine work.
- Secrets in a managed secret store, injected at runtime, never in the repo. `.env` is gitignored and a pre-commit secret scanner runs in CI.
- Least-privilege database roles: the app role cannot `DROP`, and cannot `UPDATE` or `DELETE` on append-only tables.
- Dependency scanning (`composer audit`, `npm audit`, Dependabot) with a policy: critical patched in 24h, high in 7 days.
- Container images rebuilt weekly for base-image CVEs.

## 9.5 Audit and incident response

**What is audited** (append-only, queryable, and mostly already required by the product):
seller status changes · product moderation decisions · commission rate changes · payout decisions · refunds · admin logins and failures · role changes · settings changes · decryption of sensitive fields · every order status transition with its actor.

The prototype defers a general admin activity log to Phase 1.1, but the **domain-specific audit trails are Phase 1** because the business rules already demand them — every rejection reason, every rate change, every ledger entry is a permanent record with an actor.

**Incident response**: a documented runbook covering detection (Sentry, Cloudflare, balance-reconciliation alerts), containment (revoke sessions, disable a seller, rotate keys, freeze payouts), assessment, notification (72-hour GDPR clock), and post-mortem. Reviewed at launch and quarterly.

## 9.6 Security in the development lifecycle

- Threat-model review for any change touching money, auth or tenancy.
- Every PR runs: static analysis (PHPStan level 8, ESLint, TypeScript strict), the tenant-isolation suite, the route×role matrix, dependency audit, and secret scanning.
- The **money invariant tests** from [03](03-data-model.md#34-invariants) run on every commit. A change that can break a ledger invariant cannot merge.
- Penetration test before public launch, focused on T1–T4.
- A `SECURITY.md` with a disclosure route and a response commitment.

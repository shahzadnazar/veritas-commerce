# M1 completion report — Veritas Commerce Marketplace

Identity, seller onboarding, store setup and platform governance, built on
the accepted M0 foundation. No architectural decision from M0 was reopened.

---

## 1. Branch and final commit

| | |
|---|---|
| Branch | `claude/veritas-marketplace-architecture-vov8c0` |
| Final commit | `b8048eae69d4faef19e4ff8a2d79309f7b991912` |
| Commits in M1 | 8, in the order the spec suggests: M0 gaps → identity and MFA → customer auth and store shell → application domain → admin review and RBAC → seller portal → notifications, isolation and audit → visibility policy and hardening |

No published history was rewritten and nothing was force-pushed.

## 2. Framework and package changes

No new runtime dependency was added in M1. The stack is unchanged from M0:
Laravel 13.29.0 on PHP 8.4.19, Inertia 3.3.1 with `@inertiajs/react` 2.3.27,
React 19.2.8, TypeScript 5.9.3 in strict mode, Vite 8.2.2, Tailwind 4,
PostgreSQL 16.13, Redis 7.

Tooling added: PHPStan 2.2.12 (pinned phar, see §3), plus two scripts —
`tools/install-phpstan.php` and `tools/annotate-models.php`.

## 3. M0 gaps closed

**A. Static analysis is a real gate.** PHPStan runs at level 6 over
`app`, `database`, `config`, `routes` and `tests`, and fails CI. It is not
made green by suppression: there are three `ignoreErrors` entries, each
scoped to one identifier and one pattern, each documented as existing only
because Larastan cannot be installed here, and each deliberately absent
from `phpstan.larastan.neon` so they disappear the moment it can be.

What replaces Larastan is real information rather than silence.
`tools/annotate-models.php` generates `@property` annotations for all 32
models from the live PostgreSQL catalog plus each model's `casts()`, so the
types cannot drift from the migrations by hand, and `tools/stubs/laravel.stub`
declares the handful of runtime-resolved APIs that reflection would
otherwise supply. `tools/phpstan.php` prefers `vendor/bin/phpstan` and
falls back to the pinned phar, printing which it used and whether Larastan
is present — CI installs Larastan properly and gets the stronger analysis.

*Environment note:* `codeload.github.com` is refused by this environment's
egress policy (403), so Composer cannot install Pest, Larastan or Deptrac
here. Release assets are reachable, which is how the PHPStan phar arrives.
CI is not subject to this and runs the full install.

**B. Docker is built and run, not read.** The `docker` job in
`.github/workflows/ci.yml` builds the images, starts the stack, waits for
the application to answer, runs `migrate:fresh --seed`, smoke-tests six
HTTP paths, asserts the guest redirects land in the right realm, asserts an
unknown store 404s, asserts the security headers are present, checks the
test database exists, and tears down.

*It could not be executed here.* `docker compose build` fails at
`php:8.4-cli-alpine` because Docker Hub's blob CDN
(`production.cloudfront.docker.com`) answers 403 through this environment's
proxy — the agent proxy's own status endpoint records the denials. No image
can be pulled, so no image can be built. `docker compose config` resolves
and all four services are declared. This is reported as unverified rather
than claimed: see §20 and §23.

**C. The admin second factor is real.** `Totp` implements RFC 6238 over
RFC 4226 using `hash_hmac`, verified against all four published test
vectors. Enrolment issues a secret and a provisioning URI, is completed
only by a valid code, and is confirmed separately from being generated — an
unconfirmed secret can never satisfy a login. Eight recovery codes are issued
at confirmation, stored as hashes, and each works exactly once. The secret
is encrypted at rest and is never serialised after setup. Starting
enrolment and regenerating recovery codes both require the password again.
Failures are throttled. Disabling is audited without the secret. An admin
who has not enrolled is confined to the enrolment screens.

**D. Brand and environment data are configuration.** `config/veritas.php`
carries identity, branding, media, commission, payouts, inventory and
seller settings; `.env.example` ships development placeholders. No display
name, support address, domain or currency appears in application logic —
`AreaShellsTest::the_platform_name_comes_from_configuration` holds that.

## 4. Identity

One identity system. A person is a `User`; being a customer, a seller, or
both is a property of what they belong to, not of which table they are in.
There is no second user table for sellers.

Implemented: registration, email verification over signed URLs,
sign-in and sign-out, password reset, password change requiring the current
password, and an email change that re-triggers verification. Sign-in is
case-insensitive on the address, refuses without disclosing which half was
wrong, throttles repeated failures per account and IP, and rotates the
session id on success. The password policy is defined once in
`AppServiceProvider` and includes a breach check via the Pwned Passwords
range API — k-anonymity, so the password never leaves the server — disabled
under `runningUnitTests()` so the suite makes no network call.

Two realms, two guards: `web` for customers and sellers, `admin` for staff,
with separate tables, sessions and cookies. Guards are always named. A
guest on an admin route is sent to the staff sign-in page, never the
customer one.

## 5. Admin MFA

As described in §3C. Sign-in is a single step that requires password *and*
code — the code field is not decorative, and a correct password with no
code does not authenticate (`JourneyTest::an_admin_signs_in_with_a_second_
factor_and_reviews` asserts exactly that). A recovery code is accepted in
place of a TOTP code, once. Codes ±1 time step are accepted; outside that
window they are refused. Verification compares every candidate even after a
match, so timing does not reveal which step succeeded.

New in this milestone: an admin holding `staff.reset_mfa` can reset someone
else's second factor from `/admin/staff`, with a written reason, which
deletes every recovery code and forces re-enrolment at next sign-in. They
cannot reset their own from there — doing it from a session they are
already inside proves nothing.

## 6. Seller application states

`draft → submitted → under_review → {approved | rejected | changes_requested}`,
with `changes_requested → submitted` closing the loop.

`changes_requested` is a first-class state, not a rejection with softer
wording. It is editable by the applicant, carries the reviewer's note
verbatim, and returns the applicant to a pre-filled form rather than a
blank one. `approved` and `rejected` are terminal.

Every change goes through `TransitionSellerApplication`, which validates
against the enum's own transition table, requires a reason where the target
state demands one, and writes the history row inside the same transaction.
`seller_application_events` is append-only and holds from-state, to-state,
actor type, actor id, reason and timestamp.

## 7. Approval transaction

One transaction creates the seller account, attaches the applicant as
Owner, links the application to the account and writes the history. A
failure part-way leaves an application still awaiting a decision, never a
seller with no owner or an owner with no seller.

Idempotent three ways, because a double-clicked Approve, a retried request
and a redelivered queue job all reach the same code:

1. The application row is locked `FOR UPDATE`, so concurrent calls
   serialise rather than interleave.
2. An already-approved application returns its existing account.
3. The membership is created with `firstOrCreate` over a unique index on
   `(seller_account_id, user_id)` — the index is the guarantee, the
   `firstOrCreate` is the friendly path to it.

`SellerApproved` is dispatched with `DB::afterCommit`, so no listener ever
sees a seller a rollback removed. Approving twice, and three times, each
yield exactly one account and one owner.

## 8. RBAC matrix

**Seller** — 11 capabilities × 7 roles:

| Role | Capabilities |
|---|---|
| Owner | all 11 |
| Administrator | store.manage, members.view, catalog.*, inventory.*, orders.*, finance.view — **not** members.manage, **not** payouts.request |
| Catalog manager | catalog.view, catalog.manage, inventory.view |
| Inventory manager | catalog.view, inventory.view, inventory.manage, orders.view |
| Fulfillment manager | catalog.view, inventory.view, orders.view, orders.manage |
| Finance manager | orders.view, finance.view |
| Viewer | catalog.view, inventory.view, orders.view |

Changing the team and moving money stay with the owner: those two are how a
compromised staff account becomes a stolen business.

**Admin** — 21 permissions × 7 roles (Super Admin, Marketplace Admin,
Seller Operations, Catalog Moderator, Finance Admin, Support, Analyst).
There is no `is_admin` boolean anywhere. Seller Operations holds
`seller.application.view/review`, `seller.approve/reject/suspend/reactivate`
and `seller.view_sensitive`, and holds no catalogue or finance authority.

Both are enforced server-side by middleware (`seller.can:`, `admin.can:`)
*and* re-checked in the controller, so neither alone is the only thing
between a role and an action. Suspension is answered once, in
`CurrentSeller::can()`: a suspended seller keeps every read and loses every
write.

## 9. Store

Create and edit through the seller portal: name, address, description,
logo, banner, support email and phone, ships-from city and state, shipping
and return policies, and open/closed. The store is resolved from the acting
membership — there is no store id in the URL or the payload for a request
to tamper with.

Slugs are normalised on the way in (`Aeris Kitchen Co.` → `aeris-kitchen-co`)
rather than bounced back, globally unique, checked against a reserved list,
and refused if they belonged to another store that still redirects from
them. A rename writes `store_slug_history` and the old address 301s to the
current one permanently — search equity moves with the seller. **No database
id appears in a public store URL.**

Media goes through `MediaStore`, an interface. `Store` knows nothing about
any provider. Type is decided by `mime_content_type()`, not the extension —
a shell script named `logo.jpg` is refused — and the stored path is
generated (`stores/{id}/logo/{ULID}.jpg`), so nothing of the uploader's
filename survives.

## 10. Public store foundation

`/stores/{slug}` renders branding, description, ships-from, policies,
contact and an honest empty product state. There are no invented product
cards; the catalogue arrives in M2 and the page says so.

The visibility policy is stated once, in `FindPublicStore`, and enforced
there for every public surface:

- approved seller, store open → public and indexable;
- approved seller, store closed → public, says it is not taking orders, and
  carries `noindex` so a fortnight's closure is not what search engines have
  on file;
- suspended or not yet approved → does not resolve at all. An empty shell
  would still be indexed and still look like a shop that had run out of stock.

SEO: title, meta description, canonical URL, robots directive and Open
Graph title/description/type/url, all rendered server-side under SSR.

## 11. Admin review screens

Custom Inertia + React, in the same design system as the rest. **Filament is
not installed and no package was added for the admin area.**

- `/admin/applications` — queue, filterable and searchable, defaulting to
  what is waiting on the team.
- `/admin/applications/{id}` — the full record and its complete history.
  The tax id is withheld unless the reviewer holds `seller.view_sensitive`.
- Decisions: begin review (assigns the reviewer), approve, reject, request
  changes. Every negative decision requires a reason of at least ten
  characters, enforced server-side in `DecisionRequest` *and* again in the
  transition guard — a request that skips the UI cannot reject without one.
- `/admin/sellers` — governance of sellers already trading: suspend (reason
  required) and reactivate.
- `/admin/staff` — staff accounts and second-factor state, with reset.

## 12. Seller portal screens

- `/seller` — dashboard: identity, approval state, role, store address and a
  four-step setup checklist read from the record itself. **No metrics.**
  A seller who has just been approved has no orders and no earnings, so the
  screen shows what is true rather than stat cards reading zero.
- `/seller/apply` — apply, and see where an application stands. Under
  `changes_requested` the form returns pre-filled with the reviewer's note
  above it.
- `/seller/store` — store setup.
- `/seller/team` — members, roles, pending invitations, invite and revoke.
- `/seller/invitations/{id}` — accept an invitation.

Applying and accepting an invitation happen before the person is a member of
anything, so they render an onboarding shell rather than the portal sidebar,
whose links would 404 for exactly those people.

## 13. Audit events

`seller.application.submitted`, `seller.application.review_started`,
`seller.application.changes_requested`, `seller.approved`, `seller.rejected`,
`seller.suspended`, `seller.reactivated`, `seller.member.invited`,
`seller.member.invitation_revoked`, `seller.member.added`,
`seller.member.removed`, `seller.member.role_changed`, `seller.store.updated`,
`customer.registered`, `customer.profile_updated`, `customer.password_changed`,
`admin.signed_in`, `admin.sign_in.failed`, `admin.sign_in.two_factor_failed`,
`admin.mfa.enrolment_started`, `admin.mfa.enabled`, `admin.mfa.disabled`,
`admin.mfa.recovery_codes_regenerated`.

An email change is a profile update, so it is covered by
`customer.profile_updated` with the before and after values.

Each carries actor type, actor id, subject type, subject id, before/after
values where they apply, reason where one is required, IP address and
timestamp. `audit_logs` refuses updates and deletes at the model.

Redaction is central, not per-caller: `RecordAuditEvent` scrubs any key
containing `password`, `secret`, `token`, `recovery_code`, `code_hash`,
`two_factor`, `remember_token`, `api_key` or `authorization`, recursively.
Two tests hold it — one passing every sensitive shape directly, one sweeping
every record written during a realistic session for `$2y$`, `password`,
`secret` and `Bearer `.

## 14. Notifications

All queued (`ShouldQueue`), all through Laravel's mail abstraction and
configuration: email verification, password reset, application submitted,
approved, rejected, changes requested, seller suspended, seller reactivated,
and team invitation.

Every send happens after commit, so a decision that rolls back is never
emailed — an email cannot be recalled, a transaction can. The invitation is
addressed on-demand because the invitee may not have an account yet, and the
plaintext token exists only in that email. **No test sends anything:**
`Notification::fake()` intercepts every send, and one test asserts each
notification class implements `ShouldQueue`.

## 15. Security controls

- Three-layer tenant isolation: a global scope on every seller-owned model,
  404-not-403 on a foreign record (a 403 confirms it exists), and an
  adversarial test suite that tries to cross the boundary on every route.
- Nothing trusts a `seller_id`, `store_id` or `membership_id` from a
  request. The tenant is derived from the authenticated user's membership,
  server-side, every time. Five request shapes that try to nominate another
  tenant are tested and all resolve to the actor's own records.
- Guards are always named, never `$request->user()` bare.
- Invitation tokens are hashed, single-use under a row lock, expiring, and
  redeemable only by the invited address.
- Uploads are type-sniffed and path-generated.
- Baseline response headers on every request: `X-Frame-Options: DENY`,
  `Content-Security-Policy: frame-ancestors 'none'`,
  `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`, a `Permissions-Policy`
  denying camera, microphone and geolocation, and HSTS on HTTPS responses
  only. Portals carry `X-Robots-Tag: noindex`; the storefront must not.
- Throttling on customer sign-in, admin sign-in and MFA verification.
- Rejection and suspension reasons are required by the domain, not the form.

## 16. Schema changes

Two migrations, both reversible.

`2026_02_01_000100_add_admin_mfa_and_identity_tables`
- new table `admin_recovery_codes` (admin_user_id, code_hash, used_at)
- `admin_users` += `two_factor_enrolled_at`, `last_login_at`, `last_login_ip`

`2026_02_01_000200_extend_seller_application_and_membership`
- `seller_applications` += `website`, `intended_categories`,
  `expected_catalogue_type`, `operational_notes`, `submitted_at`,
  `review_started_at`, `reviewer_admin_id`, `internal_notes`,
  `seller_account_id`
- new table `seller_application_events` — append-only history
  (public_id, seller_application_id, from_status, to_status, actor_type,
  actor_id, reason, created_at)
- new table `seller_application_documents` (public_id, kind, disk, path,
  original_name, mime, bytes, checksum, uploaded_at)
- new table `seller_invitations` (public_id, seller_account_id, email, role,
  token_hash, status, invited_by_user_id, accepted_by_user_id, expires_at,
  accepted_at, revoked_at) with a **partial unique index**
  `seller_invitations_one_live_per_email` — one live invitation per address
  per seller, enforced by the database
- `stores` += `timezone`, `business_city`, `business_state`, `business_country`

`migrate:fresh --seed` runs clean from an empty database.

## 17. Test count

**242 tests, 8,605 assertions — all passing.**

| Suite | Tests |
|---|---|
| Invariants (M0 baseline, all still passing) | 72 |
| Admin seller review | 13 |
| Area shells and headers | 14 |
| Audit trail | 11 |
| Admin two-factor | 22 |
| Customer authentication | 18 |
| End-to-end journeys | 4 |
| Notifications | 8 |
| Tenant isolation and security | 16 |
| Seller application lifecycle | 13 |
| Seller team and invitations | 17 |
| Store setup and public page | 15 |
| Unit (Money, Reference) | 13 |

All 38 tests the specification names are covered. The M0 baseline grew from
97 to 242 with no test removed or weakened.

## 18. PHPStan

`php tools/phpstan.php analyse` — **0 errors**, level 6, over app, database,
config, routes and tests. Run here against the pinned phar without Larastan;
CI runs it with Larastan installed and the stricter configuration.

## 19. TypeScript, lint and builds

| Gate | Result |
|---|---|
| `tsc --noEmit` (strict, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`) | pass |
| `eslint resources/js` | pass, 0 problems |
| `prettier --check resources/js` | pass |
| `npm run build` (client) | pass — 3 area bundles, pages code-split |
| `npm run build:ssr` | pass — `bootstrap/ssr/ssr.js`, 31.95 kB |
| `php artisan statuses:export --check` | pass — generated TypeScript matches the PHP registry byte for byte |

## 20. Docker validation

**Not verified in this environment, and not claimed.** `docker compose build`
fails pulling `php:8.4-cli-alpine`: Docker Hub's blob CDN
(`production.cloudfront.docker.com`) returns 403 through this environment's
egress proxy. No base image can be pulled here, so no image can be built.

What was verified: `docker compose config` resolves and declares all four
services (postgres, redis, app, vite). The `docker` CI job performs the real
build, startup, migration, HTTP smoke and teardown on every push.

## 21. HTTP and E2E smoke tests

Run against a live server (`php artisan serve`) on a freshly migrated,
seeded database:

| Path | Result |
|---|---|
| `/` | 200 |
| `/login`, `/register` | 200 |
| `/admin/login` | 200 |
| `/up` | 200 |
| `/admin` (guest) | 302 → `/admin/login` |
| `/seller` (guest) | 302 → `/login` |
| `/stores/{live-slug}` | 200, full SEO head rendered under SSR |
| `/stores/does-not-exist` | 404 |

Security headers confirmed present on the storefront; `X-Robots-Tag:
noindex, nofollow` confirmed on the portals and confirmed absent on the
storefront. With SSR running, each page renders exactly one `<title>`, each
distinct: `Veritas Commerce`, `Sign in — Veritas Commerce`,
`Create an account — Veritas Commerce`, `{Store} — Veritas Commerce`.

The four journeys the specification names are also automated in
`tests/Feature/JourneyTest.php`, walking the real routes in order:

1. register → verify → sign out → sign in
2. apply → review → approve → portal opens → store created → public page live
3. admin password-only refused → password + TOTP → review queue
4. Seller A walking Seller B's URLs, refused at every step

## 22. Issues found and fixed

**An expired invitation stayed pending forever.** The action marked it
expired inside the transaction that then refused the redemption, so the
write rolled back with the refusal. Now the refusal raises a distinct
exception and the expiry is recorded outside the transaction. Found by its
own test.

**The store page emitted two `<title>` tags under SSR.** The Blade shell's
and the page's. A crawler reads the first, so every store page was titled
`Veritas Commerce`. The title now lives in `StorefrontLayout` — one per
page — and the shell supplies its fallback only when SSR is off.

**The public page read ships-from from the wrong record.** The store form
writes it to `stores`; the page read it from `seller_accounts`, so it was
always blank.

**`Auth::guard()` narrowing was repeated at four call sites**, one of which
had drifted. It now lives in `App\Support\Guards`.

**The model annotator stacked a second generated block on every re-run.**
Pint reflows the block after generation, inserting a blank comment line the
strip pattern did not tolerate; and a single global replace consumed the
separator between two stacked blocks, leaving the second unmatched. Fixed
to be genuinely idempotent, verified by running it twice.

**`FindPublicStore`'s comment claimed a rule the code did not enforce.**
Resolved by writing the policy down properly and implementing it (§10).

**Tests calling `actingAs()` without a guard** wrote a customer into the
admin guard, because `actingAs()` with no guard targets whichever guard is
currently the default. Every test now names its realm — the same reason the
application never calls `$request->user()` bare.

**A module boundary violation of my own**, caught by `ModuleBoundaryTest`:
a seller controller importing Orders models. Resolved with a query in the
Orders module returning DTOs.

## 23. Remaining blockers before M2

1. **Docker build unverified here** (§20). CI covers it; someone with
   unrestricted registry access should confirm one green run.
2. **Larastan, Pest and Deptrac cannot be installed in this environment**
   (§3A). CI installs them. The three narrowly-scoped `ignoreErrors` entries
   should be deleted once Larastan runs everywhere — they are already absent
   from the Larastan configuration.
3. **Queue workers are not yet run in any environment.** Notifications are
   queued and correct, but nothing drains the queue outside the synchronous
   test driver. M2 needs a worker in compose and in deployment.
4. **No object storage is configured.** `MediaStore` is the seam and the
   local driver satisfies it; the Cloudflare R2 driver is an M2 binding, not
   a code change inside the modules.
5. **Application documents are modelled but not uploaded.** The
   `seller_application_documents` table and the required-documents list
   exist; the upload flow and reviewer download are M2.
6. **Nothing to sell yet.** Everything in the "do not build" list is
   genuinely absent — no catalogue, offers, inventory screens, cart,
   checkout, orders, payouts, recommendations, search, reviews, coupons or
   shipping integrations. The seller dashboard and the public store page
   both carry honest empty states rather than placeholders.

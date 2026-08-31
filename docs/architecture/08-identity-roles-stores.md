# 08 · Identity, roles & store management

## 8.1 Three audiences, two authentication realms

| Realm | Guard | Session | MFA | Route prefix |
|---|---|---|---|---|
| Customers **and** sellers | `web` (users table) | 30-day remember, 2h idle for sellers | optional (Phase 1.1 for sellers) | `/`, `/seller` |
| Platform staff | `admin` (separate table, separate session cookie) | **30-minute idle expiry** | **mandatory TOTP** | `/admin` |

**Why sellers share the customer table.** A seller is a person who also shops. Two password records for one human is a support burden and a security liability. A `users` row gains a seller capability by being linked to a `sellers` row; the seller portal is authorised by that link, not by a separate login.

**Why admin is fully separate.** The prototype states it: *"Separate route and separate session from customer and seller accounts — an admin cannot be signed into two roles at once."* This is a real security boundary — it means a stolen customer session can never be escalated toward admin, and staff credentials never traverse the customer surface. Different table, different guard, different cookie name, different session lifetime.

## 8.2 Roles and the permission matrix

Staff roles from the prototype: **Owner · Operations · Finance · Support**.

| Capability | Owner | Operations | Finance | Support |
|---|:--:|:--:|:--:|:--:|
| View dashboard | ✅ | ✅ | ✅ | ✅ |
| Approve / reject seller applications | ✅ | ✅ | — | — |
| Suspend / reactivate a seller | ✅ | ✅ | — | — |
| Approve / reject products | ✅ | ✅ | — | — |
| Manage categories & brands | ✅ | ✅ | — | — |
| View all orders | ✅ | ✅ | ✅ | ✅ |
| Issue a refund | ✅ | ✅ | ✅ | — |
| View payments | ✅ | ✅ | ✅ | ✅ |
| **Change the commission rate** | ✅ | — | ✅ | — |
| View seller earnings | ✅ | ✅ | ✅ | — |
| Approve / reject payouts | ✅ | — | ✅ | — |
| Company & payment settings | ✅ | — | — | — |
| Operational settings | ✅ | ✅ | — | — |
| Invite staff / change roles | ✅ | — | — | — |

**Enforcement is server-side on every request.** The prototype is emphatic and it is the correct instinct: *"Permissions are enforced server-side on every request, not just hidden in the sidebar."* Hiding a nav item is a courtesy to the user, never a control. Implementation:

- A Laravel **Policy** per resource, registered and invoked via `authorize()` in every controller action.
- A middleware asserting the role for the whole `/admin/{area}` group as defence in depth.
- A test that enumerates every admin route × every role and asserts the expected 200/403. If a route is added without a policy, this test fails — which is the point.

## 8.3 Tenant isolation — the highest-severity risk in the product

A seller reading or mutating another seller's orders, products, stock or earnings is the failure that ends a marketplace's credibility. It is prevented in three independent layers:

**Layer 1 — a global scope on every seller-owned model.**
```php
// app/Modules/Sellers/Concerns/BelongsToSeller.php
protected static function bootBelongsToSeller(): void
{
    static::addGlobalScope('seller', function (Builder $q) {
        if ($seller = CurrentSeller::idOrNull()) {
            $q->where($q->getModel()->getTable().'.seller_id', $seller);
        }
    });

    static::creating(fn ($m) => $m->seller_id ??= CurrentSeller::id());
}
```
Applied to `products`, `product_variants`, `stock_levels`, `stock_movements`, `sub_orders`, `seller_ledger_entries`, `payout_requests`, `stores`.

**Layer 2 — route-model binding resolves within the tenant.** A seller requesting `/seller/orders/{sub_order}` for another seller's sub-order gets a **404**, not a 403 — a 403 confirms the record exists, which is itself a leak.

**Layer 3 — an automated test suite that tries to break it.** For every seller-scoped route, a test authenticates as seller A and requests seller B's resource, asserting 404. New routes are added to this suite by a route-enumerating test, so forgetting is not an option.

**Deliberate exception:** admin queries bypass the scope through an explicit, named, audited path (`Seller::withoutTenantScope()`), never by omission.

## 8.4 Seller lifecycle

```
     APPLY                    REVIEW                 OPERATE
  ┌──────────┐   submit    ┌──────────┐  approve  ┌──────────┐
  │  form    ├────────────►│ pending  ├──────────►│ approved │
  └──────────┘             └────┬─────┘           └────┬─────┘
       ▲                        │ reject               │ suspend
       │  correct & re-apply    ▼                      ▼
       │                   ┌──────────┐           ┌───────────┐
       └───────────────────┤ rejected │           │ suspended │
                           └──────────┘           └─────┬─────┘
                                                        │ reactivate
                                                        └──► approved
```

Rules taken directly from the prototype and made binding:

- **One form, three sections, no wizard** — *"sellers abandon multi-step onboarding"*. Business details, contact, catalogue & volume, plus explicit acceptance of the seller agreement and the commission rate.
- **Re-applying edits the same record**, it never creates a duplicate. The application reference (`APP-1180`) is stable across attempts.
- **A rejection stores the admin's reason verbatim** and shows it to the applicant unedited. The reason is a required field, validated server-side — the admin UI's required-reason dialog is the visible half of a server rule.
- **Approval unlocks store setup**, not the full portal: the store must have a name, slug, logo and policies before the public page goes live.
- **Tax ID locks after approval.** Changing it requires support intervention and writes an account event.
- **Suspension** hides listings from the storefront (a suspended store returns **404**), freezes the balance against payout, and shows the seller a banner — but **never cancels open orders**, which must still be fulfilled. Suspension always demands a written reason.
- **Every state change writes an append-only `seller_account_events` row** with actor and note. The admin seller-detail screen renders this history directly.

## 8.5 Store management

| Field | Rule |
|---|---|
| Store name | Required, 2–60 chars, no uniqueness requirement |
| **Slug** | Globally unique (citext), `[a-z0-9-]`, 3–40 chars, validated live during setup, reserved words blocked (`admin`, `api`, `seller`, `checkout`, `cart`, `search`, `store`) |
| Description | ≤ 500 chars, plain text with line breaks, shown on the public store page |
| Logo | Square, min 400×400, ≤ 5MB, JPG/PNG |
| Banner | 1600×400, ≤ 5MB |
| Support email / phone | Required, shown to customers |
| Shipping policy | Required, shown on the store page **and every product page** |
| Return policy | Required, same placement |
| Ships from | City + state, shown on product pages |
| Store open toggle | A seller can close their store (vacation mode) — listings become unpurchasable but remain visible |

**Slug changes leave a permanent redirect.** A `store_slug_history` row and a 301 forever. A marketplace whose seller URLs rot loses its accumulated SEO equity, which is the seller's asset as much as the platform's.

**The store setup preview is the real thing.** The prototype renders the actual customer-facing store page components at 0.5 scale inside the setup screen. That is a build instruction: the preview imports the same React components as the public page, not a separate mock. If they diverge, the preview lies.

## 8.6 Customer account management

- **Guest checkout is supported.** An order is claimed into an account afterwards via a signed, single-use link in the confirmation email (open Decision 4 — recommended as designed).
- **Password**: 8-character minimum, checked against a breached-password list (`have-i-been-pwned` k-anonymity API), strength shown as advisory not blocking, hashed with Argon2id.
- **Email change requires confirmation from both the old and the new address.**
- **Order emails cannot be disabled** — they are transactional. The toggle is rendered **disabled rather than hidden**, which the prototype explains stops support asking why. Marketing emails are opt-in.
- **Addresses are records, not text blobs** — the same field set as checkout, so one can be selected there in a single tap. Exactly one default; removing the default prompts the customer to pick another rather than promoting silently.
- **Past orders keep their own address snapshot.** Editing a saved address never rewrites history.
- **Account deletion** (GDPR/CCPA): anonymise the user row, retain orders with a redacted customer reference — financial records cannot be deleted, and this is disclosed in the privacy policy.

## 8.7 The seller's own team — deferred, seamed

Phase 1 has one login per seller. Multiple staff per store is a common Phase 2 request. The seam: authorisation already asks *"which seller is the current actor scoped to?"* via `CurrentSeller`, not *"is this user the owner of this seller?"*. Adding a `seller_users` pivot with roles changes the resolution of `CurrentSeller` and nothing else.

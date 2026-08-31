# 03 · Data model

## 3.1 Non-negotiable conventions

| Convention | Rule | Why |
|---|---|---|
| **Money** | Every amount is `bigint` minor units (cents) plus a `char(3)` currency. Never float, never decimal-in-PHP-float. Column names end `_minor`. | Rounding errors in a commission split are unrecoverable |
| **Identifiers** | `bigint` primary keys internally; **ULID** public identifiers on every externally visible record (`public_id`) | Sequential IDs leak volume and enable enumeration |
| **Human references** | `VC-24081` orders, `APP-1180` applications, `PO-2044` payouts, generated from a per-type sequence | Support and email need a short reference; the prototype uses these formats |
| **Time** | `timestamptz`, UTC in the database, rendered in the viewer's zone | Marketplace spans zones from day one |
| **Append-only tables** | No `UPDATE`, no `DELETE`. Revoked at the DB role level where possible, asserted by a test otherwise | The audit story is the product |
| **Soft delete** | Only where the UI offers "archive". Never on financial rows | |
| **Enum storage** | Postgres `text` + a `CHECK` constraint, mirrored by a PHP backed enum | Migrating a native enum type is painful; a check constraint is not |
| **Foreign keys** | Always, with explicit `ON DELETE RESTRICT` on financial links | |

## 3.2 Core tables

### Identity & access

```
users               id, public_id, email(citext, unique), password_hash,
                    first_name, last_name, phone, email_verified_at,
                    marketing_opt_in, created_at, updated_at
admin_users         id, public_id, email, password_hash, role,
                    two_factor_secret(encrypted), two_factor_confirmed_at,
                    last_active_at, locked_until, failed_attempts
customer_addresses  id, user_id, label, name, line1, line2, city, state,
                    postcode, country, phone, is_default
sessions            standard Laravel, Redis-backed
```

`role` on `admin_users` ∈ `owner | operations | finance | support`. Seller identity is a `users` row linked to a seller (see below), so one person can be both a customer and a seller without two passwords.

### Sellers & stores

```
seller_applications id, public_id, reference(APP-####), user_id,
                    legal_name, trading_name, business_type, tax_id(encrypted),
                    address_*, contact_name, contact_role, contact_email,
                    contact_phone, primary_category_id, planned_listings,
                    existing_site, blurb, terms_accepted_at,
                    status(pending|approved|rejected),
                    decided_at, decided_by, decision_reason, created_at

sellers             id, public_id, user_id, application_id,
                    legal_name, tax_id(encrypted), business_type,
                    status(pending|approved|suspended),
                    approved_at, suspended_at, suspension_reason,
                    ships_from_city, ships_from_state

stores              id, seller_id, name, slug(unique, citext), description,
                    logo_media_id, banner_media_id, support_email,
                    support_phone, shipping_policy, return_policy,
                    is_open, created_at

store_slug_history  id, store_id, old_slug, changed_at   -- permanent 301s
seller_account_events  id, seller_id, event, actor_type, actor_id, note,
                       created_at            -- APPEND ONLY
```

### Catalogue

```
categories   id, parent_id(null = top level), name, slug, description,
             is_visible, position          -- two levels only in Phase 1
brands       id, name, slug, owner_seller_id(null = platform-wide),
             status, merged_into_brand_id
brand_aliases id, brand_id, slug           -- merges keep old URLs alive

products     id, public_id, seller_id, category_id, brand_id,
             title, description, condition(new|refurbished|used),
             base_sku, search_keywords,
             status(draft|pending_review|published|rejected|archived),
             review_reason, published_at, archived_at,
             created_at, updated_at

product_variants id, product_id, name, sku(unique per seller),
                 price_minor, compare_at_price_minor, position,
                 is_active
product_options  id, product_id, name, position         -- "Colour", "Capacity"
product_option_values id, option_id, value, position
variant_option_values variant_id, option_value_id       -- the combination
product_media    id, product_id, media_id, position, is_main
```

**Constraint:** `compare_at_price_minor IS NULL OR compare_at_price_minor > price_minor` — the same rule the prototype validates in the UI, enforced in the database so no code path can violate it.

### Stock

```
stock_locations id, seller_id, name, is_default    -- one row per seller now
stock_levels    id, variant_id, location_id, on_hand,
                UNIQUE(variant_id, location_id)
stock_holds     id, variant_id, location_id, quantity, order_id,
                state(held|consumed|released), expires_at, created_at
stock_movements id, variant_id, location_id, change, resulting_on_hand,
                reason(order_placed|order_cancelled|refund_restock|
                       restock_received|count_correction|damaged|
                       returned_to_supplier|manual_edit),
                actor_type(seller|system|admin), actor_id, note,
                order_id, created_at            -- APPEND ONLY
```

`available = on_hand − Σ(active holds)`. Never stored, always derived. See [05](05-inventory.md).

### Cart & orders

```
carts        id, public_id, user_id(nullable), session_token,
             created_at, expires_at
cart_lines   id, cart_id, variant_id, quantity,
             unit_price_minor_at_add        -- shown, but re-priced at checkout

orders       id, public_id, reference(VC-#####), user_id(nullable for guest),
             email, status, currency,
             items_total_minor, shipping_total_minor, tax_total_minor,
             grand_total_minor,
             ship_name, ship_line1, ship_line2, ship_city, ship_state,
             ship_postcode, ship_country, ship_phone,   -- SNAPSHOT, not FK
             placed_at, completed_at, cancelled_at

sub_orders   id, order_id, reference(VC-#####-A), seller_id,
             status(placed|processing|packed|shipped|delivered|
                    cancelled|refunded),
             items_total_minor, shipping_total_minor, tax_total_minor,
             order_total_minor,
             -- the snapshot, written once, never updated:
             commission_rate_snapshot numeric(5,2),
             commission_rate_effective_at timestamptz,
             commission_amount_minor bigint,
             seller_earning_minor bigint,
             snapshotted_at timestamptz

order_lines  id, sub_order_id, variant_id, product_id,
             -- snapshots of everything the customer saw:
             product_title, variant_name, sku,
             unit_price_minor, quantity, line_total_minor,
             tax_amount_minor, tax_rate_snapshot, tax_source

order_status_events id, sub_order_id, from_status, to_status,
                    actor_type(customer|seller|admin|system), actor_id,
                    note, created_at         -- APPEND ONLY

shipments    id, sub_order_id, carrier, tracking_number, shipped_at
```

**Why `sub_orders` carries the money.** A cart can hold items from several sellers. Commission, earning and fulfilment are all per-seller. `orders` is the customer's view (one number, one payment, one address); `sub_orders` is the commercial and operational record. This resolves open Decision 1 in the direction the prototype already draws: the customer sees `VC-24081`, the seller sees `VC-24081-A`.

### Payments

```
payment_attempts id, order_id, provider, provider_ref, method,
                 amount_minor, status(pending|succeeded|failed),
                 failure_code, failure_message, raw_response(jsonb),
                 created_at                      -- APPEND ONLY, every try
payments         id, order_id, attempt_id, provider_charge_id,
                 amount_minor, captured_at, status
refunds          id, public_id, reference, order_id, sub_order_id,
                 payment_id, amount_minor,
                 commission_reversed_minor, earning_reversed_minor,
                 reason, requested_by, status, created_at
provider_webhooks id, provider, event_id(unique), type, payload(jsonb),
                  received_at, processed_at, error   -- idempotency ledger
```

### Commission & payouts

```
commission_rates  id, rate numeric(5,2), effective_from, note,
                  set_by_admin_id, created_at        -- APPEND ONLY
                  -- the "current" rate is the latest row with
                  -- effective_from <= now(); scheduling = a future row

seller_ledger_entries
  id, public_id, seller_id,
  type(earning|earning_reversal|payout|payout_reversal|adjustment),
  amount_minor,                       -- signed: + credit, − debit
  balance_after_minor,                -- running balance, written at insert
  sub_order_id, refund_id, payout_id, -- whichever applies
  note, created_at                    -- APPEND ONLY

payout_requests id, public_id, reference(PO-####), seller_id,
                amount_minor, bank_account_id,
                status(requested|approved|rejected|cancelled),
                requested_at, decided_at, decided_by_admin_id,
                decision_reason, settlement_ref
seller_bank_accounts id, seller_id, label, last4, holder_name,
                     details(encrypted), verified_at
```

**Balance is never a column on `sellers`.** It is `SUM(amount_minor)` over the ledger, materialised into `seller_balances (seller_id, available_minor, held_minor, updated_at)` as a **cache** that a nightly reconciliation job recomputes and alerts on mismatch. Reads use the cache; correctness lives in the ledger.

### Media, notifications, settings

```
media          id, public_id, disk, path, mime, bytes, width, height,
               checksum, uploaded_by_type, uploaded_by_id, created_at
media_variants id, media_id, kind(thumb|card|hero|grayscale), path, width
notifications_sent id, template, recipient_type, recipient_id, email,
                   subject, provider_message_id, sent_at, opened_at
platform_settings key(unique), value(jsonb), updated_by, updated_at
```

## 3.3 Indexing plan (the ones that matter under load)

```sql
-- catalogue browse & facets
CREATE INDEX ON products (status, category_id, published_at DESC)
  WHERE status = 'published';
CREATE INDEX ON products (seller_id, status);
CREATE INDEX ON product_variants (product_id, is_active);
CREATE INDEX ON product_variants (sku);

-- full-text (Phase 1 search before Meilisearch)
ALTER TABLE products ADD COLUMN search_vector tsvector
  GENERATED ALWAYS AS (
    setweight(to_tsvector('english', coalesce(title,'')), 'A') ||
    setweight(to_tsvector('english', coalesce(search_keywords,'')), 'B') ||
    setweight(to_tsvector('english', coalesce(description,'')), 'C')
  ) STORED;
CREATE INDEX ON products USING GIN (search_vector);
CREATE INDEX ON products USING GIN (title gin_trgm_ops);   -- typo tolerance

-- order queues (the seller's and admin's daily view)
CREATE INDEX ON sub_orders (seller_id, status, created_at DESC);
CREATE INDEX ON sub_orders (order_id);
CREATE INDEX ON orders (user_id, placed_at DESC);
CREATE INDEX ON order_status_events (sub_order_id, created_at);

-- money
CREATE INDEX ON seller_ledger_entries (seller_id, created_at DESC);
CREATE INDEX ON payout_requests (status, requested_at)
  WHERE status = 'requested';
CREATE UNIQUE INDEX ON payout_requests (seller_id)
  WHERE status = 'requested';        -- enforces "one open request at a time"

-- stock
CREATE INDEX ON stock_holds (variant_id) WHERE state = 'held';
CREATE INDEX ON stock_movements (variant_id, created_at DESC);

-- webhook idempotency
CREATE UNIQUE INDEX ON provider_webhooks (provider, event_id);
```

That partial unique index on `payout_requests` is worth calling out: the prototype states "one open request at a time" as a business rule, and this makes it impossible to violate even under a double-submit race.

## 3.4 Invariants asserted by tests (not just by intent)

1. `sub_orders.commission_amount_minor + sub_orders.seller_earning_minor = sub_orders.order_total_minor` for every snapshotted row.
2. `sub_orders.commission_amount_minor = round(order_total_minor × commission_rate_snapshot / 100)` using banker's-rounding-free `intdiv` semantics, deterministic across languages.
3. For every seller: `SUM(seller_ledger_entries.amount_minor) = seller_balances.available_minor + seller_balances.held_minor`.
4. Every `seller_ledger_entries.balance_after_minor` equals the running sum up to that row, ordered by `id`.
5. No row in an append-only table has `updated_at > created_at`.
6. Every status value present in the database has a mapping in `statusTone()` (the Phase 6 finding-1 test).
7. `available` for any variant is never negative.
8. Every `refunds` row has a matching negative ledger entry at the **original order's** rate, not today's.

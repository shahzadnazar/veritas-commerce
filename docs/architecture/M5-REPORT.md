# M5 completion report — payments, financial recording and the refund foundation

Answers the forty-nine questions in the M5 brief, in order, followed by a
traceability table against the seventy-eight required behaviours, the six
concurrency proofs and the ten security proofs. Where a gate could not be
run in this environment it says so rather than claiming a result.

---

## 1. Branch

`claude/veritas-marketplace-architecture-vov8c0`

## 2. Final SHA

The last code commit is
`630ff7ec9aa1ab18bf4abf3c8d51554a1841c519`; the branch head is the commit
carrying this report, which is its immediate child. Nothing was
force-pushed and no published history was rewritten.

## 3. M5 commit range

`ce7273c..HEAD` — ten code commits plus this report, oldest first.

| SHA       | Subject                                                            |
| --------- | ------------------------------------------------------------------ |
| `d5f5440` | feat: add the payment provider port and Stripe adapter             |
| `ead1f5c` | feat: finalize verified payments and record financial obligations  |
| `32e3e30` | feat: announce a paid order to the customer and each seller once   |
| `62d0c1d` | feat: give the customer a payment surface that cannot decide it    |
| `a9589ca` | feat: mount Stripe Elements and trust only the server              |
| `5eed4a8` | feat: complete the refund path and somewhere to run it             |
| `9e5c9b4` | feat: show the payment to the customer and the seller, and record it |
| `2700fc5` | test: expand the payment concurrency, security and lifecycle suites |
| `5422f30` | ci: smoke the payment lifecycle against the built image            |
| `630ff7e` | test: exercise the payment queue against real Redis                |

## 4. Stripe SDK version

`stripe/stripe-php` **v21.3.1**, pinned in `composer.json` as `^21.3` and
locked to commit `12986995cd5e229cc094d4b57de056f8e2e6e5a9`.

One environment accommodation, reported rather than hidden: this sandbox
cannot reach `api.github.com`, so Composer could not download the dist
archive. The dependency is declared normally in `composer.json` and
`composer.lock` (Packagist metadata is reachable); the package was then
cloned at tag `v21.3.1` into `vendor/stripe/stripe-php`, verified against
the lock's `source.reference`, registered in `vendor/composer/installed.json`
and autoloaded via `composer dump-autoload`. **CI and Docker run an
ordinary `composer install` and are unaffected.** No vendor code was
modified.

## 5. Provider abstraction changes

M0 shipped a half-built `PaymentGateway` port with a `FakePaymentGateway`,
a `WebhookEvent` and a `CaptureResult`. Rather than leave two competing
ports, that scaffolding was **retired** and replaced by one:

`App\Modules\Payments\Contracts\PaymentProvider` —

| Method             | Purpose                                                     |
| ------------------ | ----------------------------------------------------------- |
| `name()`           | the provider's key, which scopes event uniqueness           |
| `preparePayment()` | amount, currency, idempotency key, reference-only metadata  |
| `retrievePayment()`| the provider's own answer, over an authenticated connection |
| `cancelPayment()`  | abandon before capture                                      |
| `refundPayment()`  | amount, idempotency key, reason                             |
| `retrieveRefund()` | the provider's own answer about a refund                    |
| `parseEvent()`     | verify a signature and return a platform-shaped event       |

Two drivers implement it: `StripePaymentProvider` (the only file in the
application that knows Stripe exists) and `FakePaymentProvider` (an
in-memory double with HMAC-SHA256 signing, used by the suite and the CI
smoke). The binding is a singleton in `AppServiceProvider`, selected by
`config('veritas.payments.provider')`.

Everything Stripe-shaped stops at the adapter: `PaymentIntent`, `Refund`,
the SDK's exceptions and its status strings. `StripeAdapterTest` asserts
that no Stripe-only status word (`requires_capture`, `canceled`,
`requires_confirmation`) appears in the platform's own enum.

## 6. PaymentIntent / preparation architecture

`PreparePayment` takes a `MarketplaceOrder` and **no amount**. The figure
sent to Stripe is `$order->grand_total_minor`, frozen at placement and
protected by a CHECK constraint; there is no parameter through which a
different one could arrive. Currency comes from the order too.

Metadata carries references only — order id, order number, attempt public
id, checkout attempt id. No customer name, email or address is sent.

An attempt that already has a provider payment is *asked about* rather
than replaced, so a customer refreshing the payment page lands back on the
same PaymentIntent instead of holding a second authorisation against their
card.

## 7. Payment idempotency strategy

Three independent guards, and none is relied on alone:

1. **A partial unique index** — `payment_attempts_one_open_per_order` over
   the open statuses. Two tabs pressing pay at once cannot both insert; the
   loser catches `23505` and joins the winner's attempt.
2. **A provider idempotency key** derived from the attempt
   (`attempt:{public_id}`), so a retried API call returns Stripe's existing
   PaymentIntent rather than creating a second.
3. **A unique index on `payment_attempts.provider_reference`**, so one
   provider payment maps to exactly one attempt.

Financial posting is idempotent by unique `source_key` on both ledgers
(`seller_ledger_entries.source_key`, `platform_revenue_entries.source_key`)
and by `payments.provider_charge_id` plus
`payment_transactions (provider, type, reference)`.

## 8. Webhook verification

`POST /webhooks/payments`, registered in `routes/webhooks.php` **outside
the `web` middleware group entirely** — no session, no CSRF token, because
a provider has neither. Only that route is excluded; no CSRF relaxation
was applied to any customer-facing payment route.

The controller reads `$request->getContent()` — the raw bytes, before any
parsing — and calls `parseEvent()`, which for Stripe is
`Webhook::constructEvent()` (HMAC over the raw body with a timestamp
tolerance, which is also the replay window) and for the fake is
`hash_equals` over an HMAC-SHA256. A bad signature returns **400 and stores
nothing**: constructing a `ProviderEvent` *is* the act of asserting the
event is genuine.

Verification is never disabled in production code for convenience.

## 9. Provider-event persistence

Verified events are written to `provider_webhook_events` with a unique
index on `(provider, event_id)`, plus `object_reference`, `status`
(received / processed / ignored / failed), `attempts`,
`signature_fingerprint`, `received_at`, `processed_at`, `failed_at` and a
bounded `error` column (class and message only — never a payload).

The ordering is §62's: **verify → persist → dispatch → 200**. A 200 is
never returned for something the platform has not looked at.

## 10. Webhook concurrency / replay protection

`ProcessProviderEvent` claims its row with a **conditional UPDATE** —
`whereIn('status', [received, failed])->update(['attempts' => attempts+1])`.
The `WHERE` is the lock: a second worker's claim matches nothing and it
returns without touching a financial row. The claim deliberately does not
rewrite `status`, so an event that has been failing stays visibly failed
between retries.

Underneath, every action is independently idempotent — the attempt refuses
to leave a terminal state, ledger source keys are unique, the reservation
commit only claims held rows. Belt and braces, because a claim that races
is still a claim that can race.

A duplicate delivery is answered **200** (already handled), never 4xx: a
provider must not be told its retry failed.

## 11. Out-of-order event strategy

`PaymentAttemptStatus` is a state machine with an explicit
`allowedTransitions()`. Terminal states — succeeded, failed, cancelled —
have none, so a `processing` notification arriving after the `succeeded`
one it preceded is **silently refused rather than argued with**.
`RecordAttemptTransition` returns `false` for an illegal move and writes
nothing; the event is still recorded as processed, because dropping a
stale event is the correct handling of it.

`payment_intent.processing`, `payment_intent.requires_action` and
`payment_intent.amount_capturable_updated` take the same re-read path as
success: the provider's current answer decides, not the event's name.

## 12. Client-success security boundary

Proven structurally, before any Stripe code was written.
`tests/Invariants/PaymentAuthorityTest` fails the build if:

- any file other than `App\Modules\Payments\Actions\FinalizePayment`
  mentions `MarkOrderPaid`;
- any registered HTTP route resolves to a controller that reaches the paid
  transition;
- `MarkOrderPaid::__invoke` accepts any parameter other than `$order` —
  there is nowhere for a caller-supplied amount to enter.

The browser's own endpoints reinforce it: `prepare` reads no amount,
`status` reads no claim (`?redirect_status=succeeded` changes nothing), and
the React panel treats Stripe's client-side result as sufficient only to
show a **form** error — the order's state comes from polling the server.

## 13. Successful payment transaction flow

`FinalizePayment` is the one path. It retrieves the provider's own record
(not the payload), matches the attempt by provider reference, then in one
transaction with a deterministic lock order (**attempt, then order** — the
same order the expiry sweep takes them in, so there is a winner rather than
a deadlock):

1. Verify — the provider says succeeded; the captured amount equals
   `grand_total_minor` exactly; the currency matches; the reference belongs
   to this attempt; the order is still `pending_payment`.
2. Transition the attempt to succeeded and record the method description.
3. Write the `Payment` (unique by provider charge id) and the
   `PaymentTransaction` (signed, unique by provider + type + reference).
4. `MarkOrderPaid` — the parent order, its seller orders, and the
   inventory commit, in the same transaction.
5. `RecordFinancialObligations` — seller earnings and platform commission
   from the item snapshots.
6. `DB::afterCommit` → dispatch `PaymentSucceeded`.

Any verification failure throws, rolls everything back, and is dispatched
as `PaymentExceptionRaised` **outside** the rolled-back transaction — an
operational exception nobody is told about is the same as no exception,
except that money has moved.

## 14. Inventory reservation commit result

Committed inside the payment transaction, exactly once. The commit claims
held rows, so a replayed event finds nothing left to claim. Verified:
three deliveries of one success produce one `-quantity` inventory movement
and leave every reservation `consumed`
(`PaymentConcurrencyTest::two_deliveries_of_one_success_cannot_finalize_it_twice`).

A hold is never released by a decline or a provider cancellation — §20's
rule — and stock is never returned by a refund (§28 below).

## 15. Parent-order state transition

`pending_payment → paid`, through `MarkOrderPaid` only, and only from
`FinalizePayment`. An order in any other state fails verification with
`order_not_open` rather than transitioning.

## 16. Seller-order state transition

Every seller order under the parent moves to `paid` in the same
transaction. A seller order is not actionable before that: the seller
detail screen carries `fulfilment.actionable = false` with the reason in
words.

## 17. Seller financial obligation recording

`RecordFinancialObligations` reads **only `order_items`** — the snapshot
taken at purchase. For each item it posts a `SaleEarning` ledger entry of
`seller_earning_amount_minor` with
`source_key = "sale:{item_id}"`, and a `PlatformRevenueEntry` of
`commission_amount_minor` with `source_key = "commission:{item_id}"` and
the `rate_percent_snapshot` copied across. There is no path from here to a
commission rule.

## 18. Seller earnings availability status

**Not withdrawable.** Entries are posted with
`LedgerEntryStatus::Pending` and `available_at` **null**.

`PostLedgerEntry` was changed so `available_at` is derived only when the
status is not `Pending`: at payment time the goods have not shipped, so
there is no clock to start, and writing one would let a seller withdraw
against an order that never left the warehouse. The clock starts at
delivery, in M6.

Asserted in `PaymentFinalizationTest`, in `RefundTest` (a reversal is
pending with no availability date either) and in the CI Docker smoke
(`earning status=pending available_at=null`).

## 19. Platform commission recording

One `platform_revenue_entries` row per item, from the snapshot, unique by
`source_key`. A commission rate changed between purchase and payment does
not alter the recording — asserted directly by
`PaymentFinalizationTest::a_commission_rate_change_before_payment_does_not_alter_the_recording`.

## 20. Financial reconciliation

The identity `earnings + commission = order total` holds per item, per
seller order and per marketplace order. Proven for a single seller, for
three sellers across six units with prices that do not divide cleanly
(`PaymentLifecycleTest::a_multi_seller_payment_reconciles_to_the_customers_total`),
and in the built image by the CI smoke (`exact=yes`).

Rounding is half-up on the platform's share with the seller taking the
remainder — the same rule the original split uses, computed once at
purchase and never recomputed.

## 21. Payment failure behaviour

`RecordPaymentFailure` closes the attempt and stores the provider's code
and message for operators. It **does not** cancel the order and **does
not** release the reservation: a declined card is a customer reaching for
a different card, not an abandoned purchase. The order stays
`pending_payment` with its hold intact until the existing M4 expiry sweep
closes it on the ordinary schedule — no new mechanism decides when an
order dies.

The customer reads the platform's own sentence, from `PaymentLanguage`.
The provider's decline code never reaches them (§33 below).

## 22. Payment retry behaviour

A terminal attempt is not reused. Preparing again creates a **new** attempt
and a new PaymentIntent, so the failed try survives as a row — three
declines and a success produce four attempts, one payment, one order, one
reservation and one ledger entry
(`PaymentLifecycleTest::one_order_is_paid_once_however_many_attempts_it_took`).

## 23. Expiry-vs-payment race result

Locks are taken in a fixed order — attempt, then order — by both the
payment path and the expiry sweep, so there is a deterministic winner
rather than a deadlock. Both directions are proven in
`PaymentConcurrencyTest`:

- **Expiry first** → the late payment fails verification with
  `order_not_open`, the event is marked failed with its reason, no
  `Payment` row and no ledger entry are written, and the reservations stay
  released.
- **Payment first** → the sweep arriving behind it leaves the order paid,
  the reservations consumed and the ledger intact.

## 24. Late-payment exception policy

Money arriving for a cancelled order does **not** silently revive it: the
stock went back on the shelf and may already be someone else's.
`PaymentVerificationFailed::orderNoLongerOpen` is raised, the transaction
rolls back, `PaymentExceptionRaised` is dispatched, the provider event is
marked `failed` with the reason, and it is left for a person — and, in the
real world, a refund. It is not retried into the ground: a disagreement
about money will not resolve by trying again, so it is marked failed once.

## 25. Refund architecture

A refund is a **set of allocations against order items**, and the amount is
their sum: "refund $50" is not a financial instruction until it says whose
$50, because one customer payment is several sellers' money.

`RequestRefund` requires a reason and at least one line, resolves the
allocations from the item snapshots, checks the refundable balance under
the payment's row lock, writes `refunds` + `refund_allocations`, and only
then calls the provider — **outside** the transaction, so a slow provider
day does not hold a payment row locked.

`FinalizeRefund` posts the reversals, and only when the provider says the
money left.

## 26. Partial refund allocation

Explicit per item. A full-line refund reverses exactly the commission and
earning that line recorded — taken from the snapshot, not recomputed, so
there is no rounding drift. A partial refund splits proportionally by the
same half-up rule, and a database CHECK
(`refund_allocations_split_is_exact`) enforces
`commission_reversed + earning_reversed = amount` on every row.

## 27. Commission reversal

A negative `platform_revenue_entries` row of type `commission_reversal`,
carrying `reverses_entry_id` and the original's `rate_percent_snapshot`,
unique by `source_key = "refund:{id}:commission:{item_id}"`. An order taken
at 12% that is refunded after the platform moved to 30% reverses twelve
percent — asserted directly.

## 28. Seller earning reversal

A negative `RefundReversal` ledger entry beside the original, never an
edit: `reverses_entry_id` points at the sale entry, the sale entry is
untouched, and the running balance nets to zero. Status `pending`,
`available_at` null. Unique by
`source_key = "refund:{id}:earning:{item_id}"`.

**Refund inventory policy:** a refund does **not** restock. Money coming
back is not goods coming back — the item may be in a courier's van, on a
customer's shelf or destroyed, and restocking on a financial event would
offer stock the seller does not have. Returns start from a physical event
and belong to M6. Asserted by
`AdminPaymentScreensTest::a_refund_does_not_put_the_stock_back_on_the_shelf`.

## 29. Admin payment UI

`/admin/payments` — captured and refunded as **separate columns** rather
than one net figure, because an order fully refunded and an order never
paid both net to nothing and only one of them is a problem. Filters by
order reference, status and capture date.

`/admin/payments/{reference}` — every attempt including the failed ones
(with the provider's code, for operators), the signed movements, the refund
history with reasons, and — behind `payments.events.view` — the provider
event trail as metadata only, never payload bodies.

The refund control is a dialog that names the items whose money is coming
back, caps each at what remains refundable, and requires a written reason.
It is built from the shared design system (`Modal`, `Field`, `Textarea`,
`Button`); no second admin design system was introduced. The reason and the
lines are validated **by the server**, not by the form.

## 30. Customer payment UI

`/checkout/{reference}/payment` mounts Stripe Elements from a client secret
the page asks for when the customer is ready — never baked into HTML a back
button or a screenshot could hold. `confirmPayment` runs with
`redirect: 'if_required'`; whatever it returns, the page then **polls the
server** until a verified provider event has decided the outcome, and gives
up after two minutes with a plain message rather than spinning.

States rendered from the server's own words: awaiting payment, processing,
requires action, failed (retryable), paid, cancelled, expired. Refunded and
partially-refunded statuses render through the generated central status
presentation (`StatusBadge` + `design-system/generated/statuses.ts`); no
second colour or status map was introduced.

`/account/orders/{reference}` shows the same payment state from the same
source, so the two pages cannot disagree.

## 31. Seller payment visibility

Two facts and no more: **that** the money cleared and **when**
(`DescribePaymentState::forSeller`). No provider reference, no card
description, no customer email. Earnings are shown as pending with the
wording "become available for payout after delivery and the clearing
period" — never as an available balance.

## 32. RBAC

| Permission               | Opens                                        |
| ------------------------ | -------------------------------------------- |
| `payments.view`          | the payment list and detail                  |
| `payments.view_sensitive`| the provider's own record                    |
| `payments.events.view`   | the provider event trail                     |
| `orders.refund`          | issuing a refund                             |

Finance Admin holds all four. Marketplace Admin holds `payments.view` and
`payments.events.view` but **cannot refund**. Support holds `payments.view`
only. Analyst and Catalog Moderator hold none. Sellers and customers reach
none of it.

Enforced as route middleware (`admin.can:…`) and, for the event trail and
the refundable-items payload, by **not building the data at all** for a
role that may not see it — not by hiding it in CSS.

## 33. Security controls

- No card number, CVC, expiry or raw payment-method payload is stored or
  logged; card data never touches the application (Elements posts to
  Stripe).
- Client secrets leave only in the `prepare` response, to the order's own
  customer, and are never logged or written into page props.
- The Stripe secret key and webhook signing secret never appear in any
  response — asserted across four pages in `PaymentSecurityTest`.
- `.env.example` carries placeholders only (`sk_test_replace_me`,
  `whsec_replace_me`); no key is hardcoded anywhere.
- Provider error columns store class and message, bounded to 500
  characters — never a payload.
- The provider's decline codes are kept for operators and never rendered to
  a customer; `PaymentLanguage` writes what the customer reads.
- CSP gained `frame-src 'self' https://js.stripe.com https://hooks.stripe.com`.
  That is a **tightening** — there was no frame directive at all — and the
  two origins are named rather than wildcarded. No `unsafe-*` was added.
  `script-src` stays deliberately absent until there is a nonce pipeline to
  write it against.
- CSRF is excluded for the webhook route only, by registering it outside
  the `web` group. No payment route was broadly exempted.
- Rate limits: `throttle:20,1` on prepare, `throttle:120,1` on status,
  `throttle:30,1` on admin refunds, `throttle:600,1` on the webhook — high
  enough that a provider's retry burst is never throttled away.
- Ownership failures are **404, not 403**, throughout: telling a stranger
  an order exists but is not theirs confirms the reference is real.

## 34. Audit events

`payment.refunded` — actor, refund id, order reference, refund reference,
amount, currency, status, and the reason verbatim. It is the one action in
the application that takes money out of the platform's account, so it is
the one that is audited with its justification. No provider identifiers go
into the log; `RecordAuditEvent` redacts on top of that.

## 35. Analytics events

- `purchase_completed` — **once per seller**, with that seller's own value,
  because "how much did this seller sell" is the question the table exists
  to answer and one row carrying the customer's total answers it for
  nobody.
- `payment_failed` — with the provider's code, in the analytics stream
  only, so a decline rate can be told apart from a pricing problem.

Attribution comes from the order rather than the session: a payment is
decided by a webhook in a queued job where there is no session, so
`RecordInteraction` now accepts an explicit customer id for that case.

## 36. Exact migrations and constraints

One migration: `2026_06_01_000100_build_payment_lifecycle.php`.

**Tables created** — `payment_attempt_events`, `payment_transactions`,
`refund_allocations`, `platform_revenue_entries`; `refunds` was dropped and
rebuilt as a request record.

**Columns added** — `payment_attempts`: `provider_status`, `succeeded_at`,
`failed_at`, `cancelled_at`, `event_sequence`. `provider_webhook_events`:
`object_reference`, `status`, `attempts`, `signature_fingerprint`,
`failed_at`. `seller_ledger_entries`: `source_key`.

**Unique indexes** — `provider_webhook_events (provider, event_id)`;
`payment_attempts.provider_reference`; `payment_attempts.idempotency_key`;
`payment_attempts_one_open_per_order` (partial, over the open statuses);
`payments.provider_charge_id`; `payment_transactions (provider, type,
reference)`; `refunds.reference`; `refunds.idempotency_key`;
`refunds.provider_refund_reference`; `refund_allocations (refund_id,
order_item_id)`; `seller_ledger_entries.source_key`;
`platform_revenue_entries.source_key`.

**CHECK constraints** — `payment_attempts_amount_is_positive`,
`refunds_amount_is_positive`, `refund_allocations_amounts_are_not_negative`,
`refund_allocations_split_is_exact`.

Total refunds against a payment cannot be enforced by a simple CHECK, as
the brief notes; that one is held by a locked read of the payment plus the
sum of balance-holding refunds inside `RequestRefund`, and proven by
`PaymentConcurrencyTest::two_refunds_racing_for_the_same_balance_cannot_exceed_it`.

## 37. Exact automated test count

**929 tests, 11,902 assertions — all passing.**

Payment-specific coverage, 121 tests:

| Suite                                    | Tests |
| ---------------------------------------- | ----: |
| `PaymentFinalizationTest`                |    20 |
| `PaymentSecurityTest`                    |    13 |
| `PaymentLifecycleTest`                   |    13 |
| `RefundTest`                             |    12 |
| `PaymentEndpointTest`                    |    11 |
| `StripeAdapterTest`                      |     9 |
| `AdminPaymentScreensTest`                |     8 |
| `PaymentNotificationTest`                |     8 |
| `PaymentConcurrencyTest`                 |     7 |
| `WebhookIdempotencyTest` (invariant)     |     7 |
| `PaymentVisibilityTest`                  |     6 |
| `PaymentQueueRuntimeTest`                |     4 |
| `PaymentAuthorityTest` (invariant)       |     3 |

## 38. M0–M4 regression status

**Preserved.** The M4 baseline of 815 passing tests is intact; M5 adds 114
net new, for 929. Nothing was deleted, skipped or weakened.

Two existing assertions were *adapted* rather than relaxed, both because
the thing they describe legitimately changed:

- `CustomerOrderScreensTest::the_payment_pending_page_is_honest_about_what_has_happened`
  now asserts the M5 payment props (`payment.state`, `payment.isPaid`,
  `payment.canPay`) instead of the M4 `paymentStatus` shape. The assertion
  is strictly stronger — it checks three facts where it previously checked
  two strings.
- `AreaShellsTest` and the CI header check now expect the CSP including the
  Stripe `frame-src`. That directive is a tightening, not a relaxation.

## 39. PHPStan / Larastan result

**Level 8, zero errors**, across `app`, `config`, `database`, `routes` and
`tests`, with Larastan loaded (`tools/phpstan.php` reports
`Larastan: yes`). No baseline entries, no `@phpstan-ignore` comments and no
casts were added to silence anything.

## 40. Frontend gates

| Gate                       | Result                                    |
| -------------------------- | ----------------------------------------- |
| `tsc --noEmit` (strict)    | pass                                      |
| ESLint (`--max-warnings=0`)| pass                                      |
| Prettier `--check`         | pass                                      |
| Client production build    | pass — `PaymentPending` chunk 19.9 kB     |
| SSR production build       | pass — `bootstrap/ssr/ssr.js` 127.7 kB    |

`@stripe/stripe-js` and `@stripe/react-stripe-js` were added; both are
loaded lazily and neither runs during SSR.

## 41. Real Redis / Horizon payment queue result

**Verified.** `php artisan queues:smoke --timeout=60` against the live
Redis drained every queue, `payments` first:

```
ok payments   ok critical   ok emails   ok catalogue
ok default    ok search     ok media
Every queue was drained.
```

`PaymentQueueRuntimeTest` additionally runs a real worker against real
Redis: a webhook enqueues its work rather than doing it (the event is
verified and persisted inside the request, the order is still pending, the
job is on `payments` and no other queue), the same job run twice captures
once, and a provider outage leaves the event `failed` with its reason,
still queued, with nothing partial written — going through cleanly once the
provider returns.

Horizon has a dedicated `payments` supervisor: `tries` 8, backoff
`[5, 15, 60, 300, 900]`, 6 processes in production, and it shares its pool
with nothing. A test asserts no other supervisor drains the payments queue.

## 42. HTTP / SSR smoke

Run locally over HTTP against the real application:

- payment page for an unpaid order — "Pay for this order", "Nothing has
  been charged yet", no client secret in the HTML;
- paid order — "Payment received. Your order is confirmed.";
- status endpoint with `?redirect_status=failed` on a paid order — returns
  `"isPaid": true` from the database, ignoring the URL;
- no `sk_`, `whsec_` or `fake_pi_` in any rendered page.

The same assertions plus SSR-rendered headings run in the CI Docker job.

## 43. Invalid-signature and replay smoke

- Forged signature over HTTP → **400**, nothing stored.
- Unsigned body over HTTP → **400**, nothing stored.
- Correct HMAC of the *wrong secret* → refused.
- Body altered after signing (the amount changed) → refused.
- A correctly signed event replayed four times → one stored event, one
  payment, one ledger entry, one confirmation email.
- A Stripe signature older than the tolerance window → refused.

## 44. Stripe real test-mode network smoke status

**UNVERIFIED — not run, and not simulated.**

No Stripe test-mode credentials are available in this environment and
outbound access to `api.stripe.com` is not permitted by the sandbox's
network policy. Per the brief, this is reported as unverified rather than
faked.

What *is* verified without the network: the adapter's status translation in
both directions, its signature verification against real Stripe-format
signatures (including wrong-secret, tampered-body and stale-timestamp
cases), its error mapping, and the full lifecycle against the fake driver
through the real HTTP, queue and database paths.

To run it: set `PAYMENT_PROVIDER=stripe`, `STRIPE_KEY`, `STRIPE_SECRET` and
`STRIPE_WEBHOOK_SECRET` to real test-mode values, then drive a payment
through `/checkout/{reference}/payment` with `stripe listen --forward-to
localhost:8000/webhooks/payments`.

## 45. Docker local status

**UNVERIFIED — no Docker daemon in this environment** (`docker info` fails).
Nothing about local Docker is claimed. `docker-compose.yml` and the
`Dockerfile` are unchanged by M5 apart from what the CI job exercises.

## 46. Docker CI status

The workflow's Docker job is extended with three payment steps, which will
run on push:

1. **A payment is verified, recorded and reversed in the built image** —
   `.github/ci/m5-payment-smoke.php` against the container's PostgreSQL and
   Redis, each webhook delivery drained with a real `queue:work`. Its
   output was validated locally against the same script, real Postgres and
   real Redis:
   ```
   prepared amount=9000 order_total=9000 currency=USD
   idempotent attempts=1 same=yes
   forged status=400 stored=0
   declined order_status=pending_payment attempt_status=failed
   paid status=200 order=paid payments=1 transactions=1
   seller_orders=paid
   reconciled total=9000 earnings=7920 commission=1080 exact=yes
   earning status=pending available_at=null
   refund status=succeeded reversals=1 earning_reversed=-7920 commission_reversed=-1080
   net earnings=0 commission=0 payment_refunded=9000
   ```
2. **The payment surfaces answer over HTTP and give nothing away** — paid
   page wording, the status endpoint refusing the URL's claim, forged and
   unsigned webhooks getting 400 and being stored nowhere, and noindex on
   every transactional page.
3. **The admin payment screens require the permission that opens them** —
   signed-out and customer sessions turned away from the list, the detail
   and the refund route.

The M4 basket smoke's page assertions and the security-header check were
updated for the payment page's new wording and the Stripe `frame-src`.

## 47. Bugs discovered during M5, and their fixes

1. **`lockForUpdate()->sum()` cannot run on PostgreSQL.** The seller-side
   refund reversal built its running balance with an aggregate under `FOR
   UPDATE`, which PostgreSQL rejects outright (`SQLSTATE[0A000]`) — no
   refund could ever have posted one. Fixed by routing the reversal through
   `PostLedgerEntry`, which locks the seller's *last row* rather than an
   aggregate and carries the exactly-once source key. The same mistake had
   appeared in the earnings path and was fixed there first.
2. **A verification exception was being discarded with its own
   transaction.** `PaymentExceptionRaised` was dispatched inside the
   transaction that the failure rolled back, so nobody was ever told. Moved
   outside.
3. **Progress events were recorded and ignored.**
   `payment_intent.processing` and `payment_intent.requires_action` matched
   no handler, so a customer mid-3DS saw a "pay now" button for a payment
   already in flight. They now take the same re-read path as success.
4. **A retry cleared the record of a failure.** The job claimed an event by
   resetting its status to `received`, so an event that had been failing for
   an hour read as merely "received" whenever an operator looked. The claim
   now moves only the attempt count.
5. **A lazy-load violation silenced the seller notifications.** The paid-
   order listener reached `$sellerOrder->sellerAccount` on a collection,
   which threw inside a post-commit callback and was swallowed by the HTTP
   handler — customers got their receipt and sellers got nothing. Fixed by
   eager loading, and caught only because the multi-seller test asserted the
   count rather than the happy path.

## 48. Intentional deviations

1. **M0's `PaymentGateway` scaffolding was removed rather than kept
   alongside `PaymentProvider`.** Two competing ports for one concern is
   worse than one migration. `WebhookIdempotencyTest` was migrated to the
   new port and kept.
2. **The Stripe SDK was vendored by clone rather than by Composer
   download**, because this sandbox cannot reach `api.github.com`. Declared
   and locked normally; CI and Docker install it normally. Reported in §4
   rather than hidden.
3. **A provider cancellation does not release the reservation.** §42 lists
   "release inventory reservation"; §20 says a payment failure must leave
   the order retryable with its stock held. Treating a provider-side
   cancellation as terminal for the *order* would destroy a purchase the
   customer had not abandoned — they can still pay with another method. The
   attempt is cancelled; the order and its hold are left to the existing
   expiry sweep, which is the one mechanism that decides when an order dies.
   Stated here because it is a deliberate reading of two rules that pull in
   opposite directions.
4. **`script-src` is still absent from the CSP.** Adding one loose enough to
   pass Vite would be a directive that permits what it claims to stop. The
   Stripe integration is served by a narrowly named `frame-src` instead.
5. **The CI payment smoke drives the provider in-process** rather than
   entirely over HTTP, because the fake provider holds payments in memory
   and one php-fpm worker's are not another's. The HTTP surface is smoked
   separately, over the wire. Both halves are described in §46 rather than
   presented as one end-to-end browser flow.

## 49. Remaining blockers before M6

1. **Stripe test-mode network verification** (§44) — the one gate that
   cannot be closed here. It should be run before any environment takes
   real cards.
2. **The clearing clock.** Earnings sit `pending` with no `available_at` by
   design. M6 must start that clock at delivery and define the clearing
   period's effect on a partially refunded order.
3. **Returns and restocking.** The refund path deliberately does not touch
   inventory. M6 needs a physical return event before a refund can restock
   anything.
4. **Shipping and tax are zero** in this phase, asserted explicitly rather
   than assumed. When the business defines them, the refund allocator needs
   a stated rule for reversing them — it must not guess one.
5. **Payouts remain out of scope.** No Stripe Connect account, transfer or
   bank payout exists, and `available_at` is null everywhere, so nothing is
   withdrawable by construction.
6. **Local Docker** (§45) could not be exercised here; the CI job is the
   only Docker evidence.

---

# Traceability

## §78 — the seventy-eight required behaviours

| #  | Behaviour                                  | Test                                                                          |
| -- | ------------------------------------------ | ----------------------------------------------------------------------------- |
| 1  | owner can prepare payment                  | `PaymentEndpointTest::preparing_returns_a_client_secret_for_the_orders_own_total` |
| 2  | foreign customer denied                    | `PaymentEndpointTest::neither_endpoint_is_reachable_for_someone_elses_order`  |
| 3  | cancelled/expired order cannot prepare     | `PaymentEndpointTest::a_cancelled_order_cannot_be_prepared`                   |
| 4  | paid order does not create new payment     | `PaymentEndpointTest::a_paid_order_reports_paid_and_cannot_be_prepared_again` |
| 5  | order amount sent to provider exactly      | `PaymentFinalizationTest::the_provider_is_told_the_orders_amount_and_currency_exactly` |
| 6  | order currency sent exactly                | `PaymentFinalizationTest::the_provider_is_told_the_orders_amount_and_currency_exactly` |
| 7  | submitted fake amount ignored              | `PaymentEndpointTest::a_client_supplied_amount_is_ignored_entirely`           |
| 8  | preparation idempotent                     | `PaymentEndpointTest::preparing_twice_re_joins_the_same_payment`              |
| 9  | provider failure leaves order safe         | `PaymentEndpointTest::a_provider_outage_leaves_the_order_payable`             |
| 10 | provider DTO translation correct           | `StripeAdapterTest::stripe_statuses_translate_to_the_platforms_own_vocabulary` |
| 11 | internal states do not leak Stripe strings | `StripeAdapterTest::no_stripe_status_string_reaches_the_platforms_own_enum`   |
| 12 | webhook valid signature accepted           | `StripeAdapterTest::a_correctly_signed_stripe_event_is_parsed`                |
| 13 | invalid signature rejected                 | `PaymentSecurityTest::a_forged_signature_is_refused_and_nothing_is_stored`    |
| 14 | duplicate provider event stored once       | `WebhookIdempotencyTest::the_same_event_is_recorded_once`                     |
| 15 | unknown payment reference handled safely   | `PaymentFinalizationTest::a_reference_the_platform_never_prepared_is_handled_safely` |
| 16 | amount mismatch blocks finalization        | `PaymentFinalizationTest::an_amount_that_does_not_match_the_order_blocks_finalization` |
| 17 | currency mismatch blocks finalization      | `PaymentFinalizationTest::a_currency_that_does_not_match_blocks_finalization` |
| 18 | verified success marks attempt succeeded   | `PaymentFinalizationTest::a_verified_success_marks_the_attempt_and_the_order_paid` |
| 19 | parent order becomes paid                  | `PaymentFinalizationTest::a_verified_success_marks_the_attempt_and_the_order_paid` |
| 20 | seller orders become paid                  | `PaymentFinalizationTest::a_verified_success_marks_the_attempt_and_the_order_paid` |
| 21 | inventory reservations committed once      | `PaymentFinalizationTest::payment_commits_the_reservations_exactly_once`      |
| 22 | payment transaction written once           | `PaymentFinalizationTest::a_payment_transaction_is_written_once`              |
| 23 | platform commission recorded once          | `PaymentFinalizationTest::platform_commission_is_recorded_from_the_snapshot`  |
| 24 | seller pending earnings recorded once      | `PaymentFinalizationTest::seller_earnings_are_recorded_from_the_snapshot_and_are_not_available` |
| 25 | seller earning NOT available               | `PaymentFinalizationTest::seller_earnings_are_recorded_from_the_snapshot_and_are_not_available` |
| 26 | customer confirmation queued once          | `PaymentNotificationTest::the_customer_is_confirmed_once_when_the_payment_verifies` |
| 27 | each seller notified once                  | `PaymentNotificationTest::each_seller_is_told_about_their_own_order_and_no_one_elses` |
| 28 | browser callback alone cannot mark paid    | `PaymentAuthorityTest` (all three) + `PaymentSecurityTest::a_customer_cannot_mark_their_own_order_paid_by_any_route` |
| 29 | same webhook twice identical result        | `PaymentFinalizationTest::the_same_event_delivered_twice_changes_nothing_the_second_time` |
| 30 | same webhook ten times identical result    | `PaymentFinalizationTest::the_same_event_delivered_ten_times_changes_nothing` |
| 31 | two concurrent workers cannot double-finalize | `PaymentConcurrencyTest::a_second_worker_claiming_the_same_event_touches_no_financial_row` |
| 32 | stale event cannot regress success         | `PaymentFinalizationTest::a_stale_processing_event_cannot_un_pay_an_order`    |
| 33 | failed payment recorded                    | `PaymentEndpointTest::a_declined_payment_leaves_the_order_payable_and_a_retry_starts_a_fresh_attempt` |
| 34 | recoverable failure keeps order retryable  | `PaymentEndpointTest::a_declined_payment_leaves_the_order_payable_and_a_retry_starts_a_fresh_attempt` |
| 35 | terminal cancellation handled              | `PaymentLifecycleTest::a_cancellation_before_capture_records_no_money_and_returns_the_stock` |
| 36 | payment retry does not duplicate order     | `PaymentConcurrencyTest::a_retry_after_a_decline_pays_the_same_order_and_never_a_second_one` |
| 37 | payment retry does not duplicate reservations | `PaymentConcurrencyTest::a_retry_after_a_decline_pays_the_same_order_and_never_a_second_one` |
| 38 | expiration wins safely when first          | `PaymentConcurrencyTest::expiry_winning_the_race_leaves_a_late_payment_as_an_exception` |
| 39 | payment success wins safely when first     | `PaymentConcurrencyTest::payment_winning_the_race_survives_the_expiry_sweep_behind_it` |
| 40 | late success after cancellation is an exception | `PaymentFinalizationTest::payment_after_the_order_was_cancelled_becomes_an_exception` |
| 41 | payment amount equals marketplace total    | `PaymentFinalizationTest::the_recorded_obligations_reconcile_with_the_order`  |
| 42 | commission uses snapshot, not current rule | `PaymentFinalizationTest::a_commission_rate_change_before_payment_does_not_alter_the_recording` |
| 43 | seller earning uses snapshot               | `PaymentFinalizationTest::seller_earnings_are_recorded_from_the_snapshot_and_are_not_available` |
| 44 | financial reconciliation exact             | `PaymentLifecycleTest::a_multi_seller_payment_reconciles_to_the_customers_total` |
| 45 | rounding remains deterministic             | `PaymentLifecycleTest::commission_rounding_is_deterministic_and_the_seller_takes_the_remainder` |
| 46 | multi-seller allocations reconcile         | `PaymentLifecycleTest::a_multi_seller_payment_reconciles_to_the_customers_total` |
| 47 | permitted admin can request full refund    | `RefundTest::the_admin_refund_route_requires_the_permission_and_a_reason`     |
| 48 | unauthorized admin cannot                  | `PaymentSecurityTest::an_admin_without_the_refund_permission_cannot_refund`   |
| 49 | refund requires reason                     | `RefundTest::a_refund_needs_a_reason_and_at_least_one_line`                   |
| 50 | refund cannot exceed refundable balance    | `RefundTest::a_refund_cannot_exceed_what_was_captured`                        |
| 51 | full refund recorded immutably             | `RefundTest::the_original_ledger_entry_is_untouched_and_a_reversal_is_appended` |
| 52 | partial refund recorded immutably          | `RefundTest::a_partial_refund_splits_proportionally_and_leaves_the_rest_refundable` |
| 53 | partial refund allocated to seller/item    | `RefundTest::a_partial_refund_splits_proportionally_and_leaves_the_rest_refundable` |
| 54 | commission change does not alter reversal  | `RefundTest::the_commission_reversal_uses_the_rate_that_was_charged`          |
| 55 | seller pending earning reversed correctly  | `RefundTest::the_original_ledger_entry_is_untouched_and_a_reversal_is_appended` |
| 56 | platform commission reversed correctly     | `RefundTest::the_commission_reversal_uses_the_rate_that_was_charged`          |
| 57 | duplicate refund event harmless            | `RefundTest::a_replayed_refund_event_posts_one_reversal`                      |
| 58 | concurrent refunds cannot over-refund      | `PaymentConcurrencyTest::two_refunds_racing_for_the_same_balance_cannot_exceed_it` |
| 59 | provider refund failure does not complete  | `RefundTest::a_provider_that_refuses_the_refund_reverses_nothing`             |
| 60 | pre-fulfilment refund restores stock per policy | `AdminPaymentScreensTest::a_refund_does_not_put_the_stock_back_on_the_shelf` — the policy is "never", stated in §28 |
| 61 | shipped-item refund does not blindly restock | same test; the policy makes no distinction, deliberately              |
| 62 | multi-seller refund touches only the right allocation | `RefundTest::each_seller_only_has_their_own_share_reversed`     |
| 63 | customer cannot view another payment       | `PaymentSecurityTest::a_customer_cannot_pay_for_or_read_another_customers_order` |
| 64 | seller cannot view other payment internals | `PaymentSecurityTest::a_seller_cannot_reach_the_customers_payment_or_another_sellers` |
| 65 | Finance Admin access works                 | `AdminPaymentScreensTest::the_provider_event_trail_opens_for_the_roles_that_reconcile_money` |
| 66 | Support restricted per the model           | `PaymentSecurityTest::support_is_kept_out_of_the_provider_event_trail`        |
| 67 | Analyst cannot refund                      | `PaymentSecurityTest::an_admin_without_the_refund_permission_cannot_refund`   |
| 68 | credentials never serialized to Inertia    | `PaymentSecurityTest::no_page_ever_serializes_a_provider_credential`          |
| 69 | payment page uses server amount            | `PaymentEndpointTest::a_client_supplied_amount_is_ignored_entirely`           |
| 70 | processing state displayed                 | `PaymentLifecycleTest::the_customer_sees_every_state_the_order_actually_reaches` |
| 71 | verified paid state displayed              | `PaymentLifecycleTest::the_customer_sees_every_state_the_order_actually_reaches` |
| 72 | failure state displayed safely             | `PaymentLifecycleTest::the_payment_page_shows_a_failure_without_showing_the_provider` |
| 73 | refunded / partially-refunded displayed    | `PaymentLifecycleTest::the_admin_screen_shows_the_refunded_and_partially_refunded_states` |
| 74 | transactional pages remain noindex         | `PaymentSecurityTest::every_transactional_payment_page_stays_out_of_search`   |
| 75 | payments queue drains                      | `PaymentQueueRuntimeTest::a_webhook_enqueues_its_work_and_a_worker_finishes_it` + `queues:smoke` |
| 76 | failed processing visible / retryable      | `PaymentQueueRuntimeTest::an_event_the_platform_cannot_finish_stays_failed_and_retryable` |
| 77 | payment job retry idempotent               | `PaymentQueueRuntimeTest::running_the_same_queued_job_again_changes_nothing`  |
| 78 | notifications not duplicated               | `PaymentNotificationTest::a_redelivered_event_does_not_send_a_second_receipt` |

## §76 — concurrency

| # | Proof                                          | Test                                                                       |
| - | ---------------------------------------------- | -------------------------------------------------------------------------- |
| 1 | duplicate successful webhook cannot double-finalize | `PaymentConcurrencyTest::two_deliveries_of_one_success_cannot_finalize_it_twice` |
| 2 | two workers cannot double-post                 | `PaymentConcurrencyTest::a_second_worker_claiming_the_same_event_touches_no_financial_row` |
| 3 | payment vs expiration race resolves safely     | `PaymentConcurrencyTest::expiry_winning_…` and `payment_winning_…`         |
| 4 | concurrent refunds cannot exceed refundable    | `PaymentConcurrencyTest::two_refunds_racing_for_the_same_balance_cannot_exceed_it` |
| 5 | duplicate refund webhook cannot double-reverse | `PaymentConcurrencyTest::a_refund_event_delivered_repeatedly_reverses_once` |
| 6 | payment retry cannot duplicate order           | `PaymentConcurrencyTest::a_retry_after_a_decline_pays_the_same_order_and_never_a_second_one` |

All six run under `DatabaseTruncation` with committed transactions, and
three of them read or write through a second database connection so the
guard being exercised is the database's rather than the application's.

## §77 — security

| Proof                                           | Test                                                                     |
| ----------------------------------------------- | ------------------------------------------------------------------------ |
| fake webhook signature rejected                 | `PaymentSecurityTest::a_forged_signature_is_refused_and_nothing_is_stored` |
| customer cannot mark order paid                 | `PaymentSecurityTest::a_customer_cannot_mark_their_own_order_paid_by_any_route` |
| customer cannot supply payment amount           | `PaymentSecurityTest::a_customer_cannot_choose_what_they_are_charged`     |
| customer cannot pay another customer's order    | `PaymentSecurityTest::a_customer_cannot_pay_for_or_read_another_customers_order` |
| seller cannot access customer payment details   | `PaymentSecurityTest::a_seller_cannot_reach_the_customers_payment_or_another_sellers` |
| seller cannot trigger refund                    | `PaymentSecurityTest::a_seller_cannot_trigger_a_refund`                   |
| unauthorized admin cannot refund                | `PaymentSecurityTest::an_admin_without_the_refund_permission_cannot_refund` |
| Support cannot access sensitive provider data   | `PaymentSecurityTest::support_is_kept_out_of_the_provider_event_trail`    |
| replayed webhook harmless                       | `PaymentSecurityTest::a_replayed_verified_event_is_harmless`              |
| provider reference mismatch rejected            | `PaymentSecurityTest::a_provider_reference_that_belongs_to_another_order_is_rejected` |

---

## Gate summary

| Gate                              | Result                                  |
| --------------------------------- | --------------------------------------- |
| `migrate:fresh --seed`            | pass                                    |
| PHPUnit (full)                    | pass — 929 tests, 11,902 assertions     |
| Invariants suite                  | pass — 77 tests, 1,089 assertions       |
| PHPStan + Larastan level 8        | pass — 0 errors                         |
| Pint                              | pass                                    |
| `tsc --noEmit`                    | pass                                    |
| ESLint                            | pass                                    |
| Prettier                          | pass                                    |
| Client build                      | pass                                    |
| SSR build                         | pass                                    |
| Real Redis / Horizon payments queue | pass                                  |
| HTTP payment surfaces             | pass                                    |
| Invalid-signature / replay        | pass                                    |
| Docker CI payment smokes          | added; run on push                      |
| Local Docker                      | **unverified — no daemon**              |
| Stripe test-mode network          | **unverified — no credentials, no egress** |

M5 is complete for every gate that can be run in this environment. The two
that cannot are named as unverified above rather than reported as passing.

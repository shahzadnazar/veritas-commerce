# M7 completion report — seller finance, payouts and financial reporting

Answers the sixty questions in the M7 brief, in order. Where a gate could
not be run in this environment it says so rather than claiming a result.

---

## 1. Branch and final SHA

`claude/veritas-marketplace-architecture-vov8c0`.

The last code commit is `17c659e`; the branch head is the commit carrying
this report, which is its immediate child. Nothing was force-pushed and no
published history was rewritten.

## 2. M7 commit range

`6795763..HEAD` — three code commits plus this report.

| SHA       | Subject                                                     |
| --------- | ----------------------------------------------------------- |
| `8473497` | feat: implement seller finance, payout reservation and settlement |
| `467898f` | feat: build the seller and admin finance surfaces            |
| `17c659e` | test: add the M7 finance, payout and reconciliation suite     |

109 files changed, 12,462 insertions, 339 deletions.

The brief suggested twelve commits. Three were used instead, and that is a
deviation worth naming: the payout domain does not divide into
independently correct halves. A commit that added allocations without the
settlement that closes them, or the request action without the eligibility
service it consults, would have been a commit where the money did not add
up — and the whole point of this milestone is that it always does. Each of
the three is internally complete and its own tests pass at that commit.

## 3. Package/version changes

**None.** No dependency was added, removed or upgraded. `composer.json`,
`composer.lock`, `package.json` and `package-lock.json` are byte-identical
to M6. Everything below is written against the stack that was already
there.

## 4. M0–M6 regression result

**Preserved.** 1,007 tests / 12,591 assertions at the M6 baseline; 1,121
tests / 13,148 assertions now, all passing.

Three existing tests were **adapted**, none weakened:

1. `PayoutBalanceTest` (Invariants) — M0's `GetSellerBalance` and its
   three-figure DTO are gone, replaced by `GetSellerFinancialPosition`.
   The eleven assertions are the same facts asked of the authoritative
   projection: clearing money is not withdrawable, a request holds its
   amount, a second open request is refused, the database refuses it too,
   the minimum bites, a suspended store cannot ask. Two assertions became
   **stricter**: where the old test checked `held` and `available`, the new
   one also checks that the available ledger value is unchanged by a
   reservation — the §4 distinction the old shape could not express.

2. `SellerDashboardTest::the_three_money_states_are_kept_apart` — asserted
   `earnings.payoutsAvailable === false`. That was true in M6 and would be
   a lie now. It asserts the replacement: `withdrawableMinor === 0`,
   `eligibility.canRequest === false`, and the reason
   `no_available_balance`. Strictly more than before.

3. `StateMachineTest::every_non_initial_state_is_reachable_from_somewhere`
   — see §58 below. The invariant was made more precise rather than
   relaxed, and a new state-machine invariant was added beside it.

`ModuleBoundaryTest` gained one allowance: `Payouts => Identity`, the same
coupling `Orders` and `Payments` already have, because a payout belongs to
a person as well as to a store.

## 5. Financial bucket definitions

Stated once, on `Payouts\Data\SellerFinancialPosition`, and used with these
meanings everywhere in the code and on every screen.

| Bucket | Meaning |
| --- | --- |
| **PENDING** | Payment verified, delivery requirement not yet met. The money exists as an obligation; nothing has started clearing. |
| **CLEARING** | The seller order was delivered and the clearing period is running. Earned, not yet spendable. |
| **AVAILABLE** | Money that has finished clearing, **net of payouts already settled**. |
| **RESERVED_FOR_PAYOUT** | Available money an open payout request is holding. From `payout_allocations`, never from the ledger. |
| **PAID OUT** | Lifetime total actually settled to the seller, as a positive figure. Reporting only — it is already inside AVAILABLE as a negative. |
| **NET BALANCE** | pending + clearing + available. What the platform owes this seller. |
| **WITHDRAWABLE** | What the seller may ask for right now. See §6. |

AVAILABLE is the subtle one and the brief's §3 warns about exactly it. An
earning of $500 that has been paid out leaves **$0** available, not $500,
because the payout debit sits in the same pool. Summing only entries whose
status is `available` would show sellers money they have already been sent
— and that is the number a payout is measured against.

Every figure is a **signed** integer count of minor units in one currency.
Signed, because a seller's position can genuinely be below zero once a
refund lands behind a payout that already left (§29 below), and a type that
refused to represent that would round the one number that matters up to
nothing.

## 6. Withdrawable-balance formula

```
withdrawable = min(available, net_balance) − reserved
```

Implemented once, in `SellerFinancialPosition::withdrawableMinor()`.

The **cap** is the §48 requirement. A seller whose overall position is
negative may not withdraw even when an individual available earning row
exists — a refund sitting against money that is still clearing is money the
platform is about to be owed back, and paying out against it turns a
bookkeeping entry into a debt-collection problem. In the ordinary case,
where pending and clearing are positive, the cap does nothing and this is
plainly `available − reserved`.

`reserved` is subtracted **exactly once, here**. It is deliberately not
also a ledger entry: that double subtraction at settlement is the specific
bug §29 exists to prevent.

The projection reads two grouped queries — one over `seller_ledger_entries`,
one over `payout_allocations` — and nothing else. It never touches orders,
seller orders, payments, current offer prices or the current commission
rate (§1).

## 7. Payout reservation architecture

**A payout hold is not a ledger entry.**

M0 posted a negative `payout_reservation` row to hold the money, which
reads naturally right up until settlement, when the real payout debit is
posted beside it and the seller's balance falls by the amount twice. A hold
and a payment are different facts: one says "this money is spoken for", the
other says "this money is gone".

So the hold lives in `payout_allocations`, and `LedgerEntryStatus` lost
`reserved_for_payout` and `LedgerEntryType` lost `payout_reservation`
entirely. A status column on a whole row could not express the common case
anyway — a hold is often a claim on *part* of one earning.

The ledger therefore has exactly one row per settlement and no rows at all
for requests that were never paid.

## 8. Payout allocation architecture

`payout_allocations` — one row per (payout request, ledger entry) pair:

```
payout_request_id, seller_ledger_entry_id, seller_account_id,
currency, amount_minor (> 0, CHECK), status, created_at,
settled_at, released_at
```

Three statuses, and only the first reserves money:

- **HELD** — a live reservation, out of withdrawable.
- **SETTLED** — the payout was paid; the hold ended because the ledger debit
  took its place.
- **RELEASED** — the request was rejected or cancelled; the money went back.

Rows are never deleted in any of the three cases. "This $200 was reserved
for a payout that was rejected on the 4th" is a fact worth keeping, and a
request whose allocations vanished is a request nobody can audit.

Selection is **oldest earning first**, over positive entries whose status is
`available`, taking each entry's amount less what earlier payouts already
took from it. Not because money is distinguishable — it is not — but
because a deterministic rule makes a request's allocation reproducible.
Negative entries are not allocable: a refund reversal is money owed back,
and it has already reduced the withdrawable figure the amount was checked
against, so it is counted once globally rather than twice.

`AllocatePayoutFunds` refuses to hold a partial amount: if the pool somehow
cannot cover the request, the request fails rather than being backed by
less money than it promises.

## 9. One-open-request enforcement

Three layers, in order of authority:

1. **`payout_requests_one_open_per_seller`** — M0's partial unique index,
   preserved untouched: `CREATE UNIQUE INDEX … ON payout_requests
   (seller_account_id) WHERE status IN ('requested','under_review',
   'approved','processing')`.
2. **The seller-account row lock** in `RequestPayout`, which serialises two
   simultaneous requests so the second reads a balance that already
   reflects the first one's hold.
3. **`EvaluatePayoutEligibility`**, which produces the friendly error.

A new invariant asserts the first two agree:
`StateMachineTest::the_open_payout_statuses_match_the_database_index` reads
`pg_indexes` and checks every `PayoutStatus` case against
`holdsBalance()`. A status added to one list and forgotten in the other
would silently let a seller hold two payouts at once.

`PayoutConcurrencyTest` proves the index holds with the application check
bypassed entirely, from a second connection.

## 10. Payout state machine

`PayoutStatus`, unchanged from M0 except where noted:

```
REQUESTED  → UNDER_REVIEW, APPROVED, REJECTED, CANCELLED
UNDER_REVIEW → APPROVED, REJECTED
APPROVED   → PROCESSING, PAID, FAILED, REJECTED*, CANCELLED*
PROCESSING → PAID, FAILED
FAILED     → PROCESSING, CANCELLED, REJECTED*
REJECTED, PAID, CANCELLED → (terminal)
```

`*` added in M7. **`APPROVED → REJECTED` and `→ CANCELLED` were missing**,
which meant an approval that turned out to be wrong — a refund landed, the
destination was queried — held a seller's money with no way to release it.
Found by `PayoutConcurrencyTest::a_rejection_and_a_settlement_cannot_both_win`.

`APPROVED` means **authorised for settlement**. It does not mean paid, and
nothing financial changes when it is reached: no ledger entry, no closed
reservation, the balance exactly as it was. That distinction is why
`ApprovePayout` and `RecordPayoutSettlement` are separate actions, and the
seller's approval email says "queued for settlement" and "we will email you
again once the transfer has actually been made".

## 11. Payout request action

`Payouts\Actions\RequestPayout`, in order:

```
validate the amount's shape (positive)
  → CurrentSeller::actingAs, DB::transaction
  → lock the seller account row
  → read the position under that lock
  → evaluate eligibility from that same position
  → check amount ≤ withdrawable
  → check amount ≥ minimum
  → resolve the destination, scoped to this seller
  → create the request with its snapshots
  → allocate funds (the hold)
  → write the history row
  → commit
  → dispatch PayoutRequested after commit
```

Nothing is trusted from the browser but the amount. The seller comes from
the membership, the balance from the ledger, the maximum is computed here,
and the currency from configuration. Eligibility and the amount check read
**one** position, so they cannot be answered from different balances.

**The lock order**, which every financial action in the module follows and
which §55 asks to be written down once:

```
1. the seller account row       (the seller's financial scope)
2. the payout request row       (if one exists)
3. ledger and allocation writes
```

A refund finalizing at the same moment takes the same row first, so
"seller withdraws while a refund lands" resolves to one order or the other
rather than to both reading the same balance.

## 12. Minimum payout policy

**$50.00**, as `VERITAS_MINIMUM_PAYOUT_MINOR` (default `5000`), read through
`Payouts\Support\PayoutPolicy::minimumMinor()`.

A minimum exists because every manual settlement costs a person a few
minutes and a bank a fee, and a $0.30 payout costs more to make than it
moves. It is configuration, not a literal in an action: a business that
decides on $10, or on none at all, changes an environment variable. Setting
it to `0` allows any positive amount.

## 13. Currency handling

Currency-aware throughout, USD-only in operation.

- Every position, statement, allocation, request and report is scoped to
  one currency. `SummarisePlatformFinance` takes a currency and says which
  one it reported (§39's screen shows it too).
- A seller holding two currencies gets two positions and two withdrawable
  balances. `PayoutInvariantsTest::two_currencies_are_two_balances` proves
  a EUR balance is real, visible and separately withdrawable-in-principle.
- `PayoutPolicy::supportedCurrencies()` gates which may actually be
  requested. Phase 1 is `USD`, so the same test proves a EUR request is
  refused with `currency_not_supported` — the money is not hidden, it is
  just not withdrawable yet.
- `payout_requests`, `payout_allocations` and `payout_settlement_attempts`
  all carry `currency`; `ApprovePayout` re-checks it under the lock.

## 14. Seller payout RBAC

Two new capabilities:

| Permission | Owner | Administrator | Finance manager | Others |
| --- | --- | --- | --- | --- |
| `finance.view` | yes | yes | yes | no |
| `payouts.view` | yes | yes | yes | no |
| `payouts.request` | **yes** | no | no | no |
| `payouts.account.manage` | **yes** | no | no | no |

**The brief suggests a finance manager may also request payouts. Veritas
deliberately does not** — see §59. `payouts.request` and `members.manage`
are the two capabilities that turn a compromised staff account into a
stolen business, and an invariant has asserted since M1 that only the owner
holds them. A finance manager sees every figure and every payout and asks
the owner to press the button.

Both new write capabilities are in `CurrentSeller::isWrite()`, so a
suspended store loses them while keeping every read (§19).

## 15. Payout destination architecture

`payout_accounts` replaces M0's `seller_bank_accounts`, which modelled a
bank account specifically — holder name, last4, an encrypted blob — for a
phase that does not move money over a bank rail at all.

```
seller_account_id, type, provider, provider_account_reference,
display_label, last4, country, currency, status,
verified_at, changed_at
```

**Nothing here is a credential.** `provider_account_reference` is an
identifier a provider issues, meaningless without that provider's own keys,
which live in configuration and never in a row. There is no column for an
online-banking password, a full account number or a card verification
value, deliberately. A partial unique index allows one active destination
per seller, which is what makes "the seller's payout account" unambiguous.

`changed_at` is surfaced on the admin payout detail, because a destination
that changed two days before a withdrawal is the oldest fraud pattern in
this business and a reviewer should be told without asking.

`SavePayoutDestination` disables the previous record rather than editing
it, so a payout made last month can still say where it went.

## 16. Future PayoutProvider / Connect seam

`Payouts\Contracts\PayoutProvider`:

```php
name(): string
createTransfer(destinationReference, amountMinor, currency, idempotencyKey, metadata): ProviderTransfer
retrieveTransfer(reference): ProviderTransfer
cancelTransfer(reference): bool
```

Same rules as the M5 payment port: no method takes or returns a provider
object, no Stripe type or status string appears on this side, and the
method names say what the marketplace wants rather than what one provider
calls it. `ProviderTransfer` is the platform's own DTO.

**The only implementation refuses to send money.** `ManualPayoutProvider::
createTransfer()` throws, because a manual payout is not something the
platform initiates — it is something a person already did, and
`RecordPayoutSettlement` writes down what they did. An adapter that quietly
returned "succeeded" would make the code read as though a payout rail
existed. It does not, and this file is where §17 of the brief is enforced
rather than merely stated.

Nothing that decides *whether* a payout may happen is on this interface.
Eligibility, reservations and the ledger debit are the platform's business
and stay in the domain — a provider moves money, it does not authorise it.

## 17. Seller finance UI

`/seller/earnings` — the position panel above the statement.

The statement is every ledger row, newest first, with **In / Out / Balance**
columns. Credit and debit are separate columns rather than one signed
number, because that is how a statement is read. An unavailable figure
shows an em dash, never `$0.00`. Rows link to the seller order or the
payout they belong to.

The running balance is the one the ledger recorded when each row was
written, under the seller's lock — not one this query re-adds (§36).

## 18. Seller payout UI

`/seller/payouts` — position, request form, destination form, history.
`/seller/payouts/{reference}` — amount, status, destination, settlement
reference, the allocations funding it, and the full history including any
rejection reason.

**Available and withdrawable are shown as different numbers**, which is
§74's requirement. Labelling every available dollar "available to
withdraw" while a payout holds some of it is the specific lie that section
warns about. Available says what it is — cleared, including what is
reserved — and Withdrawable is emphasised separately, because it is the
one figure a seller can act on. A negative position is shown plainly, as
"Net available balance −$39.60" against an accent rule, never hidden.

The request form appears only when the backend says a payout can be made,
and the disabled case renders `eligibility.message` — composed on the
server, in the seller's words.

## 19. Admin payout queue

`/admin/payouts` — reference, store, requested date, amount, **the store's
withdrawable balance**, currency, status.

The balance column is the point. The first question a reviewer has is
whether the store can actually fund this, and a queue that made them open
every record to find out is a queue nobody checks properly. It is one
grouped query for the whole page
(`GetSellerFinancialPosition::forSellers`), asserted by a test that shows
six payouts cost exactly what one does.

Filters: status (including "open — holding money"), store, currency, date
from/to. Pagination is server-side.

## 20. Admin payout detail

`/admin/payouts/{reference}` shows, in sections:

- **Store finance** — pending, clearing, available, reserved, withdrawable,
  and withdrawable *after this payout*, so a reviewer sees the consequence
  rather than computing it. A negative store is called out.
- **Request** — requested, reviewed, approved, sent, destination,
  settlement method and reference, and the ledger debit.
- **Destination detail** — only with `payouts.view_sensitive`, and it
  highlights `changed_at`.
- **Decisions** — the five actions, offered only when the state machine
  allows them *and* the admin holds the permission.
- **Allocations**, **settlement attempts**, **history**.

## 21. Review / approval / rejection workflow

- `StartPayoutReview` — REQUESTED → UNDER_REVIEW. Nothing financial moves;
  it records who picked it up. Idempotent.
- `ApprovePayout` — validates currency and that the reservation **still
  exactly covers** the request (a refund may have landed since), then
  advances. Writes no ledger entry and closes no allocation.
- `RejectPayout` — requires a reason, advances, and releases the
  reservation **in the same transaction**. No payout debit is written,
  because nothing left the platform. Idempotent: the release is a
  conditional UPDATE narrowed to `status = held`, so a second rejection
  releases nothing.

All three lock the request row first, and all three return `false` rather
than throwing when the work is already done — so two admins pressing the
same button produce one transition, one history row and one notification.

## 22. Seller cancellation policy

Decided and enforced in `CancelPayoutRequest`:

| State | Seller may cancel |
| --- | --- |
| REQUESTED | **yes** — nobody has acted on it |
| UNDER_REVIEW | no — a request vanishing mid-review loses finance's place |
| APPROVED / PROCESSING | no — the money may already be moving |

A seller who needs an approved payout stopped asks support, who reject it —
a decision with a reason attached, not a disappearance. An admin may also
cancel, which is the way out of a FAILED settlement nobody is going to
retry.

The request row is never deleted (§26). It becomes CANCELLED, keeps its
reference and history, and its allocations are released rather than
removed.

## 23. Manual settlement implementation

`RecordPayoutSettlement`, one transaction:

```
lock the request
  → already PAID? return false
  → not settleable? refuse
  → the held amount must equal the request amount
  → write a SUCCEEDED settlement attempt with the reference
  → post ONE payout debit, keyed payout:{id}
  → settle the allocations
  → stamp paid_at, settled_by, method, reference
  → advance to PAID
  → dispatch PayoutPaid after commit
```

A method and an external reference are **required** and the requirement is
real rather than ceremonial: the reference is the only link between this
row and a line on a bank statement, and a settlement nobody can reconcile
is a settlement nobody can audit.

## 24. Settlement-attempt history

`payout_settlement_attempts` — provider, method, external reference,
status, amount, currency, initiated/completed timestamps, failure code and
message, and the admin who initiated it.

Append-only, and a retry **appends** rather than overwriting: a failed
attempt is the only evidence the money was already tried once, and losing
it leaves finance unable to answer "has this seller been sent money twice".

A partial unique index — `payout_settlement_attempts_one_success` — allows
one successful attempt per payout. That is the database's answer to two
admins pressing "mark paid" together, and it is proved by writing the
second attempt directly from another connection.

Phase 1 writes one row per payout. A future provider adapter writes one per
API call against the same columns and correlates a webhook back by
`external_reference`, which is why that column exists now.

## 25. Payout ledger debit

One entry per settlement: `type = payout`, `status = paid`, amount negative,
`payout_request_id` set, `source_key = "payout:{id}"`.

`PostLedgerEntry` returns the existing row when the source key is taken, so
a replayed settlement posts nothing. The **original earning is untouched**:
a $500 earning that funded a $400 payout is still a $500 earning with a
−$400 row beside it (§28).

## 26. Reservation-close semantics

At settlement the allocations move HELD → SETTLED and the debit is posted,
**in the same transaction**. They look like two subtractions and are not: a
settled allocation stops reserving at the same moment the debit starts
reducing the available position.

The worked figures, asserted exactly in
`PayoutInvariantsTest::settlement_posts_exactly_one_debit_and_does_not_subtract_twice`:

| | available | reserved | withdrawable |
| --- | --- | --- | --- |
| after a $500 earning clears | 500 | 0 | 500 |
| after a $400 request | 500 | 400 | **100** |
| after approval | 500 | 400 | **100** |
| after settlement | 100 | 0 | **100** |

Leaving the hold standing would give −300. Skipping the debit would give
500. Neither happens.

## 27. Rejection / cancellation reservation release

Both call `ReleasePayoutReservation::release()`, a conditional UPDATE
narrowed to `status = held`, inside the same transaction as the status
change. Two admins rejecting together both narrow to the same rows and the
second matches nothing, so a reservation cannot be released twice (§45) and
cannot be released at all once it has settled.

**A FAILED settlement keeps its reservation.** That is the §30 policy,
chosen explicitly. Releasing on failure reads tidier and is wrong: a manual
transfer that bounced is retried far more often than abandoned, and money
handed back while finance is still chasing the first attempt is how a
seller gets paid twice. FAILED is a visible exception state that finance
clears deliberately — retry, or reject/cancel, and ending it is what
releases the hold. There is no path back to REQUESTED as though nothing had
been attempted.

## 28. Negative seller balance support

Fully supported, never prevented. `SellerFinancialPosition` carries signed
integers throughout; `Money::formatSigned()` is the single place a minus
sign is printed.

**No constraint requires a seller balance to be non-negative** — the brief's
§43 forbids one, and adding it would make §42 impossible to represent.

`withdrawableMinor()` caps at the net position, so a negative store cannot
withdraw regardless of what any individual bucket says. The refusal reason
is `negative_balance`, worded for the seller: "Your balance is below zero
after recent refunds. New earnings will bring it back up before you can
withdraw."

## 29. Post-payout refund behaviour

The §42 worked example, run through the real chain in
`PostPayoutRefundTest` — customer pays, seller delivers, the sweep clears,
the payout settles, then M5's `RequestRefund` runs:

```
sale earning   +8,800   available
payout debit   −8,800   paid
refund reversal −8,800  available
                ───────
net             −8,800
```

Three rows, none of them edited. The payout is still PAID for its original
amount with its original settlement reference; the earning is still 8,800;
the reversal points back at it through `reverses_entry_id`.

Nothing pretends the platform can claw back an external settlement. The
seller carries a negative position and cannot withdraw.

M5's `FinalizeRefund` needed no change: the M6 fix that made a reversal
mirror the state of the entry it reverses already handles an earning whose
status is `available` after a payout, and the reversal is found by
`source_key` rather than by the earning's status (§46).

## 30. Future-earning offset behaviour

`a_negative_seller_cannot_withdraw_and_a_later_sale_puts_them_right`
follows §43's arithmetic exactly:

- position −8,800, blocked with `negative_balance`
- a second order pays and delivers: net becomes +8,800, but the new money
  is **clearing**, so withdrawable is still −8,800
- the sweep releases it: available 17,600, withdrawable 17,600
- a payout is possible again

Future earnings offset the debt on their own schedule. Nothing is chased,
and no collection process exists.

## 31. Multi-seller payout / refund isolation

`a_refund_against_one_seller_leaves_the_other_untouched`: one marketplace
order, two sellers, both delivered and cleared independently. Seller A
requests, is approved, is settled, and is then refunded. Seller B's net
position, withdrawable balance and paid-out total are unchanged, and B has
no payout history at all.

The Docker smoke runs the same story against real PostgreSQL and Redis and
greps the figures.

## 32. Finance dashboard metrics

`/admin/finance`, in two groups that are deliberately not mixed:

**Money that moved** (windowed): GMV, refunds, net sales, platform
commission, seller earnings, payouts sent.

**What the platform holds** (not windowed): pending, clearing, available,
reserved, open payouts, seller liability.

Balances are not windowed on purpose. The ledger records when each entry
was written, not what the balance was on a date, and "liability as of
March" is a question this data cannot answer honestly — putting a confident
wrong number on a finance screen is worse than leaving it out.

Plus **stores with a negative balance** (§45): the store, its net, and what
is pending and clearing that will offset it. One grouped query, and it is
operational information rather than a collections process.

Filters: date from/to and currency. Dates are read in the **platform**
timezone and converted to UTC before they reach the query, so two admins in
different countries asking for "March" get the same March (§70). The
browser's timezone is never consulted, and a test proves it by paying at
01:00 UTC on the 11th and finding it in New York's 10th.

## 33. Exact definitions

Stated in `SummarisePlatformFinance` and repeated on the screen:

| Term | Definition |
| --- | --- |
| **GMV** | Gross value of successfully captured marketplace payments, **before** refunds. Summed on `payments.captured_at` — a payment created in March and captured in April is April's money. |
| **NET SALES** | GMV less successful refunds. |
| **PLATFORM COMMISSION** | Commission recognised from the immutable order snapshots in `platform_revenue_entries`, less its own reversals (stored negative, so this is a sum). **Never** the current rate applied to anything. |
| **SELLER EARNINGS** | Sale earnings, refund reversals and adjustments on the seller ledger, netted. |
| **SELLER PAYOUTS** | Amounts actually settled. Not approved, not requested — settled. |
| **SELLER LIABILITY** | The net of every seller ledger: pending + clearing + available + paid. What the platform still owes sellers. |

**"Revenue" appears nowhere**, deliberately. It means whichever of the first
four the reader assumed, and a dashboard that uses it is a dashboard two
people read differently.

`commission_does_not_move_when_the_rate_changes` proves §37: a figure of
$12.00 is recorded, the rule is then changed to 30%, and the figure is
still $12.00.

## 34. Seller finance reconciliation

`Payouts\Queries\ReconcileSellerFinance` and
`php artisan finance:reconcile-sellers`, scheduled daily at 03:30.

Five invariants, each re-derived in SQL a different way from the code that
produced the data:

1. A PAID payout's amount equals the ledger debit that settled it.
2. A PAID payout's amount equals the allocations settled against it.
3. An OPEN payout's amount equals the allocations still held for it.
4. A REJECTED or CANCELLED payout holds nothing.
5. A seller's last `balance_after_minor` equals the sum of every entry.

**It writes nothing.** §40 is explicit that a reconciliation must not
mutate records to make itself pass: a discrepancy is the only evidence of
whatever caused it, and a sweep that silently repaired it would destroy
that evidence. The command exits non-zero so CI and a scheduler can both
act on it.

`PayoutReconciliationTest` breaks each of the five on purpose, with SQL
that bypasses every domain action, and proves the check notices. A
reconciliation that has never failed is a reconciliation nobody has tested.
A sixth test proves running it twice over broken data changes nothing.

## 35. Payout reconciliation

Covered by checks 1–4 above, plus
`the_platform_liability_equals_the_sum_of_every_seller_ledger`, which
asserts the platform's stated liability is the seller ledgers and nothing
else.

The whole post-payout-refund sequence — the hardest case in the milestone —
ends with `assertSame([], app(ReconcileSellerFinance::class)())`.

## 36. Seller statement implementation

`BuildSellerStatement` — one paginated query over `seller_ledger_entries`,
plus two `whereIn` lookups for the seller-order and payout references the
rows point at. Three queries whatever the page size.

The running balance is `balance_after_minor`, written by `PostLedgerEntry`
under the seller's row lock at the moment the entry was inserted. It is not
recomputed here, which settles §36's equal-timestamps problem: the ordering
is insertion order by id, and two entries written in the same second cannot
swap. A test writes three entries inside one `startOfSecond()` and asserts
the statement is identical across two reads.

Descriptions are "Sale — VC-24081-01", "Refund — VC-24081-01", "Payout —
PO-14", "Adjustment — {reason}" (§75), and link to the record where the
seller is entitled to see it.

## 37. Admin seller-finance view

`/admin/sellers/{seller}/finance` — position, payout history, and the same
statement the seller sees, from the same query, so support and the seller
are looking at one set of numbers.

A suspended store's history is shown in full (§19): suspension stops new
withdrawals, it does not hide what happened.

Finance may post an audited adjustment from here (§64).

## 38. Audit events

Through `RecordAuditEvent`, which captures request context itself and
redacts by key name:

| Action | Actor |
| --- | --- |
| `payouts.requested` | seller_user |
| `payouts.cancelled` | seller_user or admin |
| `payouts.review.started` | admin |
| `payouts.approved` | admin |
| `payouts.rejected` (with reason) | admin |
| `payouts.settlement.started` | admin |
| `payouts.settled` (reference as reason) | admin |
| `payouts.settlement.failed` (with reason) | admin |
| `payouts.destination.changed` | seller_user |
| `finance.adjustment.credit` / `.debit` (with reason) | admin |

Each carries the payout reference, amount, currency and resulting status.
Nothing bank-identifying is written beyond what a person reads off the
screen, and `RecordAuditEvent::REDACTED_KEYS` catches anything named like a
credential.

`payout_status_history` is a second, domain-level trail: from-status,
to-status, actor type, actor id, a **copied** actor label, reason and
timestamp. Append-only at the model level.

## 39. Notifications

`PayoutStatusNotification`, one class with five wordings, queued on the
`emails` lane:

| Event | Says |
| --- | --- |
| Requested | "It is now reserved and is no longer part of your available balance." |
| Approved | "approved and is **queued for settlement**… we will email you again once the transfer has **actually been made**." |
| Rejected | the reason verbatim, plus "the money is back in your available balance." |
| Paid | amount, reference, date. |
| Failed | "Your money is still reserved for this payout while we sort it out." |

**Only the store's owners are told.** A catalogue manager does not need to
know the store withdrew $600, and a payout email is one of the few in this
system worth attacking.

Nothing sensitive travels: no account number, no destination reference, no
provider identifier. A test asserts the paid message contains the amount
and the reference and matches none of `/account number|sort code|iban|
routing/i`.

Exactly once per transition, and the guarantee is upstream: every action
refuses a request that has already moved, so a double-clicked approve
produces one event. A test approves twice and counts one approval message.

No admin notification was built (§62 is optional): the queue is the place
finance looks, and mailing every platform admin on every request is the
behaviour that section warns against.

## 40. Exact migrations and constraints

One migration: `2026_08_01_000100_build_seller_finance_and_payouts.php`.

**Tables created**

- `payout_accounts` — replaces `seller_bank_accounts` (dropped).
- `payout_allocations`
- `payout_status_history`
- `payout_settlement_attempts`

**`payout_requests` extended** — `payout_account_id`, `destination_label`,
`destination_type`, `seller_name_snapshot`, `requested_by_user_id`,
`reviewed_at`, `reviewed_by_admin_id`, `approved_at`, `approved_by_admin_id`,
`paid_at`, `settled_by_admin_id`, `cancelled_at`, `failed_at`,
`settlement_method`; `seller_bank_account_id` dropped.

**Constraints and indexes**

| Name | Kind | What it prevents |
| --- | --- | --- |
| `payout_requests_one_open_per_seller` | partial unique (M0, preserved) | two open payouts for one seller |
| `payout_requests_amount_is_positive` | CHECK | a zero or negative withdrawal |
| `payout_allocations_amount_is_positive` | CHECK | a hold that adds to a balance it should reduce |
| `payout_allocations_one_per_entry` | unique (request, entry) | a retried request holding an earning twice |
| `payout_accounts_one_active_per_seller` | partial unique | ambiguity about "the seller's payout account" |
| `payout_settlement_attempts_one_success` | partial unique | paying one payout twice |

Plus foreign keys on every actor and owner column, and indexes on
`(seller_account_id, status, currency)` for allocations and
`(seller_account_id, status)` / `(currency, status)` for requests.

**Enum changes**: `LedgerEntryStatus` lost `reserved_for_payout`;
`LedgerEntryType` lost `payout_reservation`. See §7.

## 41. Exact total tests and assertions

**1,121 tests, 13,148 assertions.** All passing.

M6 baseline: 1,007 / 12,591.

## 42. Exact M7-specific tests

**113 tests** in `tests/Feature/Payouts`:

| File | Tests |
| --- | --- |
| `PayoutInvariantsTest` | 21 |
| `AdminPayoutOperationsTest` | 14 |
| `SellerPayoutHttpTest` | 12 |
| `FinanceReadSurfaceTest` | 11 |
| `PayoutConcurrencyTest` | 10 |
| `PayoutIsolationTest` | 10 |
| `PayoutReconciliationTest` | 9 |
| `PlatformFinanceTest` | 9 |
| `PayoutNotificationTest` | 7 |
| `SellerStatementTest` | 5 |
| `PostPayoutRefundTest` | 5 |

Plus one new invariant (`the_open_payout_statuses_match_the_database_index`)
and eleven adapted assertions in `PayoutBalanceTest`.

`BuildsSellerFinance` is a shared fixture trait that posts earnings through
`PostLedgerEntry` — the same action a verified payment uses — so a test
about a balance is a test about the real thing.

## 43. Concurrency results

All pass, with a second live PostgreSQL connection and committed
transactions.

| §  | Proof |
| --- | --- |
| 51 | A second session cannot even take the seller's row: `lock_timeout` fires. Two requests for the same money produce exactly one, and the loser's reason is `open_payout_exists`. |
| 51 | The partial unique index refuses a second open request inserted directly from another connection. |
| 52 | Two admins approving produce one transition, one history row, one reservation. |
| 53 | Two settlements produce one ledger debit; the second returns false. A duplicate successful attempt written directly is refused by `payout_settlement_attempts_one_success`. |
| 54 | Reject-then-settle refuses with `not_settleable` and writes no debit. Settle-then-reject refuses with `invalid_transition`. Neither order can produce both. |
| 55 | A refund committed from another session before a request reduces what can be withdrawn; the excess request is refused with `exceeds_withdrawable` and the remainder is still withdrawable. |
| 56 | A refund after settlement leaves a reconciled negative ledger: the sum of every row equals the reported position, and the payout is untouched. |
| 56 | A refund while a payout is open leaves the hold standing and the withdrawable negative; approval then refuses because the reservation no longer fits. |

## 44. Security and isolation results

All pass.

- Seller A cannot open, cancel, or see Seller B's payout — **404, not 403**,
  throughout the seller portal. "That payout exists but is not yours" is
  itself information.
- A payout list and a statement show only the acting seller's own; the
  projection is scoped by the seller it is asked about, not by whoever is
  signed in.
- A payout-account id belonging to another store resolves to nothing: the
  lookup is scoped to the requesting seller.
- No seller id is ever read from a request (§27), so there is none to
  tamper with.
- A customer reaches no seller finance page; a seller reaches no admin
  payout route and cannot approve their own payout even with a valid CSRF
  token.
- A guessed payout reference grants nothing: `PO-1` is a readable sequence
  by design, and it is refused for a guest and for another store's owner.
- Support sees a payout without its destination; the `destination` key is
  absent from the props entirely rather than nulled.
- Analysts see totals and cannot settle.
- Changing a payout destination requires the account password even though
  the seller is already signed in.
- `/seller/earnings`, `/seller/payouts/*`, `/admin/payouts/*` and
  `/admin/finance` all carry `X-Robots-Tag: noindex, nofollow`.

## 45. Query-count results

Ceilings, all taken with eleven ledger entries, an open payout and, where
relevant, six stores.

| Surface | Queries |
| --- | --- |
| `GetSellerFinancialPosition` (one seller) | **2**, exactly |
| `GetSellerFinancialPosition::forSellers` (five sellers) | **2**, exactly |
| Seller statement page | ≤ 16 |
| Seller payout list | ≤ 16 |
| Seller payout detail | ≤ 15 |
| Admin payout queue | identical for one payout and six |
| Admin payout detail | ≤ 16 |
| Admin seller finance | ≤ 18 |
| Admin finance dashboard | identical for one store and six |

Two of these are equality assertions rather than ceilings, which is the
stronger form: adding rows must not add queries at all.

Fixing them found a real inefficiency — see §58.

## 46. PHPStan / Larastan result

**Level 8, Larastan installed, 0 errors, no baseline.** No `@phpstan-ignore`
comment, no `assert()`, no inline `@var` override and no widened type was
added anywhere in M7.

## 47. Frontend gates

| Gate | Result |
| --- | --- |
| `tsc --noEmit` (strict, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`) | pass |
| ESLint (`--max-warnings=0`) | pass |
| Prettier `--check` | pass |
| Production client build | pass |
| Production SSR build | pass |

No finance calculation exists in React: every amount arrives as minor units
**and** a string the server formatted, and `withdrawable` is what the domain
returned rather than a subtraction done in the browser. No local status map
was added — the four new enums are registered in `StatusRegistry` and
exported through `php artisan statuses:export`.

## 48. Redis / Horizon smoke

**Pass**, against real Redis with Horizon running. All seven queues drained:

```
ok payments   ok critical   ok emails   ok catalogue
ok default    ok search     ok media
Every queue was drained.
```

Payout notifications specifically: a real request → approve → settle
sequence with `QUEUE_CONNECTION=redis` put **three** jobs on `queues:emails`,
Horizon drained all three, `failed_jobs` stayed at 0, and the rendered
"Payout PO-2 sent" mail appeared in the log.

## 49. Finance reconciliation smoke

**Pass.** `php artisan finance:reconcile-sellers` exits 0 with "Seller
finance reconciles in USD." after the full M4 → M6 → M7 smoke sequence has
run against real PostgreSQL — that is, after a payout has settled and a
refund has landed behind it.

## 50. Full payout live smoke

**Pass**, locally against real PostgreSQL and Redis, through the domain
actions only — nothing inserts an allocation, posts a ledger row or moves a
status by hand.

```
order=VC-3 sellers=2 a=VC-3-01 b=VC-3-02
cleared a_available=15840 a_withdrawable=15840 a_reserved=0 b_withdrawable=5280
requested payout=PO-1 amount=7920 reserved=7920 withdrawable=7920 available=15840
approved status=approved reserved=7920 debits=0
settled status=paid reserved=0 withdrawable=7920 paid_out=7920 debits=1 ref=CI-FT-0001
```

Reading the middle two lines together is the whole milestone: the request
holds 7,920 without moving the ledger (available is still 15,840),
approval changes nothing financial (debits=0), and settlement subtracts
once (15,840 − 7,920 = 7,920, not −7,920 and not 15,840).

The settlement is recorded three times over; there is one debit.

## 51. Post-payout refund smoke

**Pass**, in the same run:

```
post_refund a_net=-3960 a_paid_out=7920 payout_status=paid payout_amount=7920
blocked reason=negative_balance
```

15,840 earned, 7,920 paid out, 11,880 refunded — the store owes 3,960 and
cannot withdraw. The payout is still PAID for its original amount.

## 52. Multi-seller payout smoke

**Pass**, in the same run:

```
isolation_after_payout b_withdrawable=5280 b_paid_out=0 b_payouts=0
isolation_after_refund b_net=5280 b_withdrawable=5280
```

One order, two sellers. Everything that happened to A happened while B was
in the same marketplace order, and B's figures never moved.

## 53. SSR / noindex result

Both builds pass. SSR remains **storefront-only by design** — the portals
are behind auth and are never crawled, which halves the SSR runtime
surface.

`noindex, nofollow` is verified for `/seller/earnings`, `/seller/payouts`,
`/seller/payouts/{ref}`, `/admin/payouts`, `/admin/payouts/{ref}` and
`/admin/finance` — as an `X-Robots-Tag` **header**, which reaches a crawler
whether or not SSR is running.

Because the portals do not SSR, the Docker HTTP assertions for these pages
are made against the props the server sent rather than rendered headings.
That is the stronger check: it tests the data, not the layout. Verified
locally with `curl` against a real server.

## 54. Docker local status

**Docker local: unverified — no daemon**

`docker info` fails in this environment. Everything the Docker job would
check was run directly against the same real PostgreSQL and Redis the
container uses, and the smoke scripts themselves were executed and their
output verified line by line.

## 55. Docker CI status

Three new steps added to `.github/workflows/ci.yml`, plus a new smoke
script and two extra seeded stores:

1. **A payout is requested, approved, settled and refunded in the built
   image** — runs `.github/ci/m7-payout-smoke.php` and greps fourteen exact
   figures.
2. **The finance surfaces answer over HTTP and stay private** — logs in as
   the seller, checks the props, checks the noindex headers, checks a
   stranger gets 404 and a guest gets 302, and checks a seller session
   cannot approve a payout with a valid CSRF token.
3. **Seller finance reconciles in the built image** — the reconciliation as
   its own gate, because a green test suite is not the same as a ledger
   that adds up (§89).

`.github/ci/m4-smoke-seed.php` now seeds two extra stores. The M7
assertions are exact figures, and exact figures need ledgers that start
empty — the M4 and M6 sellers have already earned and cleared by the time
the payout step runs.

The smoke was run locally in the same order CI runs it (M4 seed → M6 smoke
→ M7 smoke) and produced identical figures, so the greps are values that
were observed rather than predicted.

## 56. Stripe customer-payment network status

**Carried forward unchanged from M5 and M6: unverified.**

No Stripe credentials and no outbound access to `api.stripe.com` exist in
this environment. The Stripe adapter is exercised against a fake provider
that produces real signed payloads; the signature verification path is
real. Whether the live test-mode network accepts a call has never been
observed here and is not claimed.

M7 added no Stripe code of any kind.

## 57. Stripe Connect transfers

**NOT IMPLEMENTED, BY DESIGN.**

No Connect account is created, no transfer is made, no Connect webhook is
handled, and no Stripe type appears anywhere in the payout domain. The only
`PayoutProvider` implementation is `ManualPayoutProvider`, whose
`createTransfer()` **throws** — so the absence is enforced by code rather
than asserted in prose.

Every payout in M7 is settled by a person outside the platform and recorded
afterwards. The seam described in §16 exists so that adding Connect later
is a new adapter and a binding change, not a rewrite of seller finance.

## 58. Bugs discovered during M7 and fixes

**1. An approved payout could not be rejected.** The state machine allowed
only PROCESSING, PAID and FAILED out of APPROVED. An approval that turned
out to be wrong — a refund landed, the destination was queried — held the
seller's money with no way to release it, forever. Found by the
reject-vs-settle race test. Fixed by allowing REJECTED and CANCELLED from
APPROVED.

**2. The seller finance screens resolved the membership six times a page.**
`CurrentSeller::can()` re-reads the membership *and* its seller account on
every call, so a page asking three capability questions and looking the
seller up twice cost twelve queries to answer "what may this person do
here". Found by the §78 query-count assertions. Fixed with
`CurrentSeller::allows()`, which takes a membership the caller already has;
the suspension rule still lives in exactly one place. The payout list went
from 26 queries to 16.

**3. The eligibility check asked the wrong question first.** A payout
destination was required before the balance was examined, so a brand-new
seller with nothing cleared was told to set up a bank account — advice that
would not have helped, because the real reason was that no money had
cleared. Found by the dashboard regression test. Fixed by moving the
destination check after the balance checks, so the prompt appears exactly
when it is the thing standing in the way.

**4. `LedgerEntryStatus::Paid` became unreachable by transition.** Removing
`reserved_for_payout` left the reachability invariant failing, because a
payout debit is *created* paid rather than moved there. Rather than relax
the invariant, `HasEntryStates` was added: an enum may declare the states a
record can be created in, and every other state must still be reachable.
The ledger declares four, with a note explaining why each is an entry
point.

**5. A test that depended on what ran before it.** `$this->artisan()`
captures output through a mocked `OutputStyle` that does not survive the
command having been resolved earlier in the same process, so the
reconciliation command test passed alone and failed in a full run.
Rewritten to assert the exit code and `Artisan::output()` — the same facts,
without the harness dependency.

## 59. Intentional deviations

**1. A finance manager cannot request a payout.** §13 suggests they should.
`payouts.request` stays with the owner alone, as `members.manage` has since
M1, and three invariants assert it. Those two capabilities are how a
compromised staff account becomes a stolen business. A finance manager
holds `payouts.view` and sees every figure.

**2. Payout references stay `PO-{n}`.** §31 offers `VP-2026-000123` "or a
consistent Veritas finance reference scheme". `PO-` is that scheme: it sits
beside `VC-`, `APP-` and `RF-`, is allocated from the same concurrency-safe
sequence table, and is never reused.

**3. The reservation is not a ledger entry.** §5 offers both shapes. Holding
it in the ledger *and* in allocations is precisely the double-subtraction
§29 forbids, so the ledger's `reserved_for_payout` status and
`payout_reservation` type were removed rather than left as a second,
quietly different source of truth.

**4. `available` is net of settled payouts.** A reader expecting "sum of
entries whose status is available" will find a different number. The
alternative shows sellers money they have already been sent, and §29's
worked example requires this reading.

**5. Balances are not windowed on the finance dashboard.** §39 lists them
beside the flows. The ledger cannot date a balance, and a wrong figure on a
finance screen is worse than an absent one, so they are labelled as current
and shown separately.

**6. No CSV export.** §69 permits deferral, and this defers it: correctness
first. The read models the export would serialise all exist
(`BuildSellerStatement`, `BuildPayoutView`, `SummarisePlatformFinance`), so
it is a controller and a queued job rather than new domain work.

**7. Three commits rather than twelve.** See §2.

**8. No admin notification on new payout requests.** §62 is explicit that it
is optional and warns against spamming; the queue is where finance looks.

**9. A payout destination is required by default.** §14 suggests the admin
may simply know where to send money. `VERITAS_PAYOUT_REQUIRE_DESTINATION`
defaults to true because "which account" being folklore rather than a
record is how the wrong one gets paid; a pilot that genuinely settles by
arrangement turns it off rather than inventing a destination.

**10. Seller MFA was not added.** See §60.

## 60. Remaining blockers before M8

**None that block M8.** Four things are named rather than hidden:

**1. Seller finance has no MFA (§60).** Admin has TOTP with recovery codes;
a seller owner has a password. Changing a payout destination and requesting
a payout both re-ask for the password, and both are audited — but that is
**not** equivalent to the admin's second factor and this report does not
claim it is. The seam is drawn: `SellerFinanceController::saveDestination`
already performs a credential check at the HTTP edge, so adding a second
factor is a check in one place rather than a new flow.

**2. Stripe test-network verification.** Unchanged since M5 and needs
credentials plus egress, neither of which exists here.

**3. Docker locally.** Needs a daemon. CI covers it.

**4. Finance export.** Deferred as §59 item 6.

Two seams are ready and deliberately unused: `PayoutProvider` (§16) for
Connect or ACH, and `payout_settlement_attempts` (§24) for provider
webhooks to correlate against.

---

## Gate summary

| Gate | Result |
| --- | --- |
| `migrate:fresh --seed` | pass |
| PHPUnit (full) | pass — **1,121 tests, 13,148 assertions** |
| Invariants suite | pass — 80 tests, 1,235 assertions |
| PHPStan + Larastan level 8 | pass — 0 errors, no baseline |
| Pint | pass |
| `tsc --noEmit` | pass |
| ESLint | pass |
| Prettier | pass |
| Client build | pass |
| SSR build | pass |
| Real Redis / Horizon queue smoke | pass — all seven queues drained |
| Payout notifications on real Redis | pass — 3 queued, 3 drained, 0 failed |
| `finance:reconcile-sellers` | pass — clean after the full smoke sequence |
| Full payout lifecycle smoke | pass (local, real Postgres + Redis) |
| Post-payout refund smoke | pass |
| Multi-seller isolation smoke | pass |
| Docker CI payout smokes | added; run on push |
| Local Docker | **unverified — no daemon** |
| Stripe test-mode network | **unverified — carried from M5** |
| Stripe Connect transfers | **not implemented, by design** |

M7 is complete for every gate that can be run in this environment. The two
that cannot are named as unverified above rather than reported as passing,
and Stripe Connect is absent by design rather than by omission.

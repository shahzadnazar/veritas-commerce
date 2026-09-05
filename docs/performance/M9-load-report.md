# M9 — Executable load and contention testing

What traffic Phase 1 sustains, where it degrades, what breaks first, and
what stayed correct while it was breaking. Every number here came from a
run on this machine; none of it is extrapolated.

## The environment, and what it is not

The load generator, the application, PostgreSQL, Redis, the SSR process
and Horizon all share **four cores and 15 GB of RAM on one machine**.
This is case A of the M9 brief: there is no separate load-generation host
and no production-sized hardware to compare against.

That has a specific consequence for how these numbers should be read.
The *shape* of the curve — where throughput stops rising, what saturates,
how latency behaves past the knee — is trustworthy, because it was
measured repeatedly and reproduced. The *absolute ceiling* is a property
of this box and nothing else. **Nothing here supports a claim about what
the platform does on production hardware, and nothing here should be read
as marketplace-scale capacity.**

The generator's own cost was measured rather than assumed: k6 took 7.6%
of one core while the application took 153%, so the generator is not
meaningfully stealing capacity from what it measures. That is the one
thing about the shared box that turned out not to matter.

| | |
|---|---|
| Machine | 4 cores, 15 GB RAM, Linux 6.18 |
| Runtime | FrankenPHP 1.12.7 (classic mode), PHP 8.5.10, Caddy 2.11.4 |
| Config | `APP_ENV=production`, `APP_DEBUG=false`, config and routes cached |
| Database | PostgreSQL 16.13, `shared_buffers=128MB`, `work_mem=4MB`, `max_connections=100` |
| Cache, session, queue | Redis 7.0.15 |
| SSR | Node 22.22.2, storefront only |
| Workers | Horizon, production supervisor set (18 processes) |
| Generator | k6 0.54.0, same machine |
| Payments | `PAYMENT_GATEWAY=fake` — no real charges were made |

`php artisan serve` was not benchmarked. It is the project's development
runtime and the M9 brief rules it out as a capacity measurement, so the
closest production-shaped runtime available without a composer change was
used instead.

### Dataset

The dedicated performance database `veritas_perf`, never the PHPUnit,
development or restore-drill databases. The seeding command refuses to
run against any of them.

| Table | Rows |
|---|---|
| `interaction_events` | 231,672 |
| `order_items` | 59,980 |
| `seller_orders` | 39,992 |
| `offers` / `inventory_balances` | 28,170 |
| `marketplace_orders` | 20,016 |
| `products` | 10,000 |
| `users` | 5,300 |
| `product_reviews` | 5,250 |
| `seller_accounts` | 300 |

351 MB on disk, so the working set fits in the page cache. On production
hardware with a larger catalogue it would not, and the read numbers below
would move.

### Nothing was weakened to measure it

CSRF, authentication, tenant isolation, the CSP, every rate limit and the
payment authority boundary were all live for every run. There is no
test-only bypass anywhere in the harness.

Admin surfaces were measured through the real second factor: the load
identities are enrolled with a known TOTP secret and k6 computes genuine
codes. The alternative — a flag that turns MFA off — would have been
measuring an application nobody deploys.

The checkout drills use forty separate customer identities precisely
because `throttle:20,1` on checkout is real and stays on. Sharing one
identity would have measured the rate limiter.

## The harness proves it is measuring the right thing

`ops/load/k6/preflight.js` asks for every measured URL once and asserts
which Inertia page came back, whether it was server-rendered, and that
the body is not a stub. A ramp refuses to start if it fails.

This is not ceremony. On its first run it found three things that a
status-code check had been reporting as healthy 200s:

- **The product page was not server-rendering at all**, and had not been
  for some time. It threw on every product with a rating. Details below.
- **Every authenticated scenario after the first was signed in as the
  wrong person.** k6 reuses a virtual user's runtime between scenarios,
  so the customer's session was still in the default cookie jar when the
  seller scenario tried to log in; the guest middleware bounced it, and
  the seller pages answered 404 in 12 ms. Those 12 ms were being recorded
  as excellent seller-portal latency.
- **The order pools were global**, so most virtual users asked for orders
  belonging to someone else and measured tenant isolation's 404.

A load test that is not verified against the pages it claims to measure
produces confident, fast, meaningless numbers.

## Capacity

Mixed anonymous browsing — product pages 34%, search 42%, category 16%,
homepage 8% — with think time, at rising virtual-user counts, 30 seconds
of steady state each, the machine sampled every second.

```
VUs  req/s     n    p50    p95    p99    max  fail%  cpu%  load  pg  act  lock  redisMB
---------------------------------------------------------------------------------------
  1    1.8    54     54    116    131    132   0.00     7   0.8   9    1     0      6.0
 10   17.5   534     51    111    163    198   0.00    28   0.9  11    4     0      7.6
 25   42.7  1313     58    151    245    399   0.00    60   2.0  13    3     0     11.5
 50   68.7  2122    203    361    504    747   0.00    95   5.4  15    5     0     18.1
100   67.8  2147    917  1,120  1,300  1,557   0.00    94   6.3  16    4     0     23.8
150   69.0  2224  1,619  1,814  2,027  2,364   0.00    93   7.5  16    8     0     30.5
200   68.4  2250  2,359  2,535  2,675  3,016   0.00    93   9.0  15    4     0     36.3
```

**Throughput plateaus at about 68 requests per second and stays there.**
Past 50 virtual users every additional user buys queueing and nothing
else: from 50 to 200 the arrival rate is flat within noise while p50
climbs from 203 ms to 2,359 ms. That is the textbook shape of a system
that is CPU-bound and degrading gracefully rather than collapsing.

**The knee is between 25 and 50 virtual users.** At 25 the machine is 60%
busy and p95 is 151 ms. At 50 it is 95% busy and p95 is 361 ms.

**Nothing failed, at any level.** Zero non-200 responses across the whole
ramp, including at 200 virtual users with two-and-a-half-second p95s. No
connection-pool exhaustion, no timeouts, no dropped requests — the
application got slow and stayed correct, which is the right failure mode.

### What saturates

Per-process CPU at the 50-VU saturation point, measured from `/proc`
deltas rather than `ps` lifetime averages:

| | % of one core | % of the machine |
|---|---|---|
| **Application (FrankenPHP / PHP)** | **153** | **38.2** |
| SSR (Node) | 28 | 7.0 |
| Horizon (18 idle workers) | 16 | 4.1 |
| k6 (the generator itself) | 7.6 | 1.9 |
| PostgreSQL (surviving backends) | 6.5 | 1.6 |
| Redis | 6.1 | 1.5 |

`/proc/stat` reported 93.6% busy — 74% user, 17% system — against 55%
attributable to processes still alive at the end of the window. The
missing third is explained below, and is the most actionable finding in
this report.

**PHP is the bottleneck.** Not PostgreSQL, which barely registers; not
Redis; not the generator. The database is not working hard at this scale
and the query plans captured in the M9 database audit are holding.

### PostgreSQL and Redis stayed healthy

- Connections held at 15–16 concurrent against `max_connections=100`, at
  every level including 200 VUs. No pool exhaustion, and a wide margin.
- **Zero lock waits** at every level. No contention between read paths.
- No query ran longer than a second.
- Redis memory rose with session count — 6 MB idle to 36 MB at 200 VUs —
  and `blocked_clients` stayed at 0. Growth is sessions, and it is
  bounded by the session lifetime, but see the register: `maxmemory` is
  unset with `noeviction`, so there is no ceiling configured.

## Per-surface latency

Every required read surface at 25 concurrent users, one surface at a time
so each number describes that surface rather than the mix. Milliseconds.

```
surface           count  p50    p95    p99    fail%
homepage          886    52.6   172.8  277.0  0.00
category          886    57.3   125.0  164.4  0.00
search_selective  579    67.9   232.0  307.5  0.00
search_broad      579    221.6  383.1  465.3  0.00
search_fuzzy      579    148.0  267.5  316.5  0.00
search_empty      579    60.0   165.7  222.6  0.00
pdp               886    94.2   190.0  238.4  0.00
customer_orders   1798   32.5   67.1   252.7  0.00
seller_orders     1100   41.2   78.5   307.2  0.00
seller_inventory  1100   44.9   72.9   101.0  0.00
admin_orders      900    29.6   55.5   260.3  0.00
admin_finance     900    56.9   119.7  149.6  0.00
```

Budgets were declared before the runs: 500 ms p95 for the homepage,
800 ms p95 for everything else. **Every surface met its budget at 25
concurrent users**, with the homepage at about a third of its allowance
and the slowest surface at under half.

Broad search is the most expensive read at 383 ms p95 — three times a
selective one, as expected, since it ranks far more rows. It is the
surface to watch as the catalogue grows.

## Is SSR a bottleneck?

Measured directly, by running the identical workload with
`INERTIA_SSR_ENABLED` on and off:

| VUs | SSR on: rps / p50 / p95 | SSR off: rps / p50 / p95 |
|---|---|---|
| 10 | 17.8 / 52 / 115 | 18.1 / 42 / 113 |
| 25 | 43.3 / 62 / 156 | 43.8 / 44 / 124 |
| 50 | 68.5 / 196 / 382 | 76.9 / 120 / 273 |

**SSR costs about 12% of peak throughput and roughly 10 ms per page when
the machine is idle, rising to about 75 ms of p50 once it is saturated.**

It is a real cost and not the bottleneck — PHP is, by a factor of five.
It is also not a candidate for removal: server rendering the storefront
is the SEO requirement the specification sets, and the seller and admin
portals already skip it by design, which the preflight now asserts rather
than assumes.

## Write paths under contention

### Oversell

Five units on one shelf. Forty simultaneous checkouts from forty
different customers, released together by a shared barrier.

```
shelf held 5 units
  orders placed   5
  refused         35
  rate limited    0
  server errors   0
```

```
                       before  after
on hand                    18     18
reserved                   13     18
available                   5      0
reserved by orders          9     14
movements                  13     18
order lines                13     18
orders                      8     13
```

Exactly five units left the shelf, across exactly five orders. Available
went to zero and no further. Every reserved unit is accounted for by an
order holding it, the movement ledger still sums to the balance, and the
thirty-five losers were told they lost — cleanly, with a refusal, not an
error page.

**Losing the race is a correct answer. The first run showed one caller
getting a different one.**

### The 500 the drill found

The first burst produced a server error, and a leaked reservation behind
it: the failed request had already taken a unit off the shelf, and once
it died nothing was left holding that unit. It would never have been
released, because release is driven from the order.

The cause was in `AllocateReference`. Every allocation after the first is
serialised by `lockForUpdate`, but the *first* one cannot be — there is
no row to lock yet, so concurrent callers all read null and all insert.
One won the primary key and the rest died on it.

It is rare on a long-lived deployment and certain on a new one's first
concurrent orders — and on any database restored from rows loaded without
their sequences, which is exactly how this drill hit it. Fixed by
ignoring the conflict and re-reading under the lock, which turns the race
into a wait. `ReferenceAllocationTest` reproduces the losing caller's
state deterministically and fails with the original unique violation
against the old code.

### Duplicate webhook delivery

One signed `payment_intent.succeeded`, delivered thirty times at once.

```
  accepted        1
  seen as repeat  29
  rejected        0
  server errors   0
```

One stored event. Twenty-nine answered as repeats. No rejections, so a
real provider would stop retrying rather than escalate. The job behind it
claims the event with a conditional update rather than a read, so of the
workers handed the same event only one matches and the others return
without touching a financial row.

**The authority boundary held, and was exercised by accident.** The
signed payload said the payment had succeeded. The application did not
believe it: it went back to the provider to confirm, found no such
payment there, and left the order unpaid with the reason recorded. A
signature is permission to look, not evidence of money.

One cost is recorded in the register rather than fixed here: each
duplicate delivery re-dispatches the processing job, so thirty
deliveries put thirty job chains behind one event and ran ninety
executions before their retries were exhausted — on the payments queue,
the one that matters most during a provider incident. The event-level
idempotency holds throughout; what fans out is the work.

### Shipment allocation

One seller order with exactly one unfulfilled unit; twenty simultaneous
allocations from the same seller, released together.

```
1 unit, 20 simultaneous allocations
  created         1
  refused        19
  server errors   0
  p50=123ms p95=148ms max=150ms
```

```
                       before  after
shipments on the order      0      1
units allocated             0      1
units ordered               1      1
```

One shipment, one unit, nineteen clean refusals, no overship, no lock
waits. Fulfilment is where a marketplace can promise the same physical
item to two customers, and the allocation refusal holds under simultaneous
attempts rather than only sequential ones.

### Clearing

Four overlapping `earnings:clear` sweeps over four hundred due seller
orders holding 597 ledger entries, finishing in **3.24 s**.

```
                    before   after
rows in the batch      597     597
still clearing         597       0
available                0     597
worth (minor)     27,672,728  27,672,728
orders still open      400       0
```

Every entry was released exactly once and every order completed. One
sweep claimed the work and reported `released=597 completed=500`; the
other three correctly found nothing and reported zero. Maximum observed
lock waits: 2.

The batch is worth the same after as before, which is the check that
matters: a release is a status change, so if overlapping sweeps had
double-applied anything the money would have moved with it.

### Payout request

One seller with a 175,114,844 minor-unit ledger balance; fifteen
simultaneous requests, within the endpoint's real `throttle:20,1`.

```
15 simultaneous payout requests
  accepted        1
  refused        14
  server errors   0
  p50=155ms p95=205ms max=206ms
```

One open request afterwards, 5,000 minor units reserved, the ledger
unchanged, zero lock waits. M7's one-open-request-at-a-time policy holds
under a simultaneous burst.

The rate limit is worth naming: at twenty per minute per identity, a
single seller cannot reach the domain race through HTTP faster than the
limiter allows. It is the first line of defence and it stayed on.

### Payout settlement

One approved payout, ten simultaneous settlements from admin sessions.

```
1 approved payout, 10 simultaneous settlements
  server errors   0
  p50=116ms p95=134ms max=144ms
```

```
                 before   after
status         approved    paid
payout debits         0       1
debited (minor)       0  -5,000
paid_at set          no     yes
```

**One debit, one transition to paid**, the remaining nine clean no-ops.
Zero lock waits. This is the moment money leaves the platform, and it
happened exactly once.

### Reference allocation, after the fix

Re-run of the checkout burst on the fixed code: five units on the shelf,
forty simultaneous checkouts from forty different customers.

```
shelf held 5 units
  orders placed   5
  refused        35
  rate limited    0
  server errors   0
```

Five units, five orders, thirty-five controlled refusals, no
five-hundreds and no leaked reservation. The original defect is covered
by `ReferenceAllocationTest`, which reproduces the losing caller's state
deterministically; it is not re-tested by re-breaking the code.

### Two generated-data defects that disabled a whole domain

Building the payout drills turned up two faults in the performance
dataset, both of which had been quietly making the payout domain
unexercisable at scale. Neither is an application defect; both matter
because a dataset that cannot reach a domain hides whatever is in it.

- **`payout_accounts.status = 'verified'`.** The model knows only
  `active` and `disabled`, and eligibility asks for `active`. Every
  generated seller was therefore ineligible to withdraw, with a message
  telling them to add a destination they already had.
- **`payout_accounts.type = 'bank_account'`.** `PayoutAccountType` has
  no such case. Casting the row threw a `ValueError`, so the seller
  finance page answered **500** for every generated seller.

Both are fixed in the generator and repaired in the performance database.
The 500 is worth noting beyond the fix: a backed-enum cast turns
unrecognised stored data into a hard failure. That is the right default —
silently coercing corrupt financial data would be worse — but it means a
bad import or migration surfaces as a broken page rather than a warning,
which belongs in the runbooks.

### Idempotency, confirmed sideways

The drill's second run placed no orders at all, which was correct
behaviour rather than a fault: it reused the previous run's idempotency
keys, and the endpoint replayed the recorded outcome instead of placing
a second order. Worth stating plainly because it is the property that
stops a customer's double-click becoming two orders.

## Sustained load

Twelve minutes at 25 virtual users — just under the knee — serving
**31,424 requests with no failures**.

| | 30-second level | 12-minute hold |
|---|---|---|
| req/s | 42.7 | 43.6 |
| p50 | 58 ms | 62 ms |
| p95 | 151 ms | 152 ms |
| p99 | 245 ms | 213 ms |
| worst | 399 ms | 557 ms |

**The number the application gives in the first minute is the number it
gives in the twelfth.** A p95 of 152 ms against the short level's 151 ms
is not a system that degrades under sustained load; it is the same system,
measured for longer. The resource picture, read in thirds, is in the
appendix — CPU, memory and connections flat, zero lock waits throughout,
and one line that climbed.

## Sustained load after the retention change

Eight minutes at 25 virtual users on the tuned configuration, with the
sampler's uncontended probe recording the latency trend directly rather
than leaving it to be inferred.

```
vus=25 rps=42.2 n=20,307 p50=76 p95=182 p99=250 max=677 fail=0.00%
```

```
              first third       middle   last third   drift
------------------------------------------------------------------
probe ms             79.5         74.1         77.6    -2%
cpu busy %           71.4         70.1         70.2    -2%
memory MB         1,823.9      1,847.4      1,868.0    +2%
pg conns             10.4         10.5         10.4    +1%
lock waits            0.0          0.0          0.0    +0%
redis MB             13.2         32.2         51.2  +289%
```

20,307 requests, **no failures**, and the probe latency ends where it
started. Zero lock waits for the whole hold. `failed_jobs` is empty and
every queue drained to zero — no queue loss. The payments queue stayed
responsive throughout: legitimate events delivered afterwards reached a
terminal state in 1.39 s on average.

Redis still climbs, and the climb is now fully explained rather than
alarming. The application queues 0.92 Horizon records per request; at
42.2 requests a second and 3.4 KB a record that is 39 records a second,
which predicts **62 MB over eight minutes against 58.6 MB observed**. The
same arithmetic gives the steady state the retention change bought:

| retention | plateau at this rate |
|---|---|
| 60 minutes (before) | ~464 MB |
| **30 minutes (now)** | **~232 MB** |

Eight minutes is a quarter of the thirty-minute window, so this run is
the window filling — the same phase the original soak mistook for
unbounded growth. That it plateaus is established separately and
deterministically, in the appendix.

## What should change, and when to scale

**Phase 1 should scale when sustained traffic approaches 25 concurrent
users on hardware of this size**, which is where the machine passes 60%
busy and the latency curve starts bending. That is a statement about this
box; on production hardware the same shape will appear at a different
number, and the honest way to find it is to run this harness there.

The first lever is not more machines. It is the connection cost below,
which is a configuration decision rather than an architectural one.

Nothing in this report justifies Kubernetes, microservices, a message
broker, a dedicated search cluster, read replicas or Octane. PostgreSQL
was idle at saturation; adding replicas would relieve a load that does
not exist.

## Findings

| | Classification |
|---|---|
| PostgreSQL per-request connection churn | **MEDIUM** — runtime/database connection efficiency |
| Horizon Redis retention | **CLOSED** — proven bounded, and tuned |
| Redis memory ceiling | **MEDIUM** — none configured; `noeviction` kept deliberately |
| Webhook duplicate amplification | **MEDIUM** — linear, measured not to starve payments |
| Idle Horizon workers on a small host | **MEDIUM** — size workers to the deployment |
| PDP SSR CI fixture | **CLOSED** — fixture now reaches the code it checks |
| Post-commit notification loss | **MEDIUM — unresolved**, carried forward |
| Production runtime | **Undecided** — benchmark is runtime-specific; validation required before launch |

No unresolved HIGH remains.


### MEDIUM — runtime and database connection efficiency

`pg_stat_database` recorded **1,956 new sessions while serving 2,098
requests** — approximately one process fork, backend initialisation,
authentication and teardown per request. Measured directly, that costs
**8.7 ms per request on an idle machine**, against a 58 ms p50 at the
knee: roughly 15% of a request spent connecting to a database it then
talks to for 0.14 ms. It also explains the accounting gap in the CPU
table, since those backends exit inside the sampling window.

**This is expected behaviour for the runtime it was measured on, not a
defect.** FrankenPHP was started in classic mode — `frankenphp php-server`,
no worker directive, no Caddyfile — so every request runs a complete PHP
request lifecycle and every object, PDO handle included, is destroyed at
the end of it. No `PDO::ATTR_PERSISTENT` is configured. Nginx with PHP-FPM
behaves identically. The application is not leaking connections: it opens
one per request, which is what the 0.93 sessions-per-request ratio says.

It is classified by launch impact rather than by optimisation potential,
and none of the launch triggers is present:

| Trigger | Measured |
|---|---|
| Connection exhaustion | 15–16 concurrent against `max_connections=100` |
| Failed latency targets | every surface inside budget at the supported level |
| Database CPU pressure | PostgreSQL 6.5% of one core; PHP 153% |
| Instability at supported load | 31,424 requests, zero failures, no drift |

So it is **not launch-blocking**, and it remains the largest single win
available. Revisit when any of these becomes true: the connection count
approaches half of `max_connections`; PostgreSQL CPU becomes comparable
to PHP's; p95 misses budget at the supported level with the database
implicated; or the deployment moves to a runtime where persistent
connections are safe. In that order, the options are FrankenPHP worker
mode, persistent PDO connections, and only then a pooler — and each needs
care, because the application sets session state (`statement_timeout`,
`lock_timeout`, the trigram thresholds) on every new connection, which a
reused connection would inherit rather than re-establish.

No pooler was introduced. The M9 brief rules that out for benchmark
improvement, and the measurements say it would not be earning its keep.

### The production runtime is not decided, and this benchmark is specific to one

The benchmark ran on FrankenPHP 1.12.7 in classic mode. Searching the
architecture and delivery material for what Phase 1 actually deploys
turns up **no commitment either way**: the deployment doc specifies
blue/green, health checks and draining workers, and the scale table
counts "app containers", but nothing names a web runtime. The repository's
only concrete runtime is `docker-compose.yml`, which runs
`php artisan serve` — the development server, and explicitly not a
capacity benchmark.

So there are not two contradictory topologies documented; there is a
decision that has not been made. Rather than fabricate equivalence
between runtimes, this report classifies its own numbers as
**environment- and runtime-specific**, and Phase 1 carries a launch
prerequisite: **a short production-topology validation on whatever
runtime is actually chosen**, re-running `ops/load/ramp.sh` and the
contention drills there. The shape of the curve should carry over; the
absolute numbers should not be assumed to.

### MEDIUM — duplicate webhook deliveries multiply work on the payments queue

Measured across a ladder of simultaneous duplicate deliveries of one
signed event. Every rung produced **exactly one durable provider event
row**, no rejections and no server errors.

| deliveries | requeued | event rows | job executions | drain |
|---|---|---|---|---|
| 1 | 0 | 1 | 1 | 2.3 s |
| 10 | 9 | 1 | 10 | 2.4 s |
| 30 | 29 | 1 | 30 | 2.6 s |
| 100 | 99 | 1 | 100 | 3.1 s |

**The amplification is linear, not the 3x first reported, and 99 of those
100 executions are near-free.** The endpoint requeues a duplicate only
while the event is still `received`; the first worker to arrive claims it
with a conditional `UPDATE`, and every later job finds nothing to claim
and returns after one indexed write that matches no rows. Drain time is
almost flat from 1 delivery to 100.

The 3x came from a different case: an event that *fails*. There, each
duplicate dispatch carries its own retry ladder (`tries = 8`, backoff
5/15/60/300/900s), so N duplicates become up to 8N executions — a
100-delivery storm of a failing event reached 300 attempts within the
observation window and would climb further.

**It does not starve legitimate payments.** With a 100-duplicate storm of
a failing event running, distinct new events were delivered and timed
from arrival to terminal state:

| | mean | worst |
|---|---|---|
| quiet queue | 2.68 s | 2.79 s |
| during the storm | **1.27 s** | 2.50 s |

New events were *faster* under the storm, because a busy queue keeps
workers warm while a quiet one pays the poll interval. The duplicates
themselves were not occupying workers: after their first failure the
exponential backoff moved them into the delayed set, and the ready queue
drained to zero.

So the behaviour is retained and classified MEDIUM on measured grounds
rather than changed. What is emphatically not restored is the
"already received, always 200, never requeue" behaviour this replaced:
that lost a genuinely paid customer's payment for ever when a Redis
dispatch failed after the event row committed. Revisit if provider retry
volumes or worker counts change materially, or if a failing event is ever
seen to delay a legitimate one.

### MEDIUM — 18 Horizon workers idle on a 4-core machine

The production supervisor set runs 18 worker processes here, costing 16%
of a core while doing nothing and holding memory. Worker counts should be
sized to the deployment target rather than fixed.

### CLOSED — Horizon's Redis retention is bounded, and now tuned

The original report called this HIGH and unbounded. That was wrong, and
the reason is worth stating: the soak ran for twelve minutes against a
sixty-minute retention window, so it only ever observed the window
*filling*. Growth with no plateau in sight is not the same as growth with
no plateau.

**Mechanism.** The trim listeners prune Horizon's index sets on the master
supervisor's loop, but they are not what reclaims the memory. Horizon
writes a hash per job and gives it a Redis TTL — `completed` minutes from
completion, `pending` from dispatch, `failed` for a failure. Redis
enforces the expiry itself.

**Evidence, two ways.** Inspecting live keys: **400 of 400 sampled job
records carried a TTL, none above 60 minutes**, spread across the window
by when each job ran; across the whole keyspace, 74,815 of 74,839 keys had
an expiry. Then a controlled run with retention shortened to two minutes:

| | records | completed index | Redis |
|---|---|---|---|
| before the workload | 10 | 0 | 2.1 MB |
| after 1,412 requests | 1,328 | 1,312 | 6.9 MB |
| +90 s | 1,216 | 1,312 | 6.2 MB |
| +2 m | 223 | 724 | 3.8 MB |
| +3 m 45 s | **14** | **0** | **3.0 MB** |

Records and index sets both returned to baseline on schedule. The answer
is **BOUNDED**: steady state is `job rate x retention x record size`, and
it plateaus rather than climbing.

**Tuned anyway.** At 3.4 KB per retained job and one job queued per page
view, an hour of retention at the measured ceiling is on the order of
840 MB of Redis holding monitoring data for a queue whose backlog is near
zero. `recent` and `completed` are now 30 minutes rather than 60, halving
the dominant term. They move together because `recent_jobs` indexes the
hashes, so a longer index than TTL would list jobs whose detail has
already expired. `pending` stays at 60 minutes — long enough to cover the
payments retry ladder, which is the longest thing an operator follows —
and failures keep the full week, because they are what gets investigated
and they are rare enough to afford it. All six values are now
environment-overridable, so a deployment can tune retention to its own
traffic without a code change.

Silencing the high-volume job was considered and rejected: Horizon's
`silenced` list changes which index a job lands in and still writes the
hash, so it would not save the memory.

### MEDIUM — Redis has no configured memory ceiling

`maxmemory` is 0 with `maxmemory-policy=noeviction`. With retention now
proven bounded, the steady state is predictable rather than open-ended —
but nothing enforces it, so a traffic spike, a retention change or an
unexpected job volume raises the plateau until the host objects.

**The policy stays `noeviction` deliberately.** An evicting policy would
let Redis silently discard queue entries, sessions and Horizon state to
stay under a limit — trading a loud, diagnosable failure for quiet
business corruption, on a store where the queue carries payments. If a
ceiling is set, it should be sized above the computed plateau and paired
with a memory alert, so the failure mode remains "writes are refused and
someone is paged" rather than "money quietly went missing". Redis
topology is otherwise unchanged; nothing measured here justifies
redesigning it.

### CLOSED — CI's SSR assertion can now see the failure it was written to catch

CI does assert that the product page server-renders, and it would not
have caught this one: the seeded product had no reviews, so the star
histogram that threw was skipped entirely. The assertion was real; its
fixture could not reach the code under test.

The demo catalogue now seeds published reviews at more than one star, and
the smoke assertions follow the truth: the structured data must claim the
rating the database supports, and five histogram rows must appear in the
server-rendered markup. Those two were previously asserted the other way
round — no rating may be claimed — which was correct for a product with
no reviews and is exactly how the blind spot arose. A feature test pins
the fixture so it cannot quietly regress.

### Carried forward, unchanged

A post-commit notification whose Redis dispatch fails before enqueue can
still be lost. It was not resolved during this block and is **not**
resolved by anything in it. It stays on the M9 register.

## The product page was not server-rendering

Found by the preflight, and worth its own section because it was a
customer-visible defect rather than a performance one.

The page sends the star histogram as a list of `{rating, count, percent}`
rows. The React component declared it as a map keyed by star and indexed
it that way. In JavaScript, indexing an array with `"4"` returns the
element at position 4 — a row object — and React refuses to render an
object as a child. **Every product page with a rating threw**, server-side
and in the browser alike.

Three things that should have caught it did not:

- **TypeScript** was satisfied, because the declared type described a map
  the server has never sent. A hand-written type that disagrees with the
  payload is not a check.
- **SSR** failed silently. Inertia falls back to client rendering when
  the render server errors, so the response was still a 200 with a
  correct-looking page shell — and the SSR process logged the exception
  where nothing was watching.
- **CI's own product-page SSR assertion** passed, because its seeded
  product has no reviews.

Fixed by reading the rows as rows and drawing the bars from the share the
server already computes. `ReviewSurfaceTest` now pins the payload shape,
so the TypeScript type and the contract have to move together.

## Running it

```
ops/load/stack.sh start                       # FrankenPHP, SSR, Horizon
php artisan veritas:seed-load-identities      # the identity and request pools
k6 run ops/load/k6/preflight.js               # prove the surfaces are real
ops/load/ramp.sh                              # the staged ramp
php ops/load/summarise.php                    # the table above

php artisan veritas:contention-drill --prepare --units=5
k6 run ops/load/k6/contention/oversell.js
php artisan veritas:contention-drill --verify

php artisan veritas:contention-drill --prepare-webhook
k6 run ops/load/k6/contention/duplicate-webhook.js
php artisan veritas:contention-drill --verify-webhook
```

Every command refuses to run against a database without the generated
dataset marker, and against production. The identity pool holds a working
password and is gitignored; it is never committed and never printed.

## Appendix: proving Horizon's retention is bounded

The original report called this unbounded and HIGH. It was neither, and
the mistake is instructive: a twelve-minute soak against a sixty-minute
retention window can only ever watch the window fill. Growth with no
plateau in sight is not growth with no plateau.

**What actually reclaims the memory.** Horizon's trim listeners run on the
master supervisor's loop and prune its index sets — `recent_jobs`,
`completed_jobs`, `pending_jobs`, `silenced_jobs`, `recent_failed_jobs`.
They do not delete the per-job hashes. Those carry a Redis TTL written
when the job is recorded: `completed` minutes from completion for a job
that succeeded, `pending` from dispatch for one still queued, `failed`
for one that did not. Redis enforces it.

**Method C — inspect what is actually stored.** Of 400 sampled job
records, **400 carried a TTL and none exceeded 60 minutes**, spread across
the window by when each job ran. Across the whole keyspace, 74,815 of
74,839 keys had an expiry.

**Method B — shorten the window and watch.** Retention set to two minutes
in the performance environment, then a controlled workload:

| | records | completed index | Redis |
|---|---|---|---|
| before the workload | 10 | 0 | 2.1 MB |
| after 1,412 requests | 1,328 | 1,312 | 6.9 MB |
| +90 s | 1,216 | 1,312 | 6.2 MB |
| ~+2 m | 223 | 724 | 3.8 MB |
| +3 m 45 s | **14** | **0** | **3.0 MB** |

Records and index sets both returned to baseline on schedule, and memory
with them. The hashes decay continuously because each expires on its own
clock; the index sets step down because the listener runs once a minute.
They converge.

**Verdict: BOUNDED.** Steady state is `job rate x retention x record
size`. Nothing was deleted by hand to produce this — the disappearance is
Redis expiring its own keys under the policy the configuration sets.

## Appendix: the sustained hold

Twelve minutes at 25 virtual users — just under the knee — with the
machine sampled every second and read in thirds, because an average over
the whole run hides exactly the drift a soak is asked about.

```
              first third       middle   last third   drift
------------------------------------------------------------------
cpu busy %           63.7         64.8         63.8    +0%
memory MB         2,012.0      2,066.8      2,077.2    +3%
pg conns             13.1         13.3         13.2    +1%
lock waits            0.0          0.0          0.0    +0%
redis MB            112.8        141.0        169.4   +50%
```

CPU, process memory and database connections were flat. Lock waits stayed
at zero for the entire hold. Nothing degraded, nothing accumulated, and
the only line that drifted is the one explained above.

The sampler now also records an uncontended probe request each second, so
later runs show the latency trend directly rather than inferring it from
flat CPU; this run predates that column.

## Gates

Run with the load stack stopped, so the suite used the test database and
not the performance one.

| Gate | Result |
|---|---|
| Pint | passed |
| PHPStan (Larastan, level 8) | passed, 0 errors |
| TypeScript | passed |
| ESLint | passed, 0 warnings |
| PHPUnit | 1,580 tests, 21,395 assertions, 0 failures |
| Prettier | passed |
| Vite build + SSR build | passed |

Reconciliations after the contention work, against the performance
database the drills mutated:

| | Result |
|---|---|
| `inventory:reconcile` | 28,170 balances; ledger, columns and holds agree |
| `finance:reconcile-sellers` | seller finance reconciles in USD |
| `reviews:reconcile-ratings` | every rating summary matches its reviews |
| `analytics:rebuild` | 361 rows recomputed, 2.5 s |
| `recommendations:rebuild` | 898 products scored, 0.5 s |

The suite is at **1,582 tests and 21,410 assertions**, six more than the
1,576 this block started from: three covering reference allocation under
a lost race, one pinning the rating histogram's payload shape, and two
pinning the CI smoke fixture so it keeps reaching the code it checks.

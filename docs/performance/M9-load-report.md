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

### Idempotency, confirmed sideways

The drill's second run placed no orders at all, which was correct
behaviour rather than a fault: it reused the previous run's idempotency
keys, and the endpoint replayed the recorded outcome instead of placing
a second order. Worth stating plainly because it is the property that
stops a customer's double-click becoming two orders.

## Sustained load

See `ops/load/.run/soak/` for the raw output of the twelve-minute hold at
25 virtual users, and the summary below.

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

### HIGH — a PostgreSQL backend is forked for every HTTP request

`pg_stat_database` recorded **1,956 new sessions while serving 2,098
requests** — approximately one process fork, backend initialisation,
authentication and teardown per request. Measured directly, that costs
**8.7 ms per request on an idle machine**, against a 58 ms p50 at the
knee: roughly 15% of a request spent connecting to a database it then
talks to for 0.14 ms.

It also explains the accounting gap in the CPU table. Those backends exit
within the sampling window, so their CPU disappears from per-process
attribution — 55% attributed against 93.6% actually busy.

The M9 brief rules out introducing a connection pooler for benchmark
improvement, and that rule is respected here: this is reported, not
implemented. It is the single largest available win and the first thing
to evaluate before adding hardware. Persistent connections would need
care, because the application sets session state (`statement_timeout`,
`lock_timeout`, the trigram thresholds) on each new connection.

### MEDIUM — duplicate webhook deliveries multiply work on the payments queue

Thirty concurrent deliveries of one event produced ninety job executions.
Correctness is unaffected — one event row, one claim, no double money —
but the amplification lands on the highest-priority queue during exactly
the incident that causes duplicate deliveries. A `WithoutOverlapping`
middleware keyed by the event id would collapse them; the stranded-event
replay command already covers the case where the surviving job then
fails. Not changed during a load-testing block because it alters the
retry semantics of the money path.

### MEDIUM — 18 Horizon workers idle on a 4-core machine

The production supervisor set runs 18 worker processes here, costing 16%
of a core while doing nothing and holding memory. Worker counts should be
sized to the deployment target rather than fixed.

### HIGH — Horizon's retained job records are unbounded by anything but time

Horizon keeps a record of every job for 60 minutes (`trim.recent`), and
the application queues one `RecordInteractionEvent` per page view. During
the soak that came to 43,812 records averaging 3.4 KB — **144 MB, 90% of
all Redis memory in use** — for a queue whose backlog stayed near zero.
At the measured ceiling of 68 requests per second, steady state is on the
order of 840 MB.

`maxmemory` is 0 with `maxmemory-policy=noeviction`, so nothing bounds
it: Redis grows until the host refuses, and then starts refusing writes,
which takes sessions, the cache and the queue with it. Both fixes are
configuration — size `maxmemory`, and shorten the retention to what an
operator actually looks back through.

### LOW — CI's SSR assertion cannot see the failure it was written to catch

CI does assert that the product page renders server-side, and it would
not have caught this one: the seeded product has no reviews, so the star
histogram that threw is skipped entirely. The assertion is real; its
fixture is too thin. Seeding a review into the demo catalogue would close
it, and belongs with the frontend work rather than here.

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

## Appendix: Redis growth under sustained load

Redis was the only thing that moved during a twelve-minute hold at 25
virtual users, and it moved a lot: 103 MB in the first third to 127 MB in
the last, +22% and still climbing when the run ended.

Measured by key prefix, **90% of it is Horizon's own bookkeeping**:

| | keys | average | total |
|---|---|---|---|
| Horizon job records | 43,812 | 3,437 B | **144 MB** |
| Sessions | 44,242 | 377 B | 16 MB |

Horizon's `trim.recent` is 60 minutes and the application queues a
`RecordInteractionEvent` for every page view, so it retains roughly
3.4 KB per request served for an hour. At the measured ceiling of 68
requests per second that is on the order of **840 MB of Redis held in
monitoring data alone** at steady state — for a queue whose actual
backlog stayed near zero throughout.

It is not a leak; the records expire. But it makes Redis memory a
function of `request rate x retention`, and no ceiling is configured to
bound it: `maxmemory` is 0 with `maxmemory-policy=noeviction`. Redis
grows until the host refuses, and `noeviction` means it then starts
refusing writes — taking sessions, the cache and the queue with it. Both
levers are configuration: size `maxmemory`, and shorten `trim.recent` to
what an operator actually looks back through.

**The session count is a generator artifact, not application behaviour.**
k6 resets a virtual user's cookie jar between iterations, so each
iteration started a new session. A client that keeps its cookies reuses
one — confirmed directly: three jarred requests came back with the same
CSRF token, so the same session. Sessions are 16 MB of the total and
would be far less in real traffic.

## Appendix: the sustained hold

Twelve minutes at 25 virtual users — just under the knee — with the
machine sampled every second and read in thirds, because an average over
the whole run hides exactly the drift a soak is asked about.

```
              first third       middle   last third   drift
------------------------------------------------------------------
cpu busy %           63.7         63.4         65.1   +2%
memory MB         1,985.6      2,023.2      2,048.4   +3%
pg conns             12.9         13.3         13.2   +2%
lock waits            0.0          0.0          0.0   +0%
redis MB            103.9        115.9        126.9   +22%
```

CPU, process memory and database connections were flat. Lock waits stayed
at zero for the entire hold. Nothing degraded, nothing accumulated, and
the only line that drifted is the one explained above.

The sampler now also records an uncontended probe request each second, so
later runs show the latency trend directly rather than inferring it from
flat CPU; this run predates that column.

# Runbook — backup and restore

No secrets in this document. Every command reads credentials from the
environment or from the application's `.env`.

## What is backed up

`ops/backup-database.sh` takes one PostgreSQL logical backup in custom
format, compressed, with `--no-owner --no-privileges` so it restores into
a database owned by whoever is restoring it. A backup that only restores
as its original role is a backup that fails at the worst moment.

A `.sha256` is written beside each dump. The restore verifies it before
touching anything.

**Object storage is not covered by this script.** Product media and
private seller documents live in R2 and are recovered through the
provider's own versioning. See "Object storage" below.

## Schedule and retention

Phase 1 is one daily backup. That is a deliberate floor, not an ideal: a
nightly dump loses at most a day, and a marketplace that has never
restored one has no backup at all however often it takes them.

Suggested retention: 7 daily, 4 weekly, 6 monthly. Store off the database
host — a backup on the machine that failed is not a backup.

Continuous archiving (WAL shipping / PITR) is the next step and is
recorded as INTENTIONAL-DEFERRED for Phase 1.

    ops/backup-database.sh /var/backups/veritas

Prints `backup_artifact`, `backup_bytes`, `backup_sha256`,
`backup_database` and `backup_taken_at` for a scheduler to capture.

## Restoring

    ops/restore-drill.sh /var/backups/veritas/veritas-…​.dump veritas_restore_check

The script:

1. verifies the dump against its checksum;
2. drops and recreates the **target** database — it never writes to the
   source;
3. restores with `--exit-on-error`, so a restore that would silently skip
   a constraint fails instead;
4. counts tables, migrations and the representative aggregates;
5. fingerprints the money — captured payments, ledger, commission,
   payouts, stock, and an md5 over order and ledger rows in id order —
   and compares it with the source when the source is still reachable.

Row counts prove the restore ran. The fingerprint proves it restored the
same marketplace: a lost ledger row, or one restored with a different
amount, changes it even when every count still matches.

When the source is gone — the case this exists for — the comparison is
skipped and reported as `financial_truth=source_unreachable_not_compared`
rather than failing.

## After a real restore

Run all three reconciliations against the restored database before it
serves anybody:

    DB_DATABASE=<restored> php artisan inventory:reconcile
    DB_DATABASE=<restored> php artisan finance:reconcile-sellers
    DB_DATABASE=<restored> php artisan reviews:reconcile-ratings

All three must exit zero. They **detect and refuse to repair** — a
finance discrepancy after a restore is a person's decision, never a
script's.

Then rebuild the derived projections, which are disposable by design:

    DB_DATABASE=<restored> php artisan analytics:rebuild --days=30 --verify
    DB_DATABASE=<restored> php artisan recommendations:rebuild --verify

Finally, before cutting traffic over:

    DB_DATABASE=<restored> php artisan app:production-check

## Object storage

Product media and private seller documents are not in the database dump.

- **Public media** — a lost object degrades a listing; it does not lose
  money. Recover from R2 object versioning.
- **Private seller documents** — KYC evidence for an approved seller.
  Recover from R2 object versioning. These must never be restored into a
  public bucket.

R2 recovery has not been exercised in this environment; see the M9 report
under ENVIRONMENT-UNVERIFIED.

## The destructive-command guard

This section exists because M9 lost the development database, and the way
it happened is worth reading before you trust any flag.

The command was `php artisan migrate:fresh --seed --env=testing`. The
intent was to rebuild the PHPUnit database. There was no `.env.testing`
in the repository at the time, so `--env=testing` fell back to `.env` and
resolved to the development database, which was dropped. Three
reconciliations were then run against an empty database and reported
nothing wrong, because there was nothing left to be wrong.

PHPUnit gets its database from `phpunit.xml`. An ordinary artisan command
does not inherit that. **`--env=testing` is not evidence of anything.**

Three things now stand between that mistake and the next person.

**`.env.testing` is committed.** The flag now resolves where people
assume. `DestructiveDatabaseGuardTest` asserts it names the same database
`phpunit.xml` does, so the two cannot drift apart again.

**Destructive artisan commands announce their target.** Before
`migrate:fresh`, `migrate:refresh` or `db:wipe` runs, it prints the
database, host, connection and `APP_ENV` it is about to act on:

```
Destructive command targeting database "veritas" on 127.0.0.1:5432 (connection pgsql, APP_ENV local).
```

Announcing is most of the protection. Nobody misreads a database name
they were shown.

**Protected databases refuse outright.** `APP_ENV=production` (or `prod`,
or `live`) is refused unconditionally. Anything named in
`VERITAS_PROTECTED_DATABASES` — comma-separated, read through config so
it survives `config:cache` — is refused too. `ops/restore-drill.sh`
applies the same list, and additionally refuses to restore into the
database it read from, or into a target whose name reads like production.

`migrate:rollback` is deliberately not guarded: it is bounded, the
operator knows which batch they are undoing, and putting an override flag
into routine deployment recovery would only teach people to type the
override without reading it.

### The override

```
VERITAS_ALLOW_DESTRUCTIVE_DB=1 php artisan migrate:fresh
```

Set it inline, on the single command that means it. Never export it into
a shell profile, a Dockerfile or a CI job — a permanently-set override is
the same as no guard, and it will be set by someone in a hurry and
forgotten by everyone.

### If you are adding a destructive command

Add its name to `DestructiveDatabaseGuard::DESTRUCTIVE_COMMANDS`. And be
aware of the framework behaviour the guard has to work around, because it
is not obvious and it silently disables listeners: Laravel's console
kernel only re-routes Symfony's command events to `CommandStarting` when
`runningUnitTests()` is false, and `runningUnitTests()` means no more than
"`APP_ENV` is testing". Left alone, the guard against the `--env=testing`
accident was itself switched off by `--env=testing`.
`AppServiceProvider::ensureCommandEventsAreDispatched()` restores the
reroute for CLI runs only, and
`DestructiveDatabaseGuardTest::the_guard_is_actually_wired_on_the_command_line`
runs a real subprocess to prove it — an in-process test that dispatches
the event by hand proves the handler and nothing about whether anything
calls it.

## What never to do

- Never restore a backup **over** the production database to "check it".
  Restore into a fresh database and compare.
- Never edit a ledger, payment or payout row by hand to make a
  reconciliation pass. The reconciliation is the alarm, not the problem.
- Never skip the post-restore reconciliation because the restore
  "obviously worked". It has been wrong before, in every organisation
  that has said it.
- Never export `VERITAS_ALLOW_DESTRUCTIVE_DB` anywhere persistent. Inline,
  once, on the command that means it.
- Never trust `--env` as proof of which database you are pointed at. Read
  the line the command prints.

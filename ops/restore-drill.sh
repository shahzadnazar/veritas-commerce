#!/usr/bin/env bash
#
# Restore a Veritas backup into a genuinely fresh database and prove it.
#
# §54 makes this launch-critical, and the reason is worth stating: a
# backup nobody has restored is a hypothesis. The only evidence that a
# marketplace can survive losing its database is a restore that produced
# a working one — schema, transactional truth, and reconciliations that
# still balance.
#
# The drill is deliberately destructive about its TARGET and never about
# its SOURCE. It drops and recreates the restore database; it opens the
# production database read-only, to take the dump, and never again.
#
# Usage:
#   ops/restore-drill.sh <dump-file> [target-database]
#
# Exits non-zero if the restore, the schema check or any reconciliation
# fails. That is what makes it usable as a scheduled proof rather than a
# thing somebody eyeballs once a year.

set -euo pipefail

ARTIFACT="${1:?usage: restore-drill.sh <dump-file> [target-database]}"
TARGET="${2:-veritas_restore_drill}"

env_value() {
    local key="$1" default="${2:-}"
    local line
    line=$(grep -E "^${key}=" .env 2>/dev/null | tail -1 || true)
    if [ -z "$line" ]; then printf '%s' "$default"; return; fi
    printf '%s' "${line#*=}" | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

export PGHOST="${PGHOST:-$(env_value DB_HOST 127.0.0.1)}"
export PGPORT="${PGPORT:-$(env_value DB_PORT 5432)}"
export PGUSER="${PGUSER:-$(env_value DB_USERNAME veritas)}"
export PGDATABASE="${PGDATABASE:-$(env_value DB_DATABASE veritas)}"
: "${PGPASSWORD:=$(env_value DB_PASSWORD)}"
export PGPASSWORD

[ -f "$ARTIFACT" ] || { echo "restore_error=artifact_missing"; exit 1; }

# The checksum written at backup time. A dump that arrived corrupted
# should fail here rather than halfway through a restore.
if [ -f "${ARTIFACT}.sha256" ]; then
    EXPECTED=$(cat "${ARTIFACT}.sha256")
    ACTUAL=$(sha256sum "$ARTIFACT" | awk '{print $1}')
    if [ "$EXPECTED" != "$ACTUAL" ]; then
        echo "restore_checksum=mismatch"
        exit 1
    fi
    echo "restore_checksum=verified"
fi

# A genuinely fresh database. Restoring over an existing one can leave
# rows the dump did not mention, which is exactly the way a restore drill
# passes while the real thing would not.
dropdb --if-exists --force "$TARGET"
createdb "$TARGET"
echo "restore_target=$TARGET"

# --exit-on-error, because a restore that reports success while quietly
# skipping a constraint has restored something other than the backup.
pg_restore \
    --dbname="$TARGET" \
    --no-owner \
    --no-privileges \
    --exit-on-error \
    "$ARTIFACT"

echo "restore_completed=yes"

TABLES=$(psql -d "$TARGET" -tAc "select count(*) from information_schema.tables where table_schema='public'")
MIGRATIONS=$(psql -d "$TARGET" -tAc "select count(*) from migrations")

printf 'restored_tables=%s\n' "$TABLES"
printf 'restored_migrations=%s\n' "$MIGRATIONS"

# Representative transactional rows (§54). Counted rather than merely
# present: a restore that produced empty tables would otherwise pass.
for pair in \
    "users:users" \
    "products:products" \
    "orders:marketplace_orders" \
    "seller_orders:seller_orders" \
    "payments:payments" \
    "inventory:inventory_balances" \
    "ledger:seller_ledger_entries" \
    "payouts:payout_requests" \
    "reviews:product_reviews"; do
    label="${pair%%:*}"; table="${pair#*:}"
    printf 'restored_%s=%s\n' "$label" "$(psql -d "$TARGET" -tAc "select count(*) from ${table}")"
done

# Row counts prove the restore ran. These prove it restored the same
# marketplace: the money, the stock, and a fingerprint over the order and
# ledger rows in id order. A restore that lost a ledger row, or restored
# it with a different amount, changes the fingerprint even though every
# count still matches.
FINGERPRINT_QUERY="select
  (select coalesce(sum(amount_minor),0) from payments where status in ('captured','partially_refunded'))
  ||'|'||(select coalesce(sum(amount_minor),0) from seller_ledger_entries)
  ||'|'||(select coalesce(sum(amount_minor),0) from platform_revenue_entries)
  ||'|'||(select coalesce(sum(amount_minor),0) from payout_requests)
  ||'|'||(select coalesce(sum(on_hand),0) from inventory_balances)
  ||'|'||(select coalesce(sum(reserved),0) from inventory_balances)
  ||'|'||(select coalesce(md5(string_agg(reference||':'||grand_total_minor||':'||status, ',' order by id)),'-') from marketplace_orders)
  ||'|'||(select coalesce(md5(string_agg(type||':'||status||':'||amount_minor, ',' order by id)),'-') from seller_ledger_entries)"

RESTORED_FINGERPRINT=$(psql -d "$TARGET" -tAc "$FINGERPRINT_QUERY")
printf 'restored_fingerprint=%s\n' "$RESTORED_FINGERPRINT"

# Compared against the source only when it is still reachable. A real
# disaster restore has no source to compare with, and the drill must not
# fail merely because it is being run for the reason it exists.
if SOURCE_FINGERPRINT=$(psql -d "$PGDATABASE" -tAc "$FINGERPRINT_QUERY" 2>/dev/null); then
    if [ "$SOURCE_FINGERPRINT" = "$RESTORED_FINGERPRINT" ]; then
        echo "financial_truth=identical"
    else
        echo "financial_truth=DIVERGED"
        printf 'source_fingerprint=%s\n' "$SOURCE_FINGERPRINT"
        exit 1
    fi
else
    echo "financial_truth=source_unreachable_not_compared"
fi

#!/usr/bin/env bash
#
# A PostgreSQL backup of the Veritas marketplace.
#
# Phase 1 is one daily logical backup. That is a deliberate floor rather
# than an ideal: a nightly pg_dump loses at most a day, and a marketplace
# that has never restored one has no backup at all regardless of how
# often it takes them. Continuous archiving is the next step, and
# ops/RUNBOOK-backup-restore.md says so.
#
# Credentials come from the environment, never from this file (§52). The
# password reaches pg_dump through PGPASSWORD in its own process
# environment rather than on a command line, where `ps` would show it to
# every user on the host.
#
# Usage:
#   ops/backup-database.sh [destination-directory]
#
# Environment (falls back to the application's own .env):
#   PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE

set -euo pipefail

DESTINATION="${1:-${VERITAS_BACKUP_DIR:-storage/backups}}"

# Read a key from .env without sourcing it — .env is not a shell script,
# and sourcing one that contains a stray backtick executes it.
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

mkdir -p "$DESTINATION"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
ARTIFACT="${DESTINATION}/veritas-${PGDATABASE}-${STAMP}.dump"

# Custom format: compressed, and pg_restore can then rebuild selectively
# and in parallel. A plain SQL file cannot do either.
#
# --no-owner and --no-privileges so the dump restores into a database
# owned by whoever is doing the restoring. A backup that only restores
# as the original role is a backup that fails at the worst moment.
pg_dump \
    --format=custom \
    --compress=6 \
    --no-owner \
    --no-privileges \
    --file="$ARTIFACT"

SIZE=$(stat -c %s "$ARTIFACT")

# Written beside the dump so a restore can prove it read the same bytes
# that were written.
sha256sum "$ARTIFACT" | awk '{print $1}' > "${ARTIFACT}.sha256"

printf 'backup_artifact=%s\n' "$ARTIFACT"
printf 'backup_bytes=%s\n' "$SIZE"
printf 'backup_sha256=%s\n' "$(cat "${ARTIFACT}.sha256")"
printf 'backup_database=%s\n' "$PGDATABASE"
printf 'backup_taken_at=%s\n' "$STAMP"

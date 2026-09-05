#!/usr/bin/env bash
# Samples the machine while a load level runs.
#
# A latency number without the resource picture beside it cannot say why
# it degraded. This writes one CSV row a second: load average, the four
# cores' idle share, PostgreSQL's connection count and its longest
# running query, and Redis's memory and blocked-client count.
#
#   ops/load/sample.sh <output.csv> &
#   ... run the level ...
#   kill %1
set -uo pipefail

OUT="${1:?usage: sample.sh <output.csv>}"
DB="${LOAD_DB:-veritas_perf}"

# The same credentials the application uses; there is no superuser here
# and pg_stat_activity does not need one for this database's own rows.
export PGHOST="${DB_HOST:-127.0.0.1}"
export PGPORT="${DB_PORT:-5432}"
export PGUSER="${DB_USERNAME:-veritas}"
export PGPASSWORD="${DB_PASSWORD:-veritas}"

echo 'at,load1,cpu_idle_pct,mem_used_mb,pg_conns,pg_active,pg_longest_s,pg_waiting,redis_mem_mb,redis_blocked,probe_ms' >"$OUT"

previous_idle=0
previous_total=0

while true; do
    read -r _ user nice system idle iowait irq softirq steal _ </proc/stat
    total=$((user + nice + system + idle + iowait + irq + softirq + steal))
    idle_delta=$((idle - previous_idle))
    total_delta=$((total - previous_total))
    previous_idle=$idle
    previous_total=$total

    cpu_idle=0
    if [ "$total_delta" -gt 0 ]; then
        cpu_idle=$((100 * idle_delta / total_delta))
    fi

    load1=$(cut -d' ' -f1 /proc/loadavg)
    mem_used=$(free -m | awk '/^Mem:/ {print $3}')

    pg=$(psql -qtAX -d "$DB" -F, -c "
        select count(*),
               count(*) filter (where state = 'active'),
               coalesce(round(max(extract(epoch from now() - query_start)) filter (where state = 'active')), 0),
               count(*) filter (where wait_event_type = 'Lock')
        from pg_stat_activity
        where datname = '$DB';" 2>/dev/null || echo ',,,')

    redis_mem=$(redis-cli info memory 2>/dev/null | awk -F: '/^used_memory:/ {printf "%.1f", $2/1048576}')
    redis_blocked=$(redis-cli info clients 2>/dev/null | awk -F: '/^blocked_clients:/ {print $2+0}')

    # One un-contended request a second, so a sustained hold can show
    # whether the latency it started with is the latency it ends with.
    # It is a single request rather than a percentile: the generator owns
    # the distribution, this owns the trend.
    probe=$(curl -s -o /dev/null -w '%{time_total}' --max-time 10 "${LOAD_PROBE_URL:-http://127.0.0.1:8080/}" 2>/dev/null || echo 0)
    probe_ms=$(awk "BEGIN{printf \"%.0f\", ${probe:-0} * 1000}")

    printf '%s,%s,%s,%s,%s,%s,%s,%s\n' \
        "$(date -u +%H:%M:%S)" "$load1" "$cpu_idle" "$mem_used" "$pg" "${redis_mem:-}" "${redis_blocked:-}" "$probe_ms" \
        | tr -d '\r' >>"$OUT"

    sleep 1
done

#!/usr/bin/env bash
# The staged ramp: one level at a time, with the machine watched.
#
# Each level runs the mixed browse workload at a fixed virtual-user count
# for a steady-state hold, with a cooldown between levels so the next one
# does not start inside the previous one's queue. Latency and the
# resource picture are written per level, and the summary table is built
# from them by ops/load/summarise.php.
#
#   ops/load/ramp.sh                    # the default 1..200 ladder
#   LOAD_LEVELS='1 10 25' ops/load/ramp.sh
#
# It stops early if a level fails more than a fifth of its requests:
# past that the application is not serving traffic and the next level up
# would only be a more expensive way to learn the same thing.
set -euo pipefail

cd "$(dirname "$0")/../.."

LEVELS="${LOAD_LEVELS:-1 10 25 50 100 150 200}"
DURATION="${LOAD_DURATION:-30s}"
COOLDOWN="${LOAD_COOLDOWN:-10}"
SCRIPT="${LOAD_SCRIPT:-ops/load/k6/browse-mix.js}"
RUN="ops/load/.run/ramp"

mkdir -p "$RUN"

if [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/up)" != '200' ]; then
    echo 'The stack is not answering. Run ops/load/stack.sh start first.' >&2
    exit 1
fi

# A dirty preflight means the numbers would describe the wrong pages.
if ! k6 run --quiet ops/load/k6/preflight.js >"${RUN}/preflight.txt" 2>&1; then
    echo 'Preflight failed; refusing to ramp. See ops/load/.run/ramp/preflight.txt' >&2
    exit 1
fi

for vus in $LEVELS; do
    echo "=== ${vus} VUs for ${DURATION}"

    LOAD_DB=veritas_perf ops/load/sample.sh "${RUN}/resources-${vus}.csv" &
    sampler=$!

    LOAD_VUS="$vus" LOAD_DURATION="$DURATION" LOAD_OUT="${RUN}/level-${vus}.json" \
        k6 run --quiet "$SCRIPT" | tee "${RUN}/level-${vus}.txt"

    kill "$sampler" 2>/dev/null || true
    wait "$sampler" 2>/dev/null || true

    failed=$(grep -o 'fail=[0-9.]*' "${RUN}/level-${vus}.txt" | cut -d= -f2)

    if [ "${failed%%.*}" -ge 20 ]; then
        echo "Stopping the ramp: ${failed}% of requests failed at ${vus} VUs."
        break
    fi

    sleep "$COOLDOWN"
done

echo
echo 'Levels written to ops/load/.run/ramp/. Summarise with: php ops/load/summarise.php'

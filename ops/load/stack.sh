#!/usr/bin/env bash
#
# Bring up a production-shaped local stack for load testing, and take it
# down again.
#
# The point of this script is that a load result is only as trustworthy as
# the runtime it was measured on. `php artisan serve` is a single-process
# development server; benchmarking it and calling the number capacity
# would be measuring PHP's toy web server rather than the application. So
# this runs the app the way production runs it — a real HTTP server with a
# worker pool, opcache on, configuration and routes cached, production
# frontend assets, SSR live and Horizon draining the queues.
#
# It points at the performance database, never the development or PHPUnit
# one, and it restores the caches it changed when it stops.
#
#   ops/load/stack.sh start
#   ops/load/stack.sh status
#   ops/load/stack.sh stop
#
# FRANKENPHP is the one thing not vendored here: it is a 170 MB static
# binary and does not belong in the repository. Download it once with
#
#   curl -sSL -o /tmp/frankenphp \
#     https://github.com/php/frankenphp/releases/latest/download/frankenphp-linux-x86_64
#   chmod +x /tmp/frankenphp
#
# and set FRANKENPHP_BIN if you put it elsewhere.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUN="${ROOT}/ops/load/.run"
FRANKENPHP_BIN="${FRANKENPHP_BIN:-/tmp/frankenphp}"
APP_PORT="${LOAD_APP_PORT:-8080}"
SSR_PORT="${LOAD_SSR_PORT:-13714}"
DB="${LOAD_DB:-veritas_perf}"

mkdir -p "${RUN}"

# The environment the whole stack shares. Written to a file rather than
# exported ad hoc so the artisan calls that build the caches and the
# server that reads them cannot disagree about which database this is.
env_file() {
    cat <<ENV
APP_ENV=production
APP_DEBUG=false
APP_URL=http://127.0.0.1:${APP_PORT}
DB_DATABASE=${DB}
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
INERTIA_SSR_ENABLED=${LOAD_SSR:-true}
INERTIA_SSR_URL=http://127.0.0.1:${SSR_PORT}
PAYMENT_GATEWAY=fake
HORIZON_TRIM_RECENT=${LOAD_TRIM:-30}
HORIZON_TRIM_COMPLETED=${LOAD_TRIM:-30}
ENV
}

load_env() {
    while IFS='=' read -r key value; do
        [ -n "${key}" ] && export "${key}=${value}"
    done < <(env_file)
}

refuse_wrong_database() {
    if [ "${DB}" = "veritas" ] || [ "${DB}" = "veritas_test" ]; then
        echo "Refusing to load-test against \"${DB}\". Use the performance database." >&2
        exit 1
    fi
}

start() {
    refuse_wrong_database
    load_env

    if [ ! -x "${FRANKENPHP_BIN}" ]; then
        echo "FrankenPHP not found at ${FRANKENPHP_BIN}. See the header of this script." >&2
        exit 1
    fi

    if [ ! -f "${ROOT}/public/build/manifest.json" ]; then
        echo "Production assets are missing. Run: npm run build && npm run build:ssr" >&2
        exit 1
    fi

    cd "${ROOT}"

    # Caches built with the load environment in them, so the running
    # server cannot quietly resolve a different database than the one
    # this script announced.
    php artisan config:cache >/dev/null
    php artisan route:cache >/dev/null

    echo "Serving ${DB} on http://127.0.0.1:${APP_PORT}"

    node bootstrap/ssr/ssr.js >"${RUN}/ssr.log" 2>&1 &
    echo $! >"${RUN}/ssr.pid"

    php artisan horizon >"${RUN}/horizon.log" 2>&1 &
    echo $! >"${RUN}/horizon.pid"

    "${FRANKENPHP_BIN}" php-server \
        --listen "127.0.0.1:${APP_PORT}" \
        --root "${ROOT}/public" \
        >"${RUN}/app.log" 2>&1 &
    echo $! >"${RUN}/app.pid"

    sleep 5
    status
}

stop() {
    for name in app ssr horizon; do
        if [ -f "${RUN}/${name}.pid" ]; then
            pid="$(cat "${RUN}/${name}.pid")"
            kill "${pid}" 2>/dev/null || true
            rm -f "${RUN}/${name}.pid"
        fi
    done

    # Horizon's workers are children; the master's SIGTERM is graceful but
    # slow, and a load run that started a second stack on top of the first
    # would measure both.
    pkill -f 'artisan horizon' 2>/dev/null || true
    pkill -f 'bootstrap/ssr/ssr.js' 2>/dev/null || true
    pkill -f 'frankenphp php-server' 2>/dev/null || true

    cd "${ROOT}"
    php artisan config:clear >/dev/null 2>&1 || true
    php artisan route:clear >/dev/null 2>&1 || true

    echo "Stack stopped, caches cleared."
}

status() {
    printf '%-10s %s\n' 'app' "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${APP_PORT}/health/live" || echo 'down')"
    printf '%-10s %s\n' 'ready' "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${APP_PORT}/health/ready" || echo 'down')"
    printf '%-10s %s\n' 'ssr' "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${SSR_PORT}/health" || echo 'no /health')"
    printf '%-10s %s\n' 'horizon' "$(pgrep -fc 'artisan horizon' || echo 0) process(es)"
}

case "${1:-}" in
    start) start ;;
    stop) stop ;;
    status) status ;;
    *) echo "usage: $0 {start|stop|status}" >&2; exit 1 ;;
esac

// One approved payout, several admins pressing "settle" at once.
//
// A settlement is the moment money leaves the platform: it writes the
// debit that reduces the seller's balance and moves the request to paid.
// Both must happen exactly once however many operators, tabs or retries
// arrive together.
//
//   php artisan veritas:contention-drill --prepare-settlement
//   k6 run ops/load/k6/contention/payout-settlement.js
//   php artisan veritas:contention-drill --verify-settlement
import http from 'k6/http';
import { fail } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { sleep } from 'k6';
import { BASE, pool, assigned } from '../lib/pool.js';
import { signInAdmin } from '../lib/session.js';

const drill = JSON.parse(open(__ENV.LOAD_DRILL || '../../.run/settlement.json'));

const settled = new Counter('settlement_settled');
const refused = new Counter('settlement_refused');
const errored = new Counter('settlement_errored');
const latency = new Trend('settlement_duration', true);

export const options = {
    scenarios: {
        burst: {
            executor: 'per-vu-iterations',
            vus: Number(__ENV.LOAD_VUS || 10),
            iterations: 1,
            maxDuration: '2m',
        },
    },
    summaryTrendStats: ['count', 'min', 'med', 'p(95)', 'max'],
    thresholds: { settlement_errored: ['count==0'] },
};

export default function settle() {
    const jar = signInAdmin(Object.assign({}, assigned(pool.admins), { password: pool.password }));

    const page = http.get(`${BASE}/admin/payouts/${drill.reference}`, { jar });
    const token = (page.body.match(/name="csrf-token" content="([^"]+)"/) || [])[1];

    if (!token) {
        fail(`No CSRF token on ${page.url} (HTTP ${page.status}); the burst would post blind.`);
    }

    const barrier = Math.ceil(Date.now() / 5000) * 5000;
    const wait = (barrier - Date.now()) / 1000;

    if (wait > 0) {
        sleep(wait);
    }

    const response = http.post(
        `${BASE}/admin/payouts/${drill.reference}/settle`,
        {
            method: 'bank_transfer',
            // Distinct per virtual user: a shared settlement reference would
            // be testing the idempotency key rather than the transition.
            reference: `drill-${__VU}-${Date.now()}`,
            _token: token,
        },
        { jar, tags: { name: 'payout:settle' } },
    );

    latency.add(response.timings.duration);

    if (__ENV.LOAD_EXPLAIN && __VU === 1) {
        const e = response.body.match(/"errors":\s*\{[^}]*\}/);
        console.log(`status=${response.status} url=${response.url} errors=${e ? e[0] : 'none'}`);
    }

    if (response.status >= 500) {
        errored.add(1);
        console.error(`VU ${__VU}: ${response.status}`);
    } else if (/"errors":\s*\{[^{}]*"[a-z_]+"/.test(response.body)) {
        refused.add(1);
    } else {
        settled.add(1);
    }
}

export function handleSummary(data) {
    const count = (n) => (data.metrics[n] ? data.metrics[n].values.count : 0);
    const d = data.metrics.settlement_duration.values;

    return {
        stdout:
            `\n1 approved payout, ${__ENV.LOAD_VUS || 10} simultaneous settlements\n` +
            `  looked settled  ${count('settlement_settled')}\n` +
            `  refused         ${count('settlement_refused')}\n` +
            `  server errors   ${count('settlement_errored')}\n` +
            `  p50=${d.med.toFixed(0)}ms p95=${d['p(95)'].toFixed(0)}ms max=${d.max.toFixed(0)}ms\n`,
    };
}

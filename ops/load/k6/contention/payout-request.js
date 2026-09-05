// One seller, one balance, many simultaneous payout requests.
//
// M7 policy allows a seller one open request at a time, and no request may
// reserve more than the balance the ledger actually holds. Both rules are
// enforced under a lock; this checks the lock rather than the rule.
//
//   php artisan veritas:contention-drill --prepare-payout
//   k6 run ops/load/k6/contention/payout-request.js
//   php artisan veritas:contention-drill --verify-payout
import http from 'k6/http';
import { fail } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { sleep } from 'k6';
import { BASE, pool } from '../lib/pool.js';
import { signInSeller } from '../lib/session.js';

const drill = JSON.parse(open(__ENV.LOAD_DRILL || '../../.run/payout.json'));
const AMOUNT = String(__ENV.LOAD_AMOUNT || 5000);

const opened = new Counter('payout_opened');
const refused = new Counter('payout_refused');
const errored = new Counter('payout_errored');
const latency = new Trend('payout_duration', true);

export const options = {
    scenarios: {
        burst: {
            executor: 'per-vu-iterations',
            vus: Number(__ENV.LOAD_VUS || 20),
            iterations: 1,
            maxDuration: '2m',
        },
    },
    summaryTrendStats: ['count', 'min', 'med', 'p(95)', 'max'],
    thresholds: { payout_errored: ['count==0'] },
};

export default function request() {
    const jar = signInSeller({ email: drill.seller_email, password: pool.password });

    const page = http.get(`${BASE}/seller/payouts`, { jar });
    const token = (page.body.match(/name="csrf-token" content="([^"]+)"/) || [])[1];

    if (!token) {
        // Posting without one is a 419, which looks like a refusal and is
        // not one. Better to stop than to record a made-up result.
        fail(`No CSRF token on ${page.url} (HTTP ${page.status}); the burst would post blind.`);
    }

    const barrier = Math.ceil(Date.now() / 5000) * 5000;
    const wait = (barrier - Date.now()) / 1000;

    if (wait > 0) {
        sleep(wait);
    }

    const response = http.post(
        `${BASE}/seller/payouts`,
        { amount_minor: AMOUNT, _token: token },
        { jar, tags: { name: 'payout:request' } },
    );

    latency.add(response.timings.duration);

    if (__ENV.LOAD_EXPLAIN && __VU === 1) {
        const e = response.body.match(/"errors":\s*\{[^}]*\}/);
        console.log(`status=${response.status} url=${response.url} errors=${e ? e[0] : 'none'}`);
    }

    if (response.status >= 500) {
        errored.add(1);
        console.error(`VU ${__VU}: ${response.status}`);
    } else if (response.body.includes('"errors":{"payout') || response.body.includes('"errors":{"amount')) {
        refused.add(1);
    } else {
        opened.add(1);
    }
}

export function handleSummary(data) {
    const count = (n) => (data.metrics[n] ? data.metrics[n].values.count : 0);
    const d = data.metrics.payout_duration.values;

    return {
        stdout:
            `\n${__ENV.LOAD_VUS || 20} simultaneous payout requests of ${AMOUNT} minor units\n` +
            `  looked accepted ${count('payout_opened')}\n` +
            `  refused         ${count('payout_refused')}\n` +
            `  server errors   ${count('payout_errored')}\n` +
            `  p50=${d.med.toFixed(0)}ms p95=${d['p(95)'].toFixed(0)}ms max=${d.max.toFixed(0)}ms\n` +
            `  (the count that matters is the verify step's open-request total)\n`,
    };
}

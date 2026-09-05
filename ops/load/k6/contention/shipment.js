// One unfulfilled unit, twenty sellers' tabs allocating it at once.
//
// Fulfilment is where a marketplace can promise the same physical item to
// two customers. The domain refuses to allocate more units than the order
// holds; this checks that the refusal survives twenty simultaneous
// attempts rather than only sequential ones.
//
//   php artisan veritas:contention-drill --prepare-shipment
//   k6 run ops/load/k6/contention/shipment.js
//   php artisan veritas:contention-drill --verify-shipment
import http from 'k6/http';
import { fail } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { BASE, pool } from '../lib/pool.js';
import { signInSeller } from '../lib/session.js';

const drill = JSON.parse(open(__ENV.LOAD_DRILL || '../../.run/shipment.json'));

const created = new Counter('shipment_created');
const refused = new Counter('shipment_refused');
const errored = new Counter('shipment_errored');
const latency = new Trend('shipment_duration', true);

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
    thresholds: { shipment_errored: ['count==0'] },
};

export default function allocate() {
    const jar = signInSeller({ email: drill.seller_email, password: pool.password });

    const page = http.get(`${BASE}/seller/orders/${drill.reference}`, { jar });
    const token = (page.body.match(/name="csrf-token" content="([^"]+)"/) || [])[1];

    if (!token) {
        // Posting without one is a 419, which looks like a refusal and is
        // not one. Better to stop than to record a made-up result.
        fail(`No CSRF token on ${page.url} (HTTP ${page.status}); the burst would post blind.`);
    }

    // Everyone posts on the same second boundary, so the race is between
    // the allocations rather than between the sign-ins.
    const barrier = Math.ceil(Date.now() / 5000) * 5000;
    waitUntil(barrier);

    const response = http.post(
        `${BASE}/seller/orders/${drill.reference}/shipments`,
        {
            'lines[0][order_item_id]': String(drill.order_item_id),
            'lines[0][quantity]': '1',
            carrier: 'Drill Carrier',
            _token: token,
        },
        { jar, tags: { name: 'shipment:create' } },
    );

    latency.add(response.timings.duration);

    if (__ENV.LOAD_EXPLAIN && __VU === 1) {
        const e = response.body.match(/"errors":\s*\{[^}]*\}/);
        console.log(`status=${response.status} url=${response.url} errors=${e ? e[0] : 'none'}`);
    }

    if (response.status >= 500) {
        errored.add(1);
        console.error(`VU ${__VU}: ${response.status}`);
    } else if (/"errors":\s*\{[^{}]*"fulfilment"/.test(response.body)) {
        // The controller answers a domain refusal with a validation error
        // under the `fulfilment` key; an empty errors object is a success.
        refused.add(1);
    } else {
        created.add(1);
    }
}

import { sleep } from 'k6';

function waitUntil(at) {
    const wait = (at - Date.now()) / 1000;

    if (wait > 0) {
        sleep(wait);
    }
}

export function handleSummary(data) {
    const count = (n) => (data.metrics[n] ? data.metrics[n].values.count : 0);
    const d = data.metrics.shipment_duration.values;

    return {
        stdout:
            `\n1 unit, ${__ENV.LOAD_VUS || 20} simultaneous allocations\n` +
            `  created         ${count('shipment_created')}\n` +
            `  refused         ${count('shipment_refused')}\n` +
            `  server errors   ${count('shipment_errored')}\n` +
            `  p50=${d.med.toFixed(0)}ms p95=${d['p(95)'].toFixed(0)}ms max=${d.max.toFixed(0)}ms\n`,
    };
}

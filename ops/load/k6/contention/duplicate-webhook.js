// One signed payment event, delivered thirty times at once.
//
// Providers retry. A retry that arrives while the first delivery is still
// being processed is the case that a `SELECT ... then INSERT` idempotency
// check gets wrong, and getting it wrong means charging a customer once
// and crediting the sellers twice.
//
//   php artisan veritas:contention-drill --prepare-webhook
//   k6 run ops/load/k6/contention/duplicate-webhook.js
//   php artisan veritas:contention-drill --verify-webhook
//
// The signature is real and is verified by the endpoint. Nothing here can
// mark anything paid — the application decides that from the event, which
// is the property being exercised.
import http from 'k6/http';
import { Counter } from 'k6/metrics';
import { BASE } from '../lib/pool.js';

const drill = JSON.parse(open(__ENV.LOAD_DRILL || '../../.run/webhook.json'));

const accepted = new Counter('webhook_accepted');
const duplicate = new Counter('webhook_duplicate');
const rejected = new Counter('webhook_rejected');
const errored = new Counter('webhook_errored');

export const options = {
    scenarios: {
        burst: {
            executor: 'per-vu-iterations',
            vus: Number(__ENV.LOAD_VUS || 30),
            iterations: 1,
            maxDuration: '1m',
        },
    },
    thresholds: {
        webhook_errored: ['count==0'],
        // A provider that gets an error retries; one that gets a 200
        // stops. Every delivery has to be answered.
        webhook_rejected: ['count==0'],
    },
};

export default function deliver() {
    const response = http.post(`${BASE}/webhooks/payments`, drill.payload, {
        headers: {
            'Content-Type': 'application/json',
            'Stripe-Signature': drill.signature,
        },
        tags: { name: 'webhook:deliver' },
    });

    if (response.status >= 500) {
        errored.add(1);
        console.error(`VU ${__VU}: ${response.status} ${response.body.slice(0, 200)}`);
    } else if (response.status !== 200) {
        rejected.add(1);
        console.error(`VU ${__VU}: ${response.status} ${response.body.slice(0, 200)}`);
    } else if (response.body.includes('Duplicate') || response.body.includes('Requeued')) {
        duplicate.add(1);
    } else {
        accepted.add(1);
    }
}

export function handleSummary(data) {
    const count = (name) => (data.metrics[name] ? data.metrics[name].values.count : 0);

    return {
        stdout:
            `\none event, delivered ${__ENV.LOAD_VUS || 30} times at once\n` +
            `  accepted        ${count('webhook_accepted')}\n` +
            `  seen as repeat  ${count('webhook_duplicate')}\n` +
            `  rejected        ${count('webhook_rejected')}\n` +
            `  server errors   ${count('webhook_errored')}\n`,
    };
}

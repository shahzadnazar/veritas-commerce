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
const requeued = new Counter('webhook_requeued');
const finished = new Counter('webhook_already_finished');
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
    } else if (response.body.includes('Requeued')) {
        // The event was stored but nothing had finished it yet, so this
        // delivery queued the work again.
        requeued.add(1);
    } else if (response.body.includes('Already received')) {
        // A worker had already carried it to a terminal state, so this
        // delivery cost one indexed lookup and nothing else.
        finished.add(1);
    } else {
        accepted.add(1);
    }
}

export function handleSummary(data) {
    const count = (name) => (data.metrics[name] ? data.metrics[name].values.count : 0);

    return {
        stdout:
            `\none event, delivered ${__ENV.LOAD_VUS || 30} times at once\n` +
            `  first delivery  ${count('webhook_accepted')}\n` +
            `  requeued        ${count('webhook_requeued')}\n` +
            `  already done    ${count('webhook_already_finished')}\n` +
            `  rejected        ${count('webhook_rejected')}\n` +
            `  server errors   ${count('webhook_errored')}\n`,
    };
}

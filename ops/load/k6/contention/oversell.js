// Many simultaneous checkouts, one shelf, five units on it.
//
// Every virtual user is a different customer — the checkout route is rate
// limited per identity, and sharing one would measure the limiter rather
// than the race — and they all add the same offer and place the order at
// the same moment, released together by a start barrier.
//
//   php artisan veritas:contention-drill --prepare --units=5
//   k6 run ops/load/k6/contention/oversell.js
//   php artisan veritas:contention-drill --verify
//
// The drill's verdict is the verify step, not this script; what this
// records is how the losers were told they lost. A refusal is a correct
// answer to "the last one just went". A 500 is not, and neither is a
// success that the books then disagree with.
import http from 'k6/http';
import { Counter } from 'k6/metrics';
import { BASE, pool, assigned } from '../lib/pool.js';
import { signInCustomer } from '../lib/session.js';

const drill = JSON.parse(open(__ENV.LOAD_DRILL || '../../.run/contention.json'));

// Twelve characters of run identity, so each run's idempotency keys are
// its own. The endpoint wants sixteen in total.
const RUN = String(Date.now()).slice(-12);

const placed = new Counter('checkout_placed');
const refused = new Counter('checkout_refused');
const throttled = new Counter('checkout_throttled');
const errored = new Counter('checkout_errored');

export const options = {
    scenarios: {
        // One iteration each, all at once: the burst is the point.
        burst: {
            executor: 'per-vu-iterations',
            vus: Number(__ENV.LOAD_VUS || 40),
            iterations: 1,
            maxDuration: '2m',
        },
    },
    thresholds: {
        // Losing the race is fine. Falling over is not.
        checkout_errored: ['count==0'],
    },
};

function token(jar) {
    const page = http.get(`${BASE}/cart`, { jar });
    const match = page.body.match(/name="csrf-token" content="([^"]+)"/);

    return match ? match[1] : null;
}

export default function contend() {
    const identity = Object.assign({}, assigned(pool.customers), { password: pool.password });
    const jar = signInCustomer(identity);
    const csrf = token(jar);

    http.post(
        `${BASE}/cart`,
        { offer: drill.offer_public_id, quantity: '1', _token: csrf },
        { jar, tags: { name: 'cart:add' } },
    );

    // Everyone waits for the top of the same second before posting the
    // checkout, so the sign-ins and cart writes are not what is spread
    // out across the burst.
    const now = Date.now();
    const barrier = Math.ceil(now / 5000) * 5000;
    sleepUntil(barrier);

    const response = http.post(
        `${BASE}/checkout`,
        {
            /*
             * Unique per virtual user and per run. A key shared between
             * virtual users would be testing idempotency instead of
             * contention, and one reused between runs replays the last
             * run's outcome — which is correct behaviour, and the reason
             * the second run of this drill placed no orders at all until
             * the nonce was added.
             */
            idempotency_key: `${RUN}${String(__VU).padStart(4, '0')}`,
            email: identity.email,
            name: 'Load Drill',
            line1: '1 Contention Way',
            city: 'London',
            postcode: 'EC1A 1BB',
            country: 'GB',
            _token: csrf,
        },
        { jar, tags: { name: 'checkout:place' } },
    );

    if (response.status === 429) {
        throttled.add(1);
    } else if (response.status >= 500) {
        errored.add(1);
        console.error(`VU ${__VU}: checkout answered ${response.status}`);
    } else if (response.url.includes('/payment')) {
        placed.add(1);
    } else {
        refused.add(1);
    }
}

// k6's sleep takes seconds and its clock is the wall clock; this is just
// a busy-free wait until a shared instant.
function sleepUntil(at) {
    const wait = (at - Date.now()) / 1000;

    if (wait > 0) {
        require_sleep(wait);
    }
}

import { sleep } from 'k6';

function require_sleep(seconds) {
    sleep(seconds);
}

export function handleSummary(data) {
    const count = (name) => (data.metrics[name] ? data.metrics[name].values.count : 0);

    return {
        stdout:
            `\nshelf held ${drill.units} units\n` +
            `  orders placed   ${count('checkout_placed')}\n` +
            `  refused         ${count('checkout_refused')}\n` +
            `  rate limited    ${count('checkout_throttled')}\n` +
            `  server errors   ${count('checkout_errored')}\n`,
    };
}

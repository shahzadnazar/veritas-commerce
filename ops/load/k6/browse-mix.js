// The mixed read workload the capacity numbers come from.
//
// read-surfaces.js measures one page at a time, which answers "how
// expensive is this page". This answers the different question the M9
// brief actually asks — what arrival rate the application sustains —
// by running the surfaces together in roughly the proportion a
// marketplace sees them, so the database is serving a realistic mixture
// and not one query plan over and over.
//
//   LOAD_VUS=50 LOAD_DURATION=30s k6 run ops/load/k6/browse-mix.js
//
// Anonymous browsing only. The signed-in surfaces are a smaller share of
// real traffic and are measured separately; mixing them in would put the
// login cost inside the capacity number.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';
import { BASE, productSlug, categorySlug, search } from './lib/pool.js';

const VUS = Number(__ENV.LOAD_VUS || 10);
const DURATION = __ENV.LOAD_DURATION || '30s';

const latency = new Trend('mix_duration', true);
const failures = new Rate('mix_failed');
const statuses = new Counter('mix_non_200');

export const options = {
    discardResponseBodies: true,
    summaryTrendStats: ['count', 'avg', 'min', 'med', 'p(95)', 'p(99)', 'max'],
    scenarios: {
        mix: { executor: 'constant-vus', vus: VUS, duration: DURATION, gracefulStop: '15s' },
    },
};

// Weights, not a uniform draw. A marketplace's traffic is mostly product
// pages and search; the homepage is one request per visit.
const plan = [
    { weight: 34, url: () => `${BASE}/products/${productSlug()}` },
    { weight: 26, url: () => `${BASE}/search?q=${encodeURIComponent(search.selective())}` },
    { weight: 10, url: () => `${BASE}/search?q=${encodeURIComponent(search.broad())}` },
    { weight: 6, url: () => `${BASE}/search?q=${encodeURIComponent(search.fuzzy())}` },
    { weight: 16, url: () => `${BASE}/categories/${categorySlug()}` },
    { weight: 8, url: () => `${BASE}/` },
];

const total = plan.reduce((sum, entry) => sum + entry.weight, 0);

function next() {
    let roll = Math.random() * total;

    for (const entry of plan) {
        roll -= entry.weight;

        if (roll <= 0) {
            return entry.url();
        }
    }

    return plan[0].url();
}

export default function browse() {
    const response = http.get(next());
    const ok = check(response, { 'status is 200': (r) => r.status === 200 });

    latency.add(response.timings.duration);
    failures.add(!ok);

    if (!ok) {
        statuses.add(1, { status: String(response.status) });
    }

    // Think time. Without it every virtual user is a closed loop running
    // flat out, which measures the machine's saturation point rather than
    // how many people it serves.
    sleep(Math.random() * 0.6 + 0.2);
}

export function handleSummary(data) {
    const mix = data.metrics.mix_duration.values;
    const http_reqs = data.metrics.http_reqs.values;

    return {
        [__ENV.LOAD_OUT || 'ops/load/.run/mix.json']: JSON.stringify(data, null, 2),
        stdout:
            `\nvus=${VUS} rps=${http_reqs.rate.toFixed(1)} n=${mix.count}` +
            ` p50=${mix.med.toFixed(0)} p95=${mix['p(95)'].toFixed(0)} p99=${mix['p(99)'].toFixed(0)}` +
            ` max=${mix.max.toFixed(0)} fail=${(data.metrics.mix_failed.values.rate * 100).toFixed(2)}%\n`,
    };
}

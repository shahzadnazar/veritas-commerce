// Every required read surface, measured one at a time.
//
// One k6 scenario per surface, run sequentially rather than together, so
// each surface's p95 is its own number and not an average of whatever
// else was running. The mixed workloads are separate scripts; this one
// answers "how fast is this page" and nothing else.
//
//   k6 run ops/load/k6/read-surfaces.js
//
// LOAD_VUS and LOAD_DURATION set the level. The defaults are modest
// because the load generator shares four cores with the application,
// PostgreSQL, Redis, SSR and Horizon.
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend, Rate } from 'k6/metrics';
import {
    BASE,
    pool,
    assigned,
    productSlug,
    categorySlug,
    customerOrder,
    sellerOrder,
    adminOrder,
    search,
} from './lib/pool.js';
import { signInAdmin, signInCustomer, signInSeller } from './lib/session.js';

const VUS = Number(__ENV.LOAD_VUS || 10);
const DURATION = __ENV.LOAD_DURATION || '45s';

// One trend per surface. k6's built-in http_req_duration is the whole
// run; these are what the report tables are built from.
const surfaces = [
    'homepage',
    'category',
    'search_selective',
    'search_broad',
    'search_fuzzy',
    'search_empty',
    'pdp',
    'customer_orders',
    'seller_orders',
    'seller_inventory',
    'admin_orders',
    'admin_finance',
];

export const trends = {};
export const failures = {};

for (const surface of surfaces) {
    trends[surface] = new Trend(`surface_${surface}`, true);
    failures[surface] = new Rate(`failed_${surface}`);
}

function scenario(exec, startTime) {
    return {
        executor: 'constant-vus',
        vus: VUS,
        duration: DURATION,
        exec,
        startTime,
        gracefulStop: '10s',
    };
}

// Sequential: each surface gets the machine to itself, so its numbers
// describe the surface rather than the contention between surfaces.
const step = (index) => `${index * (parseInt(DURATION) + 12)}s`;

export const options = {
    discardResponseBodies: false,
    // k6's default trend statistics stop at p(95) and omit the sample
    // count; the report tables need both.
    summaryTrendStats: ['count', 'avg', 'min', 'med', 'p(95)', 'p(99)', 'max'],
    scenarios: {
        publicSurfaces: scenario('publicSurfaces', step(0)),
        searchSurfaces: scenario('searchSurfaces', step(1)),
        customerSurfaces: scenario('customerSurfaces', step(2)),
        sellerSurfaces: scenario('sellerSurfaces', step(3)),
        adminSurfaces: scenario('adminSurfaces', step(4)),
    },
    thresholds: {
        // Declared before the run, per the M9 brief. They are recorded
        // as thresholds rather than enforced as failures, because a
        // single machine running the load generator alongside the
        // application is not the environment these targets were written
        // for — the report says which were met.
        surface_homepage: ['p(95)<500'],
        surface_category: ['p(95)<800'],
        surface_search_selective: ['p(95)<800'],
        surface_search_broad: ['p(95)<800'],
        surface_pdp: ['p(95)<800'],
        surface_customer_orders: ['p(95)<800'],
        surface_seller_orders: ['p(95)<800'],
        surface_seller_inventory: ['p(95)<800'],
        surface_admin_orders: ['p(95)<800'],
        surface_admin_finance: ['p(95)<800'],
    },
};

function measure(surface, url, name) {
    const response = http.get(url, { tags: { name: name || surface } });
    const ok = check(response, { 'status is 200': (r) => r.status === 200 });

    trends[surface].add(response.timings.duration);
    failures[surface].add(!ok);

    return response;
}

export function publicSurfaces() {
    group('public', () => {
        measure('homepage', `${BASE}/`);
        sleep(0.3);
        measure('category', `${BASE}/categories/${categorySlug()}`);
        sleep(0.3);
        measure('pdp', `${BASE}/products/${productSlug()}`);
        sleep(0.3);
    });
}

export function searchSurfaces() {
    group('search', () => {
        measure('search_selective', `${BASE}/search?q=${encodeURIComponent(search.selective())}`);
        sleep(0.3);
        measure('search_broad', `${BASE}/search?q=${encodeURIComponent(search.broad())}`);
        sleep(0.3);
        measure('search_fuzzy', `${BASE}/search?q=${encodeURIComponent(search.fuzzy())}`);
        sleep(0.3);
        measure('search_empty', `${BASE}/search?q=${encodeURIComponent(search.empty())}`);
        sleep(0.3);
    });
}

// Each authenticated VU signs in once and keeps its session. Logging in
// every iteration would measure bcrypt and trip the login throttle, and
// sharing one session across VUs would serialise on its Redis key.
let signedIn = false;

export function customerSurfaces() {
    if (!signedIn) {
        signInCustomer(Object.assign({}, assigned(pool.customers), { password: pool.password }));
        signedIn = true;
    }

    group('customer', () => {
        measure('customer_orders', `${BASE}/account/orders`);
        sleep(0.5);
        measure('customer_orders', `${BASE}/account/orders?page=2`, 'customer_orders:page2');
        sleep(0.5);
    });
}

export function sellerSurfaces() {
    if (!signedIn) {
        signInSeller(Object.assign({}, assigned(pool.sellers), { password: pool.password }));
        signedIn = true;
    }

    group('seller', () => {
        measure('seller_orders', `${BASE}/seller/orders`);
        sleep(0.4);
        measure('seller_orders', `${BASE}/seller/orders?status=delivered`, 'seller_orders:filtered');
        sleep(0.4);
        measure('seller_inventory', `${BASE}/seller/inventory`);
        sleep(0.4);
        measure('seller_inventory', `${BASE}/seller/inventory?state=low_stock`, 'seller_inventory:low');
        sleep(0.4);
    });
}

export function adminSurfaces() {
    if (!signedIn) {
        signInAdmin(Object.assign({}, assigned(pool.admins), { password: pool.password }));
        signedIn = true;
    }

    group('admin', () => {
        measure('admin_orders', `${BASE}/admin/orders`);
        sleep(0.5);
        measure('admin_orders', `${BASE}/admin/orders?status=paid`, 'admin_orders:filtered');
        sleep(0.5);
        measure('admin_finance', `${BASE}/admin/finance`);
        sleep(0.5);
        measure('admin_finance', `${BASE}/admin/payouts`, 'admin_payouts');
        sleep(0.5);
    });
}

export function handleSummary(data) {
    return {
        [__ENV.LOAD_OUT || 'ops/load/.run/read-surfaces.json']: JSON.stringify(data, null, 2),
        stdout: textSummary(data),
    };
}

// A compact table rather than k6's default wall of text: the report needs
// p50/p95/p99 per surface and nothing else.
function textSummary(data) {
    const rows = [['surface', 'count', 'p50', 'p95', 'p99', 'fail%']];

    for (const surface of surfaces) {
        const metric = data.metrics[`surface_${surface}`];
        const failed = data.metrics[`failed_${surface}`];

        if (!metric || !metric.values.count) {
            continue;
        }

        rows.push([
            surface,
            String(metric.values.count),
            metric.values.med.toFixed(1),
            metric.values['p(95)'].toFixed(1),
            metric.values['p(99)'].toFixed(1),
            ((failed ? failed.values.rate : 0) * 100).toFixed(2),
        ]);
    }

    const widths = rows[0].map((_, column) => Math.max(...rows.map((row) => row[column].length)));

    return (
        '\n' +
        rows
            .map((row) => row.map((cell, column) => cell.padEnd(widths[column])).join('  '))
            .join('\n') +
        '\n'
    );
}

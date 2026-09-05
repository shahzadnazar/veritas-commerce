// The request pools, and the rule that makes them worth having.
//
// A load test that asks for one product slug measures a cache entry. This
// samples from pools drawn across the whole 857k-row dataset by
// `veritas:seed-load-identities`, so buffer-cache and index behaviour
// under load resemble a real mix of traffic rather than one hot row.
//
// The pool file is generated, gitignored and holds a working password. It
// is never committed and never printed.
import { SharedArray } from 'k6/data';

export const BASE = __ENV.LOAD_BASE_URL || 'http://127.0.0.1:8080';
// Relative to this file, so the scripts run the same from the
// repository root or from ops/load.
const POOL_PATH = __ENV.LOAD_POOL || '../../.run/pool.json';

// SharedArray keeps one copy in memory for every VU rather than one per
// VU; with 200 VUs the difference is the whole pool file times two
// hundred.
const raw = new SharedArray('pool', () => [JSON.parse(open(POOL_PATH))]);

export const pool = raw[0];

// Deterministic per-VU choice where it matters, random where it does not.
// A VU that always picks index 0 would be a hotspot test by accident.
export function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}

// Spread VUs across a pool so two virtual users rarely share an identity —
// sessions and row locks both serialise, and a benchmark that shared one
// login would be measuring that instead of the application.
export function assigned(list, offset = 0) {
    return list[(__VU + offset) % list.length];
}

export const productSlug = () => pick(pool.products);
export const categorySlug = () => pick(pool.categories);
export const storeSlug = () => pick(pool.stores);
// Orders belong to whoever placed them. Asking for one belonging to
// somebody else is answered with a 404 by design, so an identity's own
// references travel with the identity.
export const ownOrder = (identity) => pick(identity.orders);
export const adminOrder = () => pick(pool.admin_orders);
export const payoutReference = () => pick(pool.payouts);

export const search = {
    selective: () => pick(pool.searches.selective),
    broad: () => pick(pool.searches.broad),
    fuzzy: () => pick(pool.searches.fuzzy),
    empty: () => pick(pool.searches.empty),
};

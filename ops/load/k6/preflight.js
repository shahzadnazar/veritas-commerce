// Proof that the load scripts are measuring the pages they claim to.
//
// A benchmark that times a login redirect, a 404 or an empty result set
// produces excellent numbers and tells you nothing. This asks for every
// measured URL once and asserts which Inertia page came back, so a
// surface only enters a report after it has been shown to be the real
// page for a real signed-in identity.
//
//   k6 run ops/load/k6/preflight.js
//
// It exits non-zero if any surface is wrong, and prints whether each one
// was server-rendered — which is the input to the SSR question the load
// report has to answer.
import http from 'k6/http';
import { fail } from 'k6';
import { BASE, pool, assigned, ownOrder, adminOrder, productSlug, categorySlug, search } from './lib/pool.js';
import { signInAdmin, signInCustomer, signInSeller } from './lib/session.js';

export const options = {
    scenarios: {
        preflight: { executor: 'shared-iterations', vus: 1, iterations: 1, maxDuration: '3m' },
    },
};

const problems = [];
const rendering = {};

// Whether a response was server-rendered, and which page it is.
//
// Inertia leaves the page object in one of two places. When SSR
// succeeded the root div holds the rendered markup and carries the
// props in its data-page attribute; when SSR failed or is off the root
// div is empty and the props arrive in a script tag. Emptiness of the
// root is therefore the test — not which form the props took, which is
// what an earlier version of this check got backwards.
function page(body) {
    const root = body.match(/<div id="app"([^>]*)>([\s\S]*?)<\/div><\/body>/);
    const script = body.match(/<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/);

    const attribute = root
        ? (root[1].match(/ data-page="([^"]*)"/) || [])[1]
        : undefined;

    const json = attribute
        ? attribute.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&')
        : script
          ? script[1]
          : null;

    if (json === null) {
        return null;
    }

    return { rendered: root && root[2].trim() !== '' ? 'ssr' : 'client', json };
}

function component(found) {
    const name = found ? found.json.match(/"component"\s*:\s*"([^"]+)"/) : null;

    return name ? name[1].replace(/\\\//g, '/') : null;
}

// `render` is 'ssr' for the storefront, which is server-rendered for
// crawlers and first paint, and 'client' for the seller and admin
// portals, which are behind auth and deliberately skip SSR. Asserting it
// is what turns a silent SSR fallback — a blank page for a crawler, and
// a page whose cost the load numbers no longer describe — into a failure
// somebody sees.
function expect(label, url, expected, render, jar) {
    const response = http.get(url, jar ? { jar } : {});
    const found = page(response.body);
    const got = component(found);

    rendering[label] = found ? found.rendered : 'unknown';

    if (response.status !== 200) {
        problems.push(`${label}: HTTP ${response.status} (expected 200) at ${response.url}`);
    } else if (got !== expected) {
        problems.push(`${label}: rendered "${got}" but the load script assumes "${expected}"`);
    } else if (rendering[label] !== render) {
        problems.push(`${label}: rendered ${rendering[label]}-side, expected ${render}`);
    } else if (response.body.length < 2000) {
        // A page that renders with nothing on it is measurable and
        // useless; the pools are meant to point at populated rows.
        problems.push(`${label}: rendered ${expected} but the body is only ${response.body.length} bytes`);
    }
}

export default function preflight() {
    expect('homepage', `${BASE}/`, 'Home', 'ssr');
    expect('category', `${BASE}/categories/${categorySlug()}`, 'Category/Show', 'ssr');
    expect('pdp', `${BASE}/products/${productSlug()}`, 'Product/Show', 'ssr');

    for (const [label, term] of [
        ['search_selective', search.selective()],
        ['search_broad', search.broad()],
        ['search_fuzzy', search.fuzzy()],
        ['search_empty', search.empty()],
    ]) {
        expect(label, `${BASE}/search?q=${encodeURIComponent(term)}`, 'Search/Index', 'ssr');
    }

    // Authenticated surfaces, each through the real login form, each
    // asking only for rows the identity actually owns.
    const customer = Object.assign({}, assigned(pool.customers), { password: pool.password });
    const asCustomer = signInCustomer(customer);
    expect('customer_orders', `${BASE}/account/orders`, 'Account/Orders/Index', 'ssr', asCustomer);
    expect('customer_order', `${BASE}/account/orders/${ownOrder(customer)}`, 'Account/Orders/Show', 'ssr', asCustomer);

    const seller = Object.assign({}, assigned(pool.sellers), { password: pool.password });
    const asSeller = signInSeller(seller);
    expect('seller_orders', `${BASE}/seller/orders`, 'Orders/Index', 'client', asSeller);
    expect('seller_order', `${BASE}/seller/orders/${ownOrder(seller)}`, 'Orders/Show', 'client', asSeller);
    expect('seller_inventory', `${BASE}/seller/inventory`, 'Inventory/Index', 'client', asSeller);

    const asAdmin = signInAdmin(Object.assign({}, assigned(pool.admins), { password: pool.password }));
    expect('admin_orders', `${BASE}/admin/orders`, 'Orders/Index', 'client', asAdmin);
    expect('admin_order', `${BASE}/admin/orders/${adminOrder()}`, 'Orders/Show', 'client', asAdmin);
    expect('admin_finance', `${BASE}/admin/finance`, 'Finance/Index', 'client', asAdmin);
    expect('admin_payouts', `${BASE}/admin/payouts`, 'Payouts/Index', 'client', asAdmin);

    for (const [label, mode] of Object.entries(rendering)) {
        console.log(`  ${label.padEnd(20)} ${mode}`);
    }

    if (problems.length) {
        for (const problem of problems) {
            console.error(problem);
        }

        fail(`${problems.length} surface(s) are not what the load scripts assume.`);
    }

    console.log('Preflight clean: every measured surface rendered its expected page.');
}

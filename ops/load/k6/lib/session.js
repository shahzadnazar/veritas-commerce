// Signing in the way a browser does.
//
// Each helper returns the cookie jar holding that identity's session;
// pass it to every request that is supposed to be made as them.
//
// Every authenticated scenario goes through the real login form: the real
// CSRF token out of the page, the real session cookie, the real
// authorisation policies, and — for admins — a real TOTP code computed
// here from the secret the seeder enrolled. Nothing is bypassed, because
// a benchmark that needed the second factor turned off would be measuring
// an application nobody deploys.
import http from 'k6/http';
import crypto from 'k6/crypto';
import encoding from 'k6/encoding';
import { fail } from 'k6';
import { BASE } from './pool.js';

// The token Laravel puts in every page's head. Taken from the page rather
// than from a cookie because that is where a browser's JavaScript gets
// it, and a mismatch here shows up as 419 rather than as a wrong number.
function csrf(html) {
    const match = html.match(/name="csrf-token" content="([^"]+)"/);

    if (!match) {
        fail('No CSRF token in the page; the login flow would post blind.');
    }

    return match[1];
}

function base32Decode(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';

    for (const character of secret.toUpperCase().replace(/=+$/, '')) {
        const index = alphabet.indexOf(character);

        if (index < 0) {
            fail(`Not a base32 secret: ${character}`);
        }

        bits += index.toString(2).padStart(5, '0');
    }

    const bytes = [];

    for (let at = 0; at + 8 <= bits.length; at += 8) {
        bytes.push(parseInt(bits.slice(at, at + 8), 2));
    }

    return new Uint8Array(bytes).buffer;
}

// RFC 6238, the six-digit thirty-second variant the application uses.
export function totp(secret) {
    const counter = Math.floor(Date.now() / 1000 / 30);
    const message = new Uint8Array(8);

    let remaining = counter;

    for (let at = 7; at >= 0; at--) {
        message[at] = remaining & 0xff;
        remaining = Math.floor(remaining / 256);
    }

    const digest = new Uint8Array(
        encoding.b64decode(
            crypto.hmac('sha1', base32Decode(secret), message.buffer, 'base64'),
        ),
    );

    const offset = digest[19] & 0xf;
    const binary =
        ((digest[offset] & 0x7f) << 24) |
        (digest[offset + 1] << 16) |
        (digest[offset + 2] << 8) |
        digest[offset + 3];

    return String(binary % 1000000).padStart(6, '0');
}

// A fresh jar per sign-in, never the VU's default one.
//
// k6 reuses a virtual user's JavaScript runtime across scenarios, so a
// customer session established in one scenario is still in the default
// cookie jar when the next scenario signs in as a seller — and Laravel's
// guest middleware bounces the already-authenticated login request, so
// the seller scenario quietly measures the customer's 404s. Handing each
// sign-in its own jar makes that impossible rather than remembering not
// to do it.
function signIn(path, body) {
    const jar = new http.CookieJar();
    const page = http.get(`${BASE}${path}`, { jar, tags: { name: 'login:form' } });

    if (page.status !== 200) {
        fail(`The login form at ${path} answered ${page.status}.`);
    }

    const response = http.post(
        `${BASE}${path}`,
        Object.assign({}, body, { _token: csrf(page.body) }),
        { jar, tags: { name: 'login:submit' } },
    );

    // 200 after redirects means the form came back with errors; a
    // successful sign-in lands on a page that is not the login form.
    if (response.status !== 200 || response.url.includes(path)) {
        fail(`Sign-in as ${body.email} failed: ${response.status} at ${response.url}`);
    }

    return jar;
}

export function signInCustomer(identity) {
    return signIn('/login', { email: identity.email, password: identity.password });
}

export function signInSeller(identity) {
    return signIn('/login', { email: identity.email, password: identity.password });
}

export function signInAdmin(identity) {
    return signIn('/admin/login', {
        email: identity.email,
        password: identity.password,
        code: totp(identity.totp_secret),
    });
}

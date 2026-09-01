<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A random per-browser identifier, for behaviour that has no account
 * behind it.
 *
 * Deliberately the least invasive thing that works: a random ULID kept in
 * the session, generated on first need. No fingerprinting, no IP hashing,
 * no cross-site identifier — §35 rules those out, and none of them are
 * needed to answer "did this person click the second result".
 *
 * It lives and dies with the session cookie, which means a customer who
 * clears their cookies is a new person to us, and that is the correct
 * behaviour rather than a limitation to engineer around.
 */
final class AnonymousSession
{
    private const KEY = 'veritas_anon_id';

    public static function idFor(Request $request): ?string
    {
        if (! $request->hasSession()) {
            // A stateless request — an API call, a console run — has
            // nowhere to keep one, and inventing an id per request would
            // produce a stream of single-event "visitors".
            return null;
        }

        $session = $request->session();
        $existing = $session->get(self::KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::ulid();
        $session->put(self::KEY, $id);

        return $id;
    }
}

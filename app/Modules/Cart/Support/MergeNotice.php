<?php

declare(strict_types=1);

namespace App\Modules\Cart\Support;

use App\Modules\Cart\Data\CartIssue;
use App\Modules\Cart\Data\CartMergeResult;
use App\Modules\Cart\Enums\CartIssueCode;
use Illuminate\Http\Request;

/**
 * Carries what a sign-in merge could not honour through to the cart page.
 *
 * Not a flash message: sign-in redirects to wherever the customer was
 * going, which is usually not the cart, so a one-request flash would be
 * gone before there was anywhere to show it. It is drained on first read
 * instead, so the notice appears exactly once, whenever the customer next
 * looks at their cart.
 */
final class MergeNotice
{
    private const KEY = 'veritas_cart_merge_issues';

    public static function remember(Request $request, CartMergeResult $result): void
    {
        if ($result->issues === [] || ! $request->hasSession()) {
            return;
        }

        $request->session()->put(
            self::KEY,
            array_map(static fn (CartIssue $issue): array => $issue->toArray(), $result->issues),
        );
    }

    /**
     * The pending issues, removed as they are read.
     *
     * @return array<int, CartIssue>
     */
    public static function drain(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $stored = $request->session()->pull(self::KEY, []);

        if (! is_array($stored)) {
            return [];
        }

        $issues = [];

        foreach ($stored as $row) {
            if (! is_array($row) || ! is_string($row['code'] ?? null)) {
                continue;
            }

            $code = CartIssueCode::tryFrom($row['code']);

            if ($code === null) {
                continue;
            }

            $issues[] = new CartIssue(
                code: $code,
                lineIdentity: is_string($row['lineIdentity'] ?? null) ? $row['lineIdentity'] : null,
                available: is_int($row['available'] ?? null) ? $row['available'] : null,
            );
        }

        return $issues;
    }
}

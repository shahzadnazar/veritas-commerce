<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Controllers;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Actions\UpdateCartLine;
use App\Modules\Cart\Data\CartIssue;
use App\Modules\Cart\Exceptions\CartOperationRefused;
use App\Modules\Cart\Queries\BuildCartView;
use App\Modules\Cart\Queries\ResolveCart;
use App\Modules\Cart\Support\MergeNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's cart, over HTTP.
 *
 * Every route resolves the cart from the session and never from the
 * request. There is no cart id in any URL or body, so there is nothing for
 * a customer to change into somebody else's cart — which is the whole of
 * the cart's authorization model, and why it needs no policy class.
 *
 * The page is handed the read model the domain already builds, revalidated
 * on every load. React renders it; it does not recompute any of it.
 */
final class CartController
{
    public function __construct(
        private readonly ResolveCart $carts,
        private readonly BuildCartView $view,
        private readonly AddOfferToCart $add,
        private readonly UpdateCartLine $lines,
    ) {}

    public function show(Request $request): Response
    {
        $cart = $this->carts->existing($request);

        return Inertia::render('Cart/Index', [
            // Named `cartView`, not `cart`: the shared props already carry
            // a `cart` holding the header count, and a page prop of the
            // same name would silently shadow it.
            'cartView' => ($this->view)($cart)->toArray(),
            'maxLineQuantity' => AddOfferToCart::MAX_LINE_QUANTITY,
            /*
             * What a sign-in merge could not honour, drained on first
             * read. Sign-in rarely lands a customer on the cart, so this
             * waits here until they look rather than flashing past on a
             * page that was not about the cart at all.
             */
            'mergeNotices' => array_map(
                static fn (CartIssue $issue): array => $issue->toArray(),
                MergeNotice::drain($request),
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'offer' => ['required', 'string', 'max:64'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.AddOfferToCart::MAX_LINE_QUANTITY],
        ]);

        // Created on demand: a browser that only ever looks does not get
        // a cart row.
        $cart = $this->carts->orCreate($request);

        try {
            ($this->add)($cart, $input['offer'], (int) ($input['quantity'] ?? 1));
        } catch (CartOperationRefused $refusal) {
            throw ValidationException::withMessages(['offer' => $this->message($refusal)]);
        }

        return back()->with('success', 'Added to your basket.');
    }

    public function update(Request $request, string $line): RedirectResponse
    {
        $input = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:'.AddOfferToCart::MAX_LINE_QUANTITY],
        ]);

        $cart = $this->carts->existing($request);

        if ($cart === null) {
            return redirect()->route('cart');
        }

        try {
            $this->lines->setQuantity($cart, $line, (int) $input['quantity']);
        } catch (CartOperationRefused $refusal) {
            /*
             * The backend wins, always. A quantity the customer typed is
             * a request; what the inventory says at the moment the row is
             * locked is the answer, and the error names the number they
             * can actually have.
             */
            throw ValidationException::withMessages(['quantity' => $this->message($refusal)]);
        }

        return back();
    }

    public function destroy(Request $request, string $line): RedirectResponse
    {
        $cart = $this->carts->existing($request);

        if ($cart !== null) {
            $this->lines->remove($cart, $line);
        }

        return back()->with('success', 'Removed from your basket.');
    }

    /**
     * A refusal, in the customer's words.
     *
     * The domain's issue codes are for the code that has to branch on
     * them. A shopper gets a sentence naming what they can do about it,
     * and never an enum value.
     */
    private function message(CartOperationRefused $refusal): string
    {
        return match (true) {
            $refusal->available !== null && $refusal->available > 0 => sprintf(
                'Only %d left. Choose %d or fewer.',
                $refusal->available,
                $refusal->available,
            ),
            $refusal->available === 0 => 'That has just sold out.',
            default => $refusal->getMessage(),
        };
    }
}

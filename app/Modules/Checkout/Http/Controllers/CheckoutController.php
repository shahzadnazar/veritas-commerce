<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Cart\Queries\ResolveCart;
use App\Modules\Checkout\Actions\PlaceOrder;
use App\Modules\Checkout\Actions\StartCheckout;
use App\Modules\Checkout\Data\ShippingAddress;
use App\Modules\Checkout\Exceptions\CheckoutRefused;
use App\Modules\Checkout\Queries\BuildCheckoutView;
use App\Modules\Checkout\Queries\CheckoutIssueLanguage;
use App\Modules\Events\Actions\RecordInteraction;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Identity\Models\CustomerAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Review, then hand off to payment.
 *
 * The page shows what the server says this purchase costs; the form posts
 * an address and an idempotency key and nothing else. There is no total,
 * no price and no commission in the request body, because there is nowhere
 * for one to be read from — the quote is rebuilt on the server on both
 * sides of the button.
 *
 * M4 ends at a payment-pending order. Nothing here contacts a provider.
 */
final class CheckoutController
{
    public function __construct(
        private readonly ResolveCart $carts,
        private readonly BuildCheckoutView $view,
        private readonly StartCheckout $start,
        private readonly PlaceOrder $place,
        private readonly RecordInteraction $interactions,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $cart = $this->carts->existing($request);
        $page = ($this->view)($request, $cart);

        if (($page['quote']['cart']['itemCount'] ?? 0) === 0) {
            return redirect()->route('cart');
        }

        $this->interactions->record($request, InteractionEventType::CheckoutStarted, payload: [
            'context' => 'checkout',
            'lines' => $page['quote']['cart']['itemCount'],
        ]);

        return Inertia::render('Checkout/Index', $page);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            // Client-generated, so a resubmission of the same form is the
            // same checkout. Validated as an opaque token: it means
            // nothing to the server beyond "these two requests are one".
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'saved_address' => ['nullable', 'string', 'max:64'],
            'email' => ['required_without:saved_address', 'nullable', 'string', 'email', 'max:255'],
            'name' => ['required_without:saved_address', 'nullable', 'string', 'max:255'],
            'line1' => ['required_without:saved_address', 'nullable', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required_without:saved_address', 'nullable', 'string', 'max:255'],
            // Nullable on purpose: §33. Requiring a state locks out every
            // country that does not have one.
            'state' => ['nullable', 'string', 'max:64'],
            'postcode' => ['required_without:saved_address', 'nullable', 'string', 'max:32'],
            'country' => ['required_without:saved_address', 'nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:64'],
            'save_address' => ['nullable', 'boolean'],
        ]);

        $cart = $this->carts->existing($request);

        if ($cart === null) {
            return redirect()->route('cart');
        }

        $user = $request->user('web');
        $userId = $user === null ? null : (int) $user->getAuthIdentifier();
        $address = $this->addressFrom($input, $userId);
        $email = $this->emailFrom($input, $user?->email);

        try {
            $attempt = ($this->start)($cart, $input['idempotency_key'], $address, $userId, $email);
            $order = ($this->place)($attempt);
        } catch (CheckoutRefused $refusal) {
            $this->interactions->record($request, InteractionEventType::CheckoutValidationFailed, payload: [
                'context' => 'checkout',
                'reason' => $refusal->reason,
            ]);

            throw ValidationException::withMessages([
                'checkout' => $this->message($refusal),
            ])->errorBag('default');
        }

        if (($input['save_address'] ?? false) && $userId !== null && ($input['saved_address'] ?? null) === null) {
            $this->rememberAddress($address, $userId);
        }

        $this->interactions->record($request, InteractionEventType::CheckoutOrderCreated, payload: [
            'context' => 'checkout',
            'reference' => $order->reference,
        ], valueMinor: $order->grand_total_minor);

        return redirect()->route('checkout.payment', ['reference' => $order->reference]);
    }

    /**
     * A refusal, as something the customer can act on.
     *
     * Never the exception's own words where those name a mechanism: the
     * reason code is the branch, the sentence is the answer. No SQL, no
     * enum, no identifier reaches this page.
     */
    private function message(CheckoutRefused $refusal): string
    {
        return match ($refusal->reason) {
            'cart_empty' => 'Your basket is empty.',
            'stock_unavailable' => 'Some items are no longer available in the requested quantity. '
                .'Review your basket before continuing.',
            'price_moved' => 'Prices in your basket changed while you were checking out. '
                .'Review the new total and try again.',
            'attempt_expired' => 'This checkout timed out and the items were released. Start again from your basket.',
            'cart_not_buyable' => $this->firstIssueMessage($refusal)
                ?? 'One of the offers in your basket is no longer available.',
            'idempotency_conflict' => 'That checkout has already been submitted. Check your orders before trying again.',
            default => 'We could not complete this checkout. Review your basket and try again.',
        };
    }

    private function firstIssueMessage(CheckoutRefused $refusal): ?string
    {
        $first = $refusal->issues[0] ?? null;

        return $first === null ? null : CheckoutIssueLanguage::describe($first)['detail'];
    }

    /**
     * The address this checkout ships to.
     *
     * A saved address is resolved through the signed-in customer's own
     * rows, so an id belonging to somebody else resolves to nothing rather
     * than to their address.
     *
     * @param  array<string, mixed>  $input
     */
    private function addressFrom(array $input, ?int $userId): ShippingAddress
    {
        $savedId = is_string($input['saved_address'] ?? null) ? $input['saved_address'] : null;

        if ($savedId !== null && $userId !== null) {
            /** @var CustomerAddress|null $saved */
            $saved = CustomerAddress::query()
                ->where('user_id', $userId)
                ->where('public_id', $savedId)
                ->first();

            if ($saved === null) {
                throw ValidationException::withMessages([
                    'saved_address' => 'Choose one of your saved addresses.',
                ]);
            }

            return new ShippingAddress(
                name: $saved->name,
                line1: $saved->line1,
                line2: $saved->line2,
                city: $saved->city,
                state: $saved->state,
                postcode: $saved->postcode,
                country: $saved->country,
                phone: $saved->phone,
            );
        }

        try {
            return ShippingAddress::fromArray($input);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['line1' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $input */
    private function emailFrom(array $input, ?string $accountEmail): string
    {
        $given = is_string($input['email'] ?? null) ? trim($input['email']) : '';

        if ($given !== '') {
            return $given;
        }

        if ($accountEmail !== null) {
            return $accountEmail;
        }

        throw ValidationException::withMessages([
            'email' => 'We need an email address to send your receipt to.',
        ]);
    }

    /** The address book is a convenience; the order already has its own copy. */
    private function rememberAddress(ShippingAddress $address, int $userId): void
    {
        CustomerAddress::query()->create([
            'user_id' => $userId,
            'label' => Str::limit($address->city, 24, ''),
            'name' => $address->name,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'state' => $address->state,
            'postcode' => $address->postcode,
            'country' => $address->country,
            'phone' => $address->phone,
        ]);
    }
}

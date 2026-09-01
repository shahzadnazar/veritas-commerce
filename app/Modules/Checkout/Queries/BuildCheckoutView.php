<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Queries;

use App\Modules\Cart\Data\CartIssue;
use App\Modules\Cart\Models\Cart;
use App\Modules\Checkout\Actions\QuoteCheckout;
use App\Modules\Identity\Models\CustomerAddress;
use Illuminate\Http\Request;

/**
 * Everything the checkout page renders, assembled once.
 *
 * The quote is the domain's; the addresses and the contact details are the
 * page's. Keeping the assembly here rather than in the controller means
 * the shape the page depends on is one class, and a test can build it
 * without a request.
 *
 * Nothing in here is recomputed in React. The page is given formatted
 * money AND minor units — the first to render, the second so a test can
 * assert on a number rather than on a string somebody may reformat.
 */
final class BuildCheckoutView
{
    public function __construct(private readonly QuoteCheckout $quote) {}

    /** @return array<string, mixed> */
    public function __invoke(Request $request, ?Cart $cart): array
    {
        $quote = ($this->quote)($cart);
        $user = $request->user('web');

        return [
            'quote' => $quote->toArray(),
            'contact' => [
                'email' => $user?->email,
                'name' => $user?->fullName(),
                'isGuest' => $user === null,
            ],
            'addresses' => $user === null ? [] : $this->addressesFor((int) $user->getAuthIdentifier()),
            /*
             * The customer-facing reading of every issue on the quote.
             * Codes stay in the payload for the page to branch on; the
             * sentence is written here, where the platform's voice lives,
             * rather than in seven React string literals.
             */
            'issueMessages' => array_map(
                static fn (CartIssue $issue): array => CheckoutIssueLanguage::describe($issue),
                $quote->issues,
            ),
            'shippingPolicy' => self::shippingPolicy(),
        ];
    }

    /**
     * How shipping was calculated, in words the page can print.
     *
     * §29: label the policy that is actually implemented. A flat per-
     * seller-order fee is what the domain charges, and zero is a real
     * answer rather than a placeholder to dress up.
     *
     * @return array<string, mixed>
     */
    public static function shippingPolicy(): array
    {
        $perSellerOrder = (int) config('veritas.checkout.shipping_per_seller_order_minor');

        return [
            'perSellerOrderMinor' => $perSellerOrder,
            'label' => $perSellerOrder === 0
                ? 'Delivery included'
                : 'Delivery charged once per seller',
            'note' => $perSellerOrder === 0
                ? 'Sellers on this marketplace do not charge separately for delivery.'
                : 'Each seller ships separately, so delivery is charged once per seller.',
            // Stated rather than implied: M4 runs no tax engine, and a
            // "$0.00 tax" line that looked calculated would be a claim
            // the platform cannot support.
            'taxNote' => 'Tax is not calculated at this stage.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function addressesFor(int $userId): array
    {
        return CustomerAddress::query()
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(static fn (CustomerAddress $address): array => [
                'publicId' => $address->public_id,
                'label' => $address->label,
                'name' => $address->name,
                'line1' => $address->line1,
                'line2' => $address->line2,
                'city' => $address->city,
                'state' => $address->state,
                'postcode' => $address->postcode,
                'country' => $address->country,
                'phone' => $address->phone,
                'isDefault' => $address->is_default,
            ])
            ->all();
    }
}

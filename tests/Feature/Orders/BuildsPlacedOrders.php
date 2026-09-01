<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Models\Cart;
use App\Modules\Checkout\Actions\PlaceOrder;
use App\Modules\Checkout\Actions\StartCheckout;
use App\Modules\Checkout\Data\ShippingAddress;
use App\Modules\Offers\Models\Offer;
use App\Modules\Orders\Models\MarketplaceOrder;
use Illuminate\Support\Str;

/**
 * A real placed order, built the way a customer builds one.
 *
 * The read-surface tests run against orders that went through the actual
 * cart, quote, reservation and placement path — a fixture that inserted
 * order rows directly could satisfy every assertion here while the real
 * checkout produced something different.
 */
trait BuildsPlacedOrders
{
    /** @param array<int, Offer|array{0: Offer, 1: int}> $offers */
    protected function placeOrder(array $offers, ?int $userId = null, string $email = 'buyer@example.test'): MarketplaceOrder
    {
        $cart = Cart::query()->create([
            'user_id' => $userId,
            'session_token' => $userId === null ? (string) Str::ulid() : null,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        foreach ($offers as $entry) {
            [$offer, $quantity] = is_array($entry) ? $entry : [$entry, 1];
            app(AddOfferToCart::class)($cart, $offer->public_id, $quantity);
        }

        $attempt = app(StartCheckout::class)(
            $cart,
            (string) Str::ulid(),
            $this->orderAddress(),
            $userId,
            $email,
        );

        return app(PlaceOrder::class)($attempt);
    }

    protected function orderAddress(): ShippingAddress
    {
        return new ShippingAddress(
            name: 'Ada Lovelace',
            line1: '12 Analytical Way',
            line2: null,
            city: 'London',
            state: null,
            postcode: 'EC1A 1BB',
            country: 'GB',
        );
    }
}

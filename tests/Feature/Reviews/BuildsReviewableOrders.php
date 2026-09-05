<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\CompleteDeliveredSellerOrders;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Reviews\Actions\SubmitReview;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Sellers\Models\SellerAccount;

/**
 * Purchases that are genuinely reviewable, built the long way.
 *
 * Every fixture here goes through the real chain — place, pay, ship,
 * deliver — because a review's verified badge rests on exactly that chain,
 * and a test that inserted a "delivered" row directly would prove the
 * badge can be produced by something other than a delivery, which is the
 * opposite of what needs proving.
 */
trait BuildsReviewableOrders
{
    /**
     * A customer who bought a product, paid for it, and took delivery.
     *
     * @return array{user: User, product: Product, order: MarketplaceOrder, sellerOrder: SellerOrder, seller: SellerAccount}
     */
    protected function deliveredPurchase(int $quantity = 1, int $priceMinor = 10_000): array
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(
            priceMinor: $priceMinor,
            stock: max(5, $quantity + 2),
        );

        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, $quantity]], (int) $user->id, $user->email);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        /** @var Product $product */
        $product = Product::query()->whereKey($offer->product_id)->firstOrFail();

        return [
            'user' => $user,
            'product' => $product,
            'order' => $order->refresh(),
            'sellerOrder' => $sellerOrder->refresh(),
            'seller' => $seller,
        ];
    }

    /** The same, carried on to COMPLETED by the clearing sweep. */
    protected function completeDelivered(): void
    {
        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();
    }

    protected function review(
        User $user,
        Product $product,
        int $rating = 5,
        string $body = 'Arrived quickly and works exactly as described.',
        ?string $title = 'Very good',
    ): ProductReview {
        return app(SubmitReview::class)(
            userId: (int) $user->id,
            productId: (int) $product->id,
            rating: $rating,
            body: $body,
            title: $title,
        );
    }
}

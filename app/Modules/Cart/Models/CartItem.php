<?php

declare(strict_types=1);

namespace App\Modules\Cart\Models;

use App\Modules\Offers\Models\Offer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of intent.
 *
 * References the seller's OFFER, not the canonical product: a customer
 * buying a kettle is buying one particular seller's kettle at one
 * particular price, and a cart that recorded only the product would have
 * to guess whose when it came to checkout.
 *
 * `line_identity` is what makes two lines the same line. Today it is the
 * offer and the variant; when a product carries an engraving or a
 * configured length, that goes into it too and nothing else changes —
 * which is why the uniqueness is on the identity rather than on the offer.
 */
final class CartItem extends Model
{
    protected $fillable = [
        'cart_id', 'offer_id', 'product_variant_id', 'line_identity',
        'quantity', 'unit_price_at_add_minor',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_at_add_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}

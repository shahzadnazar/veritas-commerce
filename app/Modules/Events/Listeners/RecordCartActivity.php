<?php

declare(strict_types=1);

namespace App\Modules\Events\Listeners;

use App\Modules\Cart\Events\CartLineAdded;
use App\Modules\Cart\Events\CartLineRemoved;
use App\Modules\Events\Actions\RecordInteraction;
use App\Modules\Events\Enums\InteractionEventType;

/**
 * Cart behaviour, turned into the analytics stream.
 *
 * The cart actions dispatch a domain event and know nothing about
 * analytics; the translation lives here, in the module that owns the
 * event table. That is what keeps the cart usable from a console command
 * or a queued job, where there is no request to attribute behaviour to.
 *
 * Add and remove carry the offer and the line's value, not just the
 * product: which seller a shopper chose at which price is the whole
 * question a marketplace's ranking has to answer later, and a
 * product-only event throws it away.
 */
final class RecordCartActivity
{
    public function __construct(private readonly RecordInteraction $interactions) {}

    public function added(CartLineAdded $event): void
    {
        $this->interactions->record(
            request(),
            InteractionEventType::CartItemAdded,
            productId: $event->productId,
            sellerAccountId: $event->sellerAccountId,
            payload: [
                'context' => 'cart',
                'quantity' => $event->quantity,
                'line_quantity' => $event->lineQuantity,
                'unit_price_minor' => $event->unitPriceMinor,
            ],
            offerId: $event->offerId,
            valueMinor: $event->valueMinor(),
        );
    }

    public function removed(CartLineRemoved $event): void
    {
        $this->interactions->record(
            request(),
            InteractionEventType::CartItemRemoved,
            productId: $event->productId,
            sellerAccountId: $event->sellerAccountId,
            payload: [
                'context' => 'cart',
                'quantity' => $event->quantity,
                'unit_price_minor' => $event->unitPriceMinor,
            ],
            offerId: $event->offerId,
            valueMinor: $event->valueMinor(),
        );
    }
}

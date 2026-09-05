<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Events\SellerOrderConfirmed;
use App\Modules\Orders\Events\SellerOrderProcessing;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\SellerOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The seller's two deliberate steps before anything is packed.
 *
 * Confirming is an acknowledgement: the seller has seen the order and
 * accepts it. Processing says they have started. Neither happens because
 * somebody opened a page — §9 is explicit, and the reason is that an order
 * which quietly says "processing" the moment a screen loads tells a
 * customer something nobody did.
 *
 * Both refuse an unpaid order outright, which is the payment boundary
 * again: `paid` is where fulfilment becomes possible, and everything
 * before it is a purchase that has not been paid for.
 */
final class AcknowledgeSellerOrder
{
    public function __construct(private readonly AdvanceSellerOrder $advance) {}

    /** @return bool whether this call was the one that confirmed it */
    public function confirm(SellerOrder $sellerOrder, string $actorType = 'seller', ?int $actorId = null): bool
    {
        return $this->move($sellerOrder, SellerOrderStatus::Confirmed, $actorType, $actorId);
    }

    /** @return bool whether this call was the one that started it */
    public function startProcessing(SellerOrder $sellerOrder, string $actorType = 'seller', ?int $actorId = null): bool
    {
        return $this->move($sellerOrder, SellerOrderStatus::Processing, $actorType, $actorId);
    }

    private function move(
        SellerOrder $sellerOrder,
        SellerOrderStatus $to,
        string $actorType,
        ?int $actorId,
    ): bool {
        if ($sellerOrder->status === SellerOrderStatus::PendingPayment) {
            throw FulfilmentRefused::notPaid();
        }

        $moved = ($this->advance)($sellerOrder, $to, actorType: $actorType, actorId: $actorId);

        if (! $moved) {
            return false;
        }

        $event = $to === SellerOrderStatus::Confirmed
            ? new SellerOrderConfirmed(
                sellerOrderId: $sellerOrder->id,
                sellerOrderReference: $sellerOrder->reference,
                sellerAccountId: (int) $sellerOrder->seller_account_id,
            )
            : new SellerOrderProcessing(
                sellerOrderId: $sellerOrder->id,
                sellerOrderReference: $sellerOrder->reference,
                sellerAccountId: (int) $sellerOrder->seller_account_id,
            );

        DB::afterCommit(static fn () => Event::dispatch($event));

        return true;
    }
}

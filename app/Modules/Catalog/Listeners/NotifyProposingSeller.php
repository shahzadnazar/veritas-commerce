<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Events\ProductApproved;
use App\Modules\Catalog\Events\ProductChangesRequested;
use App\Modules\Catalog\Events\ProductPublished;
use App\Modules\Catalog\Events\ProductRejected;
use App\Modules\Catalog\Events\ProductSuspended;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Notifications\ProductDecided;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;

/**
 * Tells the seller who proposed a product what a moderator decided.
 *
 * A listener rather than a line in the decision: the catalogue entry is
 * the transaction's job, telling someone about it is not, and a mail
 * server being down must never roll a moderation decision back. The events
 * are dispatched after commit, so this only runs for decisions that stuck.
 */
final class NotifyProposingSeller
{
    public function handle(
        ProductApproved|ProductPublished|ProductRejected|ProductChangesRequested|ProductSuspended $event,
    ): void {
        $product = Product::query()->find($event->productId);

        if ($product === null || $product->created_by_seller_account_id === null) {
            // Nothing to tell: a product the platform added itself has no
            // proposing seller.
            return;
        }

        $status = match (true) {
            $event instanceof ProductApproved => ProductStatus::Approved,
            $event instanceof ProductPublished => ProductStatus::Published,
            $event instanceof ProductRejected => ProductStatus::Rejected,
            $event instanceof ProductChangesRequested => ProductStatus::ChangesRequested,
            $event instanceof ProductSuspended => ProductStatus::Suspended,
        };

        // Publishing is the same news as approval to a seller, and both
        // usually happen in one request. Approval is the one that is sent;
        // publishing on its own — a suspended product restored, say — is
        // covered by the suspension notice being lifted.
        if ($status === ProductStatus::Published) {
            return;
        }

        $memberships = SellerMembership::query()
            ->where('seller_account_id', $product->created_by_seller_account_id)
            ->where('role', SellerRole::Owner->value)
            ->with('user')
            ->get();

        foreach ($memberships as $membership) {
            $membership->user?->notify(new ProductDecided(
                productTitle: $product->title,
                status: $status,
                reason: $product->moderation_reason,
            ));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Events\ProductApproved;
use App\Modules\Catalog\Events\ProductChangesRequested;
use App\Modules\Catalog\Events\ProductPublished;
use App\Modules\Catalog\Events\ProductRejected;
use App\Modules\Catalog\Events\ProductSuspended;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductProposalEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * The only path a product's moderation state changes by.
 *
 * Validating against the enum's own table means an illegal jump —
 * rejected straight to published, say — is refused wherever it is
 * attempted, and the history row is written in the same transaction as the
 * change it describes, so the two cannot disagree.
 */
final class TransitionProduct
{
    public function __invoke(
        Product $product,
        ProductStatus $to,
        string $actorType,
        ?int $actorId = null,
        ?string $reason = null,
    ): Product {
        $from = $product->status;

        if ($from === $to) {
            return $product;
        }

        if (! in_array($to, $from->allowedTransitions(), true)) {
            throw new RuntimeException("A product cannot move from {$from->value} to {$to->value}.");
        }

        if ($to->requiresReason() && trim((string) $reason) === '') {
            throw new RuntimeException("Moving a product to {$to->value} requires a written reason.");
        }

        return DB::transaction(function () use ($product, $from, $to, $actorType, $actorId, $reason): Product {
            $product->status = $to;

            // A reason belongs to the decision that needed one. Clearing it
            // on the way out stops a stale rejection note reappearing on a
            // product that has since been approved.
            $product->moderation_reason = $to->requiresReason() ? $reason : null;

            if ($to === ProductStatus::PendingReview) {
                $product->submitted_at = Carbon::now();
            }

            if (in_array($to, [ProductStatus::Approved, ProductStatus::Rejected, ProductStatus::ChangesRequested], true)) {
                $product->reviewed_at = Carbon::now();

                if ($actorType === 'admin') {
                    $product->reviewed_by_admin_id = $actorId;
                }
            }

            if ($to === ProductStatus::Published && $product->published_at === null) {
                $product->published_at = Carbon::now();
            }

            $product->save();

            ProductProposalEvent::query()->create([
                'product_id' => $product->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            // Dispatched after commit so no listener — indexing, mail,
            // analytics — ever sees a state a rollback removed.
            DB::afterCommit(function () use ($product, $to, $actorId, $reason): void {
                $event = match ($to) {
                    ProductStatus::Approved => new ProductApproved($product->id, $actorId),
                    ProductStatus::Published => new ProductPublished($product->id, $actorId),
                    ProductStatus::Rejected => new ProductRejected($product->id, $actorId, $reason),
                    ProductStatus::ChangesRequested => new ProductChangesRequested($product->id, $actorId, $reason),
                    ProductStatus::Suspended => new ProductSuspended($product->id, $actorId, $reason),
                    default => null,
                };

                if ($event !== null) {
                    Event::dispatch($event);
                }
            });

            return $product;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The negative decisions: reject, ask for changes, suspend.
 *
 * All three take a reason, all three are recorded, and all three are
 * distinct outcomes. Telling a seller their product was rejected when one
 * field needs correcting is untrue and unrecoverable in the reporting
 * afterwards, which is why changes_requested is its own state rather than
 * a rejection with softer wording.
 */
final class DecideProduct
{
    public function __construct(
        private readonly TransitionProduct $transition,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function reject(Product $product, int $adminId, string $reason): Product
    {
        return $this->decide($product, ProductStatus::Rejected, 'catalogue.product.rejected', $adminId, $reason);
    }

    public function requestChanges(Product $product, int $adminId, string $reason): Product
    {
        return $this->decide($product, ProductStatus::ChangesRequested, 'catalogue.product.changes_requested', $adminId, $reason);
    }

    public function suspend(Product $product, int $adminId, string $reason): Product
    {
        return $this->decide($product, ProductStatus::Suspended, 'catalogue.product.suspended', $adminId, $reason);
    }

    private function decide(Product $product, ProductStatus $to, string $action, int $adminId, string $reason): Product
    {
        return DB::transaction(function () use ($product, $to, $action, $adminId, $reason): Product {
            $from = $product->status;
            $decided = ($this->transition)($product, $to, 'admin', $adminId, $reason);

            ($this->audit)(
                action: $action,
                actorType: 'admin',
                actorId: $adminId,
                subjectType: Product::class,
                subjectId: $decided->id,
                changes: ['status' => ['from' => $from->value, 'to' => $to->value]],
                reason: $reason,
            );

            return $decided;
        });
    }
}

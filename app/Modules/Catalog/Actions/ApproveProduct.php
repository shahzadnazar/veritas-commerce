<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * A moderator accepting a proposed product into the catalogue.
 *
 * IDEMPOTENT. Two moderators clicking Approve on the same proposal, a
 * retried request or a redelivered job must produce one catalogue entry
 * and one approval, not two. Three things guarantee that:
 *
 *   1. The product row is locked FOR UPDATE, so concurrent calls
 *      serialise rather than interleave.
 *   2. An already-approved product returns as it is, with no second
 *      history row and no second event.
 *   3. The transition itself validates against the state machine, so even
 *      a path that skipped this action cannot approve twice.
 *
 * Publishing is deliberately a separate step. Accepting a product into the
 * catalogue and putting it on the storefront are different decisions, and
 * a marketplace that conflates them cannot stage a launch.
 */
final class ApproveProduct
{
    public function __construct(
        private readonly TransitionProduct $transition,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function __invoke(Product $product, int $adminId, bool $publish = false): Product
    {
        return DB::transaction(function () use ($product, $adminId, $publish): Product {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            // The branch a double-click takes. It must be a no-op.
            if (in_array($locked->status, [ProductStatus::Approved, ProductStatus::Published], true)) {
                return $publish && $locked->status === ProductStatus::Approved
                    ? ($this->transition)($locked, ProductStatus::Published, 'admin', $adminId)
                    : $locked;
            }

            $approved = ($this->transition)($locked, ProductStatus::Approved, 'admin', $adminId);

            ($this->audit)(
                action: 'catalogue.product.approved',
                actorType: 'admin',
                actorId: $adminId,
                subjectType: Product::class,
                subjectId: $approved->id,
                changes: ['title' => $approved->title, 'proposed_by' => $approved->created_by_seller_account_id],
            );

            if ($publish) {
                $approved = ($this->transition)($approved, ProductStatus::Published, 'admin', $adminId);
            }

            return $approved;
        });
    }
}

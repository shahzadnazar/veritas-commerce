<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Queries;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The catalogue moderation queue.
 *
 * Defaults to what is waiting on the team rather than everything ever
 * proposed: a queue that opens on ten thousand published products is a
 * list, not a queue.
 */
final class ProductModerationQueue
{
    /** @return LengthAwarePaginator<int, Product> */
    public function __invoke(Request $request): LengthAwarePaginator
    {
        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());

        return Product::query()
            ->with(['brand', 'category', 'proposedBy'])
            ->when(
                $status === '',
                fn (Builder $query) => $query->where('status', ProductStatus::PendingReview->value),
                fn (Builder $query) => $status === 'all' ? $query : $query->where('status', $status),
            )
            ->when(
                $request->filled('category'),
                fn (Builder $query) => $query->where('category_id', $request->integer('category')),
            )
            ->when(
                $request->filled('seller'),
                fn (Builder $query) => $query->where('created_by_seller_account_id', $request->integer('seller')),
            )
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('title', 'ilike', '%'.$search.'%')
                        ->orWhere('gtin', $search)
                        ->orWhere('ean', $search)
                        ->orWhere('upc', $search)
                        ->orWhere('mpn', $search);
                });
            })
            // Oldest first: a proposal that has waited longest is the one
            // a seller is most likely wondering about.
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Queries;

use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Models\SellerApplication;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The reviewer's working list.
 *
 * Filters are URL query parameters so a filtered queue is shareable and
 * survives the back button — the same rule the design system sets for
 * every table in the product.
 */
final class SellerApplicationQueue
{
    /** @return LengthAwarePaginator<int, SellerApplication> */
    public function __invoke(?string $status = null, ?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        return SellerApplication::query()
            ->with('reviewer')
            ->when(
                $status !== null && $status !== 'all',
                fn ($query) => $query->where('status', $status),
                // The default view is work waiting on the team, not
                // everything ever submitted.
                fn ($query) => $query->whereIn('status', [
                    SellerApplicationStatus::Submitted->value,
                    SellerApplicationStatus::UnderReview->value,
                    SellerApplicationStatus::ChangesRequested->value,
                ]),
            )
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                $term = '%'.strtolower((string) $search).'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->whereRaw('lower(legal_name) like ?', [$term])
                        ->orWhereRaw('lower(trading_name) like ?', [$term])
                        ->orWhereRaw('lower(contact_email) like ?', [$term])
                        ->orWhereRaw('lower(reference) like ?', [$term]);
                });
            })
            ->orderByRaw('coalesce(submitted_at, created_at) asc')
            ->paginate($perPage)
            ->withQueryString();
    }
}

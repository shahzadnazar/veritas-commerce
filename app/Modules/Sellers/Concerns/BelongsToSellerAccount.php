<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Layer 1 of seller isolation: a global scope every seller-owned model
 * carries, so a query written without a where clause still cannot see
 * another seller's rows.
 *
 * Layer 2 is route binding that 404s rather than 403s on a foreign record —
 * a 403 confirms the record exists, which is itself a leak. Layer 3 is
 * tests/Invariants/SellerIsolationTest, which actively tries to cross the
 * boundary on every seller-scoped route.
 */
trait BelongsToSellerAccount
{
    public static function bootBelongsToSellerAccount(): void
    {
        static::addGlobalScope('seller_account', function (Builder $query): void {
            $sellerId = CurrentSeller::id();

            if ($sellerId !== null) {
                // Qualified with the table name so a joined query cannot
                // make the column ambiguous. It goes to the underlying
                // query builder because `table.column` is a SQL reference,
                // not one of the model's own attributes.
                $query->getQuery()->where(
                    $query->getModel()->getTable().'.seller_account_id',
                    $sellerId,
                );
            }
        });

        static::creating(function (Model $model): void {
            $sellerId = CurrentSeller::id();

            // While acting as a seller, every row created belongs to that
            // seller — a supplied seller_account_id is overruled rather than
            // trusted, so a hand-edited request cannot plant a record in
            // another seller's account.
            //
            // With no acting seller (admin paths, system jobs, seeders) the
            // caller supplies the owner explicitly.
            if ($sellerId !== null) {
                $model->setAttribute('seller_account_id', $sellerId);

                return;
            }

            if (blank($model->getAttribute('seller_account_id'))) {
                throw new RuntimeException(
                    $model::class.' needs a seller_account_id: set one explicitly, or create it inside CurrentSeller::actingAs().'
                );
            }
        });
    }
}

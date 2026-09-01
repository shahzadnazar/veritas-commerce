<?php

declare(strict_types=1);

namespace App\Modules\Stores\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Stores\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates the acting seller's store.
 *
 * The store is always resolved from the acting membership, so there is no
 * path where a store id in the request decides which store is written.
 * A slug change records the old address, which keeps the redirect working
 * and keeps the seller's search equity with them.
 */
final class UpdateStore
{
    public function __construct(
        private readonly ObjectStore $objects,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{logo?: UploadedFile|null, banner?: UploadedFile|null}  $images
     */
    public function __invoke(int $sellerAccountId, array $attributes, array $images = []): Store
    {
        return CurrentSeller::actingAs($sellerAccountId, fn (): Store => DB::transaction(function () use ($sellerAccountId, $attributes, $images): Store {
            $store = Store::query()->where('seller_account_id', $sellerAccountId)->lockForUpdate()->first()
                ?? new Store(['seller_account_id' => $sellerAccountId]);

            $previousSlug = $store->exists ? $store->slug : null;
            $changed = [];

            foreach (['name', 'slug', 'description', 'support_email', 'support_phone',
                'shipping_policy', 'return_policy', 'timezone', 'business_city',
                'business_state', 'business_country', 'is_open'] as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }

                if ($store->exists && $store->{$field} !== $attributes[$field]) {
                    $changed[$field] = ['from' => $store->{$field}, 'to' => $attributes[$field]];
                }

                $store->{$field} = $attributes[$field];
            }

            foreach (['logo' => 'logo_media_id', 'banner' => 'banner_media_id'] as $input => $column) {
                $file = $images[$input] ?? null;

                if ($file instanceof UploadedFile) {
                    $stored = $this->objects->put(
                        $file,
                        "stores/{$sellerAccountId}/{$input}",
                        Visibility::Public,
                    );

                    $store->{$column} = $stored->reference();
                    // The reference changes, never the bytes: the record
                    // says a replacement happened, not what was in it.
                    $changed[$column] = ['from' => 'previous', 'to' => 'replaced'];
                }
            }

            $store->save();

            if ($previousSlug !== null && $previousSlug !== $store->slug) {
                // The old address keeps working, permanently.
                DB::table('store_slug_history')->insert([
                    'store_id' => $store->id,
                    'old_slug' => $previousSlug,
                    'changed_at' => Carbon::now(),
                ]);
            }

            if ($changed !== []) {
                ($this->audit)(
                    action: 'seller.store.updated',
                    actorType: 'seller',
                    actorId: $sellerAccountId,
                    subjectType: Store::class,
                    subjectId: $store->id,
                    changes: $changed,
                );
            }

            return $store;
        }));
    }
}

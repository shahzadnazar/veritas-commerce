<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Support\CatalogueText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds an existing brand or proposes a new one.
 *
 * The failure this prevents is a catalogue holding "Apple", "APPLE",
 * "apple" and "Apple Inc." as four brands, which makes brand filters
 * useless and brand pages worthless. Matching is on the normalised name,
 * and a unique index on that column is what actually enforces it.
 *
 * A seller cannot mint a live brand. What they can do is propose one,
 * which arrives unapproved and invisible until a moderator accepts it.
 */
final class ResolveBrand
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function find(string $name): ?Brand
    {
        $normalised = CatalogueText::normalise($name);

        return $normalised === ''
            ? null
            : Brand::query()->where('normalised_name', $normalised)->first();
    }

    /**
     * Propose a brand a seller could not find.
     *
     * Idempotent by the same normalisation: two sellers proposing "Aeris"
     * and "AERIS" on the same day get one pending brand between them.
     */
    public function propose(string $name, int $sellerAccountId): Brand
    {
        $normalised = CatalogueText::normalise($name);

        return DB::transaction(function () use ($name, $normalised, $sellerAccountId): Brand {
            $existing = Brand::query()->where('normalised_name', $normalised)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                $brand = Brand::query()->create([
                    'name' => trim($name),
                    'normalised_name' => $normalised,
                    'slug' => $this->uniqueSlug($normalised),
                    'proposed_by_seller_account_id' => $sellerAccountId,
                    // Proposed, not live: it appears on no filter and no
                    // brand page until a moderator approves it.
                    'is_active' => false,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Lost the race. The index did its job; hand back the
                // winner rather than failing the seller's whole form.
                return Brand::query()->where('normalised_name', $normalised)->firstOrFail();
            }

            ($this->audit)(
                action: 'catalogue.brand.proposed',
                actorType: 'seller',
                actorId: $sellerAccountId,
                subjectType: Brand::class,
                subjectId: $brand->id,
                changes: ['name' => $brand->name],
            );

            return $brand;
        });
    }

    public function approve(Brand $brand, int $adminId): Brand
    {
        return DB::transaction(function () use ($brand, $adminId): Brand {
            $brand->forceFill(['is_active' => true, 'approved_at' => now()])->save();

            ($this->audit)(
                action: 'catalogue.brand.approved',
                actorType: 'admin',
                actorId: $adminId,
                subjectType: Brand::class,
                subjectId: $brand->id,
                changes: ['name' => $brand->name],
            );

            return $brand;
        });
    }

    private function uniqueSlug(string $normalised): string
    {
        $base = Str::slug($normalised) ?: 'brand';
        $slug = $base;
        $suffix = 2;

        while (Brand::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}

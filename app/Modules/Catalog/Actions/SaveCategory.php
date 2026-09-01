<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Catalog\Models\Category;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates or moves a category, keeping the tree a tree.
 *
 * Two things can break a hierarchy: a category becoming its own ancestor,
 * and a stored path drifting from the parent links it summarises. Both are
 * handled here, in one transaction, because a half-moved subtree is worse
 * than a refused move.
 */
final class SaveCategory
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /** @param  array<string, mixed>  $attributes */
    public function __invoke(?Category $category, array $attributes, string $actorType, int $actorId): Category
    {
        return DB::transaction(function () use ($category, $attributes, $actorType, $actorId): Category {
            $parentId = $attributes['parent_id'] ?? $category?->parent_id;
            $parent = $parentId === null
                ? null
                : Category::query()->whereKey($parentId)->lockForUpdate()->first();

            if ($parentId !== null && $parent === null) {
                throw new RuntimeException('That parent category does not exist.');
            }

            if ($category !== null && $parent !== null) {
                $this->assertNoCycle($category, $parent);
            }

            $before = $category?->only(['name', 'slug', 'parent_id', 'is_visible']);

            $category ??= new Category;
            $category->fill($attributes);
            $category->parent_id = $parentId;

            // Depth and path are derived, never supplied: a caller that
            // could set them could also lie about them.
            $category->depth = $parent === null ? 0 : $parent->depth + 1;
            $category->save();

            $category->path = ($parent === null ? '' : rtrim((string) $parent->path, '/')).'/'.$category->id;
            $category->save();

            // Moving a category moves everything under it. Done here so a
            // descendant's path can never describe a parent it no longer
            // has.
            $this->repath($category);

            ($this->audit)(
                action: $before === null ? 'catalogue.category.created' : 'catalogue.category.updated',
                actorType: $actorType,
                actorId: $actorId,
                subjectType: Category::class,
                subjectId: $category->id,
                changes: [
                    'before' => $before,
                    'after' => $category->only(['name', 'slug', 'parent_id', 'is_visible']),
                ],
            );

            return $category;
        });
    }

    /**
     * A category may not be moved beneath itself.
     *
     * The database rejects `parent_id = id`; anything deeper needs the
     * ancestry, which is what the stored path is for.
     */
    private function assertNoCycle(Category $category, Category $parent): void
    {
        if ($parent->id === $category->id) {
            throw new RuntimeException('A category cannot be its own parent.');
        }

        if (in_array($category->id, $parent->ancestorIds(), true)) {
            throw new RuntimeException(
                "Moving {$category->name} under {$parent->name} would make it its own ancestor."
            );
        }
    }

    /** Rewrite the path and depth of everything below this category. */
    private function repath(Category $category): void
    {
        $children = Category::query()->where('parent_id', $category->id)->get();

        foreach ($children as $child) {
            $child->depth = $category->depth + 1;
            $child->path = rtrim((string) $category->path, '/').'/'.$child->id;
            $child->save();

            $this->repath($child);
        }
    }
}

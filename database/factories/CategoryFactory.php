<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Category> */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = implode(' ', array_map(fn (): string => $this->faker->unique()->word(), range(1, 2)));

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'is_visible' => true,
            'position' => 0,
        ];
    }

    /**
     * Derive path and depth the way SaveCategory does.
     *
     * `ancestorIds()` reads the stored path, so a factory-built child with
     * no path reports no ancestors — and every test about category
     * inheritance or descendant listings silently measures a flat tree
     * instead of the one it built.
     */
    public function configure(): self
    {
        return $this->afterCreating(function (Category $category): void {
            $parent = $category->parent_id === null
                ? null
                : Category::query()->find($category->parent_id);

            $category->forceFill([
                'depth' => $parent === null ? 0 : $parent->depth + 1,
                'path' => ($parent === null ? '' : rtrim((string) $parent->path, '/')).'/'.$category->id,
            ])->save();
        });
    }
}

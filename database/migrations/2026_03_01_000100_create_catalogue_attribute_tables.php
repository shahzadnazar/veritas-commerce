<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Category taxonomy and the attribute schema.
 *
 * Different categories describe their products differently — a phone has
 * storage, a sofa has depth — so the set of specifications is data, not a
 * column list. What it is NOT is a single unvalidated JSON blob: each
 * value lands in a typed column, so "phones with 256GB storage under
 * £900" stays an indexable query rather than a full-table scan through
 * documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            // A category is a page as much as a filter, so it carries the
            // same SEO identity a product does.
            $table->string('seo_title')->nullable()->after('description');
            $table->string('seo_description', 320)->nullable()->after('seo_title');

            // Denormalised ancestry, maintained by the domain, so a
            // breadcrumb or a "everything under Electronics" query is one
            // read rather than a recursive walk.
            $table->string('path')->nullable()->after('slug');
            $table->unsignedSmallInteger('depth')->default(0)->after('path');

            $table->index('path');
        });

        // A category may not be its own parent. Deeper cycles are caught by
        // the domain (a database CHECK cannot walk a tree), but this closes
        // the one case a single UPDATE could otherwise create.
        DB::statement('alter table categories add constraint categories_not_own_parent check (parent_id is null or parent_id <> id)');

        Schema::create('attributes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            // The stable identifier used in code and in query strings;
            // the name is what a person reads and may be reworded.
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('data_type', 16);
            // "GB", "mm", "kg" — rendered after the value, never parsed
            // out of it.
            $table->string('unit', 24)->nullable();

            // Whether this attribute earns a place in a filter rail, a
            // search index, or the set that distinguishes one variant from
            // another. All three are catalogue policy, not presentation.
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_variant_defining')->default(false);

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_filterable', 'is_active']);
        });

        // The permitted values of a select or multi-select attribute.
        Schema::create('attribute_options', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->string('label');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'value']);
        });

        // Which attributes a category asks for, and which it insists on.
        // The same attribute can be required in one category and optional
        // in another — "material" matters more for a sofa than a cable.
        Schema::create('category_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_variant_defining')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'attribute_id']);
            $table->index(['category_id', 'position']);
        });

        /*
         * One row per product per attribute, with the value in the column
         * that matches its type.
         *
         * Typed columns rather than a JSON document because these are the
         * values people filter and sort by: a decimal screen size has to
         * compare as a number, and a select has to join to its option so
         * renaming "Space Grey" does not orphan ten thousand products.
         */
        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->restrictOnDelete();
            // Null for a product-level value; set for a variant's own.
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->text('value_text')->nullable();
            $table->bigInteger('value_int')->nullable();
            $table->decimal('value_decimal', 18, 6)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->foreignId('attribute_option_id')->nullable()->constrained()->restrictOnDelete();

            $table->timestamps();

            // One value per attribute per product, or per variant where the
            // attribute varies. Postgres treats NULLs as distinct in a
            // unique index, so the product-level row needs its own partial
            // index to be constrained at all.
            $table->unique(
                ['product_id', 'product_variant_id', 'attribute_id'],
                'product_attribute_values_variant_unique',
            );

            $table->index(['attribute_id', 'value_int']);
            $table->index(['attribute_id', 'value_decimal']);
            $table->index(['attribute_id', 'attribute_option_id']);
        });

        DB::statement(
            'create unique index product_attribute_values_product_unique
             on product_attribute_values (product_id, attribute_id)
             where product_variant_id is null'
        );

        // Exactly one value column may be set, and at least one must be:
        // a row that says nothing is a specification nobody wrote.
        DB::statement(
            'alter table product_attribute_values add constraint product_attribute_values_one_value check (
                (case when value_text is not null then 1 else 0 end)
              + (case when value_int is not null then 1 else 0 end)
              + (case when value_decimal is not null then 1 else 0 end)
              + (case when value_boolean is not null then 1 else 0 end)
              + (case when value_date is not null then 1 else 0 end)
              + (case when attribute_option_id is not null then 1 else 0 end)
              = 1
            )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('category_attributes');
        Schema::dropIfExists('attribute_options');
        Schema::dropIfExists('attributes');

        DB::statement('alter table categories drop constraint if exists categories_not_own_parent');

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex(['path']);
            $table->dropColumn(['seo_title', 'seo_description', 'path', 'depth']);
        });
    }
};

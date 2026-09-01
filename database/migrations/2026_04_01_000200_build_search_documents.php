<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The search document grows into a real discovery record.
 *
 * M2 left a title, a flat searchable string and a price, which was enough
 * to prove the port. M3 has to rank, filter, facet and suggest over it, and
 * every one of those becomes a join back to the catalogue unless the
 * document already carries the answer. So it does: category lineage,
 * brand, condition set, filterable attributes, availability and the few
 * fields a product card needs to render without touching another table.
 *
 * Two indexing strategies, because they answer different questions:
 *
 *   - A WEIGHTED tsvector for relevance. Title is weight A, brand B,
 *     category C, everything else D, so a phrase in the title outranks the
 *     same phrase buried in a description.
 *   - A pg_trgm index on the title for typo tolerance. "iphnoe" shares
 *     trigrams with "iphone" and matches nothing in a tsvector.
 *
 * Both live behind SearchIndex. Replacing PostgreSQL with OpenSearch
 * remains a second adapter and no change to any caller.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Trigram similarity, for fuzzy matching. Enabled in a migration so
        // CI and every developer database get it without a manual step.
        DB::statement('create extension if not exists pg_trgm');

        Schema::table('product_search_documents', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('title');
            $table->string('normalised_title')->nullable()->after('slug');

            $table->foreignId('category_id')->nullable()->after('category_path')
                ->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->after('category_id')
                ->constrained()->nullOnDelete();

            // Prices span the eligible offers, so a card can show a range
            // without asking the offers table.
            $table->bigInteger('highest_price_minor')->nullable()->after('lowest_price_minor');
            $table->string('currency', 3)->nullable()->after('highest_price_minor');

            // Availability, denormalised. §42 forbids a per-result
            // inventory query, and this is what makes that possible.
            $table->boolean('in_stock')->default(false)->after('offer_count');
            $table->unsignedInteger('in_stock_offer_count')->default(0)->after('in_stock');

            // What a product card renders. Carried here so a page of 24
            // results is one query rather than 24 media lookups.
            $table->string('primary_image_disk')->nullable()->after('in_stock_offer_count');
            $table->string('primary_image_path')->nullable()->after('primary_image_disk');
            $table->string('primary_image_alt')->nullable()->after('primary_image_path');

            // "Newest" sorts on this rather than on the row's own age: a
            // reindex must not reorder the catalogue.
            $table->timestamp('published_at')->nullable()->after('primary_image_alt');
        });

        /*
         * Ancestor ids as an array.
         *
         * A category page shows everything beneath it, and asking "is this
         * product in this category or any of its descendants" as an array
         * containment test against a GIN index is a single indexed
         * operation. The alternative — a recursive CTE per request — is
         * the query that makes category pages slow.
         */
        DB::statement('alter table product_search_documents add column category_ancestor_ids bigint[] not null default \'{}\'');

        // The conditions the eligible offers are actually in, for the
        // condition facet.
        DB::statement('alter table product_search_documents add column conditions text[] not null default \'{}\'');

        // Identifiers, for an exact barcode lookup that must outrank
        // everything else.
        DB::statement('alter table product_search_documents add column identifiers text[] not null default \'{}\'');

        /*
         * Filterable attributes, as jsonb.
         *
         * Only attributes a moderator marked filterable land here, and
         * they are stored as code => array of values so a multi-select
         * attribute has somewhere to put its second value. Containment
         * queries against a GIN index handle the filtering; jsonb_each
         * handles the facet counts.
         */
        DB::statement("alter table product_search_documents add column attributes jsonb not null default '{}'::jsonb");

        /*
         * The weighted vector replaces the flat one.
         *
         * setweight is what makes relevance explainable: a title hit is an
         * A, a brand hit a B, and ts_rank returns them in that order
         * without a hand-tuned score anywhere in PHP.
         */
        DB::statement('alter table product_search_documents drop column if exists search_vector');
        DB::statement("
            alter table product_search_documents
                add column search_vector tsvector
                generated always as (
                    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(brand_name, '')), 'B') ||
                    setweight(to_tsvector('english', coalesce(category_path, '')), 'C') ||
                    setweight(to_tsvector('english', coalesce(searchable_text, '')), 'D')
                ) stored
        ");

        DB::statement('create index product_search_documents_vector on product_search_documents using gin (search_vector)');

        // Typo tolerance. Trigram similarity over the normalised title, so
        // "samsng" still reaches "Samsung".
        DB::statement('create index product_search_documents_title_trgm on product_search_documents using gin (normalised_title gin_trgm_ops)');

        DB::statement('create index product_search_documents_ancestors on product_search_documents using gin (category_ancestor_ids)');
        DB::statement('create index product_search_documents_conditions on product_search_documents using gin (conditions)');
        DB::statement('create index product_search_documents_identifiers on product_search_documents using gin (identifiers)');
        DB::statement('create index product_search_documents_attributes on product_search_documents using gin (attributes)');

        DB::statement('drop index if exists product_search_documents_public');
        // The shape of nearly every discovery query: public rows, filtered,
        // ordered by price or recency.
        DB::statement('create index product_search_documents_discovery on product_search_documents (is_public, in_stock, lowest_price_minor)');
        DB::statement('create index product_search_documents_newest on product_search_documents (is_public, published_at desc)');
        DB::statement('create index product_search_documents_brand on product_search_documents (is_public, brand_id)');
    }

    public function down(): void
    {
        foreach ([
            'product_search_documents_vector',
            'product_search_documents_title_trgm',
            'product_search_documents_ancestors',
            'product_search_documents_conditions',
            'product_search_documents_identifiers',
            'product_search_documents_attributes',
            'product_search_documents_discovery',
            'product_search_documents_newest',
            'product_search_documents_brand',
        ] as $index) {
            DB::statement("drop index if exists {$index}");
        }

        DB::statement('alter table product_search_documents drop column if exists search_vector');
        DB::statement('alter table product_search_documents drop column if exists attributes');
        DB::statement('alter table product_search_documents drop column if exists identifiers');
        DB::statement('alter table product_search_documents drop column if exists conditions');
        DB::statement('alter table product_search_documents drop column if exists category_ancestor_ids');

        Schema::table('product_search_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn([
                'slug', 'normalised_title', 'highest_price_minor', 'currency',
                'in_stock', 'in_stock_offer_count', 'primary_image_disk',
                'primary_image_path', 'primary_image_alt', 'published_at',
            ]);
        });

        DB::statement(
            "alter table product_search_documents
             add column search_vector tsvector
             generated always as (to_tsvector('english', searchable_text)) stored"
        );

        DB::statement('create index product_search_documents_vector on product_search_documents using gin (search_vector)');
        DB::statement('create index product_search_documents_public on product_search_documents (is_public, lowest_price_minor)');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical product: moderation, identifiers, media, slug history and
 * enough of a merge story that a future deduplication cannot be blocked by
 * this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('slug');
            $table->string('logo_media_id')->nullable()->after('description');
            $table->string('seo_title')->nullable()->after('logo_media_id');
            $table->string('seo_description', 320)->nullable()->after('seo_title');

            // The normalised form of the name, used to stop "Apple",
            // "APPLE" and "apple  " becoming three brands. Not the slug:
            // a slug is a URL and may be edited for other reasons.
            $table->string('normalised_name')->nullable()->after('name');
            $table->foreignId('proposed_by_seller_account_id')->nullable()
                ->after('owner_seller_account_id')->constrained('seller_accounts')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('is_active');
        });

        DB::statement('update brands set normalised_name = lower(regexp_replace(name, \'\s+\', \' \', \'g\'))');
        DB::statement('alter table brands alter column normalised_name set not null');
        DB::statement('create unique index brands_normalised_name_unique on brands (normalised_name)');

        Schema::table('products', function (Blueprint $table): void {
            // Replaces is_active: a boolean cannot say the difference
            // between "not yet reviewed", "refused" and "withdrawn".
            $table->string('status', 32)->default('draft')->after('slug');
            $table->text('moderation_reason')->nullable()->after('status');
            $table->foreignId('reviewed_by_admin_id')->nullable()->after('moderation_reason')
                ->constrained('admin_users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('reviewed_by_admin_id');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->timestamp('published_at')->nullable()->after('reviewed_at');

            // Trade identifiers. A product may have several kinds, or none:
            // a handmade bowl has no GTIN and requiring one would exclude
            // exactly the sellers the marketplace wants.
            $table->string('upc', 12)->nullable()->after('gtin');
            $table->string('ean', 13)->nullable()->after('upc');
            $table->string('isbn', 17)->nullable()->after('ean');
            $table->string('model_number')->nullable()->after('mpn');

            // A normalised title for deterministic duplicate detection:
            // lowercase, collapsed whitespace, punctuation removed.
            $table->string('normalised_title')->nullable()->after('title');

            /*
             * Merge future-proofing.
             *
             * Full deduplication is not M2's job, but the schema must not
             * make it impossible. A product superseded by another keeps its
             * row, its offers and its slug history; reads follow this
             * pointer, and the old URL 301s to the survivor. Nothing is
             * deleted, so SEO authority and media provenance survive.
             */
            $table->foreignId('merged_into_product_id')->nullable()->after('published_at')
                ->constrained('products')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_into_product_id');

            $table->index(['status', 'category_id']);
            $table->index('normalised_title');
            $table->index('merged_into_product_id');
        });

        DB::statement("update products set status = case when is_active then 'published' else 'draft' end");
        DB::statement('update products set published_at = created_at where status = \'published\'');
        DB::statement('update products set normalised_title = lower(regexp_replace(title, \'\s+\', \' \', \'g\'))');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });

        // A product cannot be merged into itself, and a merge must record
        // when it happened.
        DB::statement('alter table products add constraint products_not_merged_into_self check (merged_into_product_id is null or merged_into_product_id <> id)');
        DB::statement('alter table products add constraint products_merge_is_dated check ((merged_into_product_id is null) = (merged_at is null))');

        // Identifiers are unique when present. Partial indexes, because a
        // handmade product legitimately has none and NULLs must not
        // collide with each other.
        foreach (['upc', 'ean', 'isbn'] as $identifier) {
            DB::statement("create unique index products_{$identifier}_unique on products ({$identifier}) where {$identifier} is not null");
        }

        // Renaming a product must not cost its search position.
        Schema::create('product_slug_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('old_slug');
            $table->timestamp('changed_at');

            $table->unique('old_slug');
            $table->index('product_id');
        });

        /*
         * M0 left a placeholder here: a path, an alt string and a flag.
         * Real media needs the disk it lives on, what the bytes are, how
         * large the image is, and whether it has been processed yet.
         */
        Schema::table('product_media', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->after('id');
            // Set when the image belongs to one variant rather than the
            // product as a whole.
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained()->cascadeOnDelete();

            $table->string('disk')->nullable()->after('product_variant_id');
            $table->string('mime', 128)->nullable()->after('path');
            $table->unsignedBigInteger('bytes')->default(0)->after('mime');
            $table->unsignedInteger('width')->nullable()->after('bytes');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->string('checksum', 64)->nullable()->after('height');

            // Written by the seller and read aloud by a screen reader, so
            // it is content rather than metadata.
            $table->string('alt_text', 255)->nullable()->after('checksum');

            // Processing happens on a queue, so an image exists before it
            // is ready to show.
            $table->string('processing_state', 24)->default('pending')->after('is_main');
            $table->timestamp('processed_at')->nullable()->after('processing_state');
        });

        DB::statement('update product_media set disk = ? where disk is null', [config('veritas.storage.public_disk')]);
        DB::statement('update product_media set alt_text = alt where alt_text is null');
        DB::statement('alter table product_media alter column disk set not null');

        Schema::table('product_media', function (Blueprint $table): void {
            // `alt` and `is_main` were the placeholder's names; the real
            // ones say what they are.
            $table->dropColumn('alt');
            $table->renameColumn('is_main', 'is_primary');
        });

        // One primary image per product. A gallery with two "first" images
        // has no first image.
        DB::statement(
            'create unique index product_media_one_primary
             on product_media (product_id)
             where is_primary and product_variant_id is null'
        );

        // Who proposed what, kept as provenance. The canonical product
        // belongs to the marketplace once approved; this records where it
        // came from without implying ownership.
        Schema::create('product_proposal_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['product_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_proposal_events');
        Schema::dropIfExists('product_slug_history');

        DB::statement('drop index if exists product_media_one_primary');

        Schema::table('product_media', function (Blueprint $table): void {
            $table->renameColumn('is_primary', 'is_main');
            $table->string('alt')->nullable();
        });

        Schema::table('product_media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn([
                'public_id', 'disk', 'mime', 'bytes', 'width', 'height',
                'checksum', 'alt_text', 'processing_state', 'processed_at',
            ]);
        });

        foreach (['upc', 'ean', 'isbn'] as $identifier) {
            DB::statement("drop index if exists products_{$identifier}_unique");
        }

        DB::statement('alter table products drop constraint if exists products_not_merged_into_self');
        DB::statement('alter table products drop constraint if exists products_merge_is_dated');

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });

        DB::statement("update products set is_active = (status = 'published')");

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by_admin_id');
            $table->dropConstrainedForeignId('merged_into_product_id');
            $table->dropIndex(['status', 'category_id']);
            $table->dropIndex(['normalised_title']);
            $table->dropColumn([
                'status', 'moderation_reason', 'submitted_at', 'reviewed_at',
                'published_at', 'upc', 'ean', 'isbn', 'model_number',
                'normalised_title', 'merged_at',
            ]);
        });

        DB::statement('drop index if exists brands_normalised_name_unique');

        Schema::table('brands', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proposed_by_seller_account_id');
            $table->dropColumn([
                'description', 'logo_media_id', 'seo_title', 'seo_description',
                'normalised_name', 'approved_at',
            ]);
        });
    }
};

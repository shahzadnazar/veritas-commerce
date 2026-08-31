<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'position']);
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            // Null owner means platform-wide: any seller may list against it.
            $table->foreignId('owner_seller_account_id')->nullable()->constrained('seller_accounts')->cascadeOnDelete();
            $table->foreignId('merged_into_brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // A merge keeps a permanent alias so old URLs survive.
        Schema::create('brand_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->timestamp('created_at');
        });

        /*
         * The canonical catalogue product: shared identity, owned by the
         * platform rather than any seller.
         *
         *   Apple iPhone 17 Pro 256GB          <- one product
         *     Seller A  $1,199                 <- three offers
         *     Seller B  $1,175
         *     Seller C  $1,220
         *
         * A one-of-a-kind handmade item is the same shape with a single
         * offer, so unique goods and commodity goods share one model.
         */
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Global trade identifiers, where the product has them. Unique
            // when present so two sellers cannot fork the same GTIN into two
            // catalogue entries.
            $table->string('gtin', 14)->nullable()->unique();
            $table->string('mpn')->nullable();

            $table->jsonb('specifications')->nullable();
            $table->jsonb('attributes')->nullable();

            // Created by a seller listing something new; still platform-owned.
            $table->foreignId('created_by_seller_account_id')->nullable()->constrained('seller_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
            $table->index('brand_id');
        });

        // Variation axes belong to the canonical product: colour and capacity
        // are facts about the iPhone, not about one seller's listing.
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('option_values')->nullable();          // {"Colour":"Black","Capacity":"256GB"}
            $table->string('gtin', 14)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_main')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brand_aliases');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};

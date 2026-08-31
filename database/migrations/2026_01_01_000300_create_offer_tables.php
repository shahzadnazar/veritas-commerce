<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A seller's commercial offer against a canonical product variant.
         * This is what a customer actually buys and what carries price,
         * condition, stock and fulfilment settings.
         */
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('seller_sku');
            $table->string('condition')->default('new');

            // Money is an integer count of minor units, always with its
            // currency. No decimal, no float, anywhere.
            $table->bigInteger('price_minor');
            $table->bigInteger('compare_at_price_minor')->nullable();
            $table->char('currency', 3)->default('USD');

            $table->string('status')->default('draft');
            $table->text('moderation_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->unsignedSmallInteger('handling_days')->default(2);
            $table->bigInteger('shipping_flat_minor')->nullable();
            $table->bigInteger('free_shipping_threshold_minor')->nullable();

            $table->text('seller_notes')->nullable();
            $table->timestamps();

            // A seller lists a given variant once.
            $table->unique(['seller_account_id', 'product_variant_id'], 'offers_seller_variant_unique');
            $table->unique(['seller_account_id', 'seller_sku']);
            $table->index(['product_id', 'status']);
            $table->index(['seller_account_id', 'status']);
            $table->index(['status', 'price_minor']);
        });

        Schema::create('offer_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['offer_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_media');
        Schema::dropIfExists('offers');
    }
};

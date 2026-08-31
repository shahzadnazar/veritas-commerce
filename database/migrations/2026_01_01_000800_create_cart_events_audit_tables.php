<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('session_token')->nullable()->unique();
            $table->char('currency', 3)->default('USD');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            // Shown to the customer, but re-priced from the live offer at
            // checkout. The client's number is display only.
            $table->bigInteger('unit_price_at_add_minor');
            $table->timestamps();

            $table->unique(['cart_id', 'offer_id']);
        });

        /*
         * Behavioural events, captured from the first release.
         *
         * No model ships in M0. This exists now because a recommender built
         * later on no history recommends noise, and because result_position
         * recorded at click time is the only thing that makes ranking
         * training data usable.
         *
         * No PII: an anonymous visitor gets a rotating pseudonymous session
         * id, never a durable fingerprint.
         */
        Schema::create('interaction_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('event_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('anonymous_session_id')->nullable();
            $table->string('event_type');

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('search_query')->nullable();
            $table->unsignedInteger('result_position')->nullable();
            $table->string('context')->nullable();               // home_featured | search | pdp_related …
            $table->bigInteger('value_minor')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['event_type', 'created_at']);
            $table->index(['product_id', 'event_type']);
            $table->index(['user_id', 'created_at']);
            $table->index(['anonymous_session_id', 'created_at']);
        });

        // APPEND ONLY. Domain-specific audit for anything that moves money,
        // access or moderation state.
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('actor_type');                        // customer | seller | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('changes')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['actor_type', 'actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        // Operational configuration. Legal entity, domain and branding live
        // here rather than being hard-coded anywhere in application logic.
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->jsonb('value');
            $table->string('group')->default('general');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('interaction_events');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};

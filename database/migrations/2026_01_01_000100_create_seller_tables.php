<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_applications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference')->unique();               // APP-1180
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('legal_name');
            $table->string('trading_name');
            $table->string('business_type');
            $table->text('tax_id');                              // encrypted cast
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('address_city');
            $table->string('address_state', 64);
            $table->string('address_postcode', 32);
            $table->char('address_country', 2)->default('US');

            $table->string('contact_name');
            $table->string('contact_role')->nullable();
            $table->string('contact_email');
            $table->string('contact_phone')->nullable();

            $table->foreignId('primary_category_id')->nullable();
            $table->unsignedInteger('planned_listings')->nullable();
            $table->string('existing_site')->nullable();
            $table->text('blurb')->nullable();

            $table->string('status')->default('submitted');
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('seller_accounts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('application_id')->nullable()->constrained('seller_applications')->nullOnDelete();

            $table->string('legal_name');
            $table->string('business_type')->nullable();
            $table->text('tax_id')->nullable();                  // encrypted cast
            $table->string('status')->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();

            $table->string('ships_from_city')->nullable();
            $table->string('ships_from_state', 64)->nullable();

            // Per-seller override of the platform clearing period. Null means
            // "use the platform setting" — never a hard-coded 7.
            $table->unsignedSmallInteger('clearing_period_days')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo_media_id')->nullable();
            $table->string('banner_media_id')->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_phone')->nullable();
            $table->text('shipping_policy')->nullable();
            $table->text('return_policy')->nullable();
            $table->boolean('is_open')->default(true);
            $table->timestamps();

            $table->index('seller_account_id');
        });

        // A slug change leaves a permanent redirect. A marketplace whose
        // seller URLs rot loses the seller's accumulated search equity.
        Schema::create('store_slug_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('old_slug')->unique();
            $table->timestamp('changed_at');
        });

        // Which users may act for which seller, and in what role. Phase 1
        // creates one Owner per seller; the model already supports more.
        Schema::create('seller_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['seller_account_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('seller_account_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_account_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['seller_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_account_events');
        Schema::dropIfExists('seller_memberships');
        Schema::dropIfExists('store_slug_history');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('seller_accounts');
        Schema::dropIfExists('seller_applications');
    }
};

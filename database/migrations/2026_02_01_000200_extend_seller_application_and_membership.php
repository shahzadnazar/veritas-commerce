<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_applications', function (Blueprint $table): void {
            $table->string('website')->nullable()->after('existing_site');
            $table->jsonb('intended_categories')->nullable()->after('primary_category_id');
            $table->string('expected_catalogue_type')->nullable()->after('intended_categories');
            $table->text('operational_notes')->nullable()->after('blurb');
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->timestamp('review_started_at')->nullable()->after('submitted_at');
            $table->foreignId('reviewer_admin_id')->nullable()->after('review_started_at')
                ->constrained('admin_users')->nullOnDelete();
            $table->text('internal_notes')->nullable()->after('decision_reason');
            $table->foreignId('seller_account_id')->nullable()->after('internal_notes')
                ->constrained('seller_accounts')->nullOnDelete();

            $table->index(['reviewer_admin_id', 'status']);
        });

        /*
         * APPEND ONLY. Every decision on an application is a row: who, when,
         * from what state to what state, and why. This is what makes a
         * dispute over an approval or a rejection reconstructable months
         * later, and it is why the application row itself carries only the
         * current state.
         */
        Schema::create('seller_application_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_application_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['seller_application_id', 'created_at']);
        });

        /*
         * Verification documents.
         *
         * Which documents are mandatory is configuration, not schema
         * (config veritas.sellers.required_documents), because KYC rules
         * change by market and by regulator rather than by release.
         */
        Schema::create('seller_application_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_application_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 128);
            $table->unsignedBigInteger('bytes');
            $table->string('checksum', 64)->nullable();
            $table->timestamp('uploaded_at');

            $table->index(['seller_application_id', 'kind']);
        });

        // A seller's team. Invitations are single-use, expiring and
        // revocable; the partial unique index below is what actually stops
        // two live invitations for the same address.
        Schema::create('seller_invitations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_account_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash');
            $table->string('status')->default('pending');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['seller_account_id', 'status']);
            $table->index('token_hash');
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('is_open');
            $table->string('business_city')->nullable()->after('timezone');
            $table->string('business_state', 64)->nullable()->after('business_city');
            $table->char('business_country', 2)->nullable()->after('business_state');
        });

        $this->addSingleLiveInvitationConstraint();
    }

    /**
     * One live invitation per address per seller.
     *
     * Enforced in the database rather than by a check the UI can lose to a
     * double submit — the same reasoning as the open-payout constraint.
     */
    private function addSingleLiveInvitationConstraint(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement(
            "CREATE UNIQUE INDEX seller_invitations_one_live_per_email
             ON seller_invitations (seller_account_id, lower(email))
             WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'business_city', 'business_state', 'business_country']);
        });

        Schema::dropIfExists('seller_invitations');
        Schema::dropIfExists('seller_application_documents');
        Schema::dropIfExists('seller_application_events');

        Schema::table('seller_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewer_admin_id');
            $table->dropConstrainedForeignId('seller_account_id');
            $table->dropColumn([
                'website', 'intended_categories', 'expected_catalogue_type',
                'operational_notes', 'submitted_at', 'review_started_at', 'internal_notes',
            ]);
        });
    }
};

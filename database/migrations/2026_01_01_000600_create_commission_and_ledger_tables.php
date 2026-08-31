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
        /*
         * APPEND ONLY. Setting a rate inserts a row with a future
         * effective_from; it never updates the previous one. Phase 1 uses a
         * single global rule, but scope/category/seller columns exist now so
         * a category or seller rate later is data, not a migration.
         */
        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('scope')->default('global');
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('seller_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('campaign_code')->nullable();

            $table->decimal('rate_percent', 5, 2);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['scope', 'effective_from']);
            $table->index(['category_id', 'effective_from']);
            $table->index(['seller_account_id', 'effective_from']);
        });

        /*
         * The seller ledger. APPEND ONLY.
         *
         * A balance is never a column that gets updated — it is the sum of
         * these rows. A mistake is corrected by an Adjustment referencing the
         * original, never by editing a row.
         *
         * available_at carries the clearing deadline per entry, so the
         * platform clearing period can change without rewriting history and
         * a per-seller override is possible without a schema change.
         */
        Schema::create('seller_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_account_id')->constrained()->restrictOnDelete();

            $table->string('type');
            $table->string('status')->default('pending');
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('amount_minor');                  // signed: + credit, - debit
            $table->bigInteger('balance_after_minor');           // running balance at insert

            $table->foreignId('seller_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payout_request_id')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('seller_ledger_entries')->nullOnDelete();

            $table->timestamp('available_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['seller_account_id', 'created_at']);
            $table->index(['seller_account_id', 'status', 'available_at']);
        });

        Schema::create('seller_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_account_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('holder_name');
            $table->string('last4', 4);
            $table->text('details');                             // encrypted cast
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('changed_at')->nullable();         // surfaced on the payout queue
            $table->timestamps();

            $table->index('seller_account_id');
        });

        Schema::create('payout_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference')->unique();               // PO-2044
            $table->foreignId('seller_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_bank_account_id')->nullable()->constrained()->nullOnDelete();

            $table->char('currency', 3)->default('USD');
            $table->bigInteger('amount_minor');
            $table->string('status')->default('requested');

            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->string('settlement_ref')->nullable();        // reserved for automated payouts
            $table->timestamps();

            $table->index(['status', 'requested_at']);
        });

        $this->addOneOpenPayoutConstraint();
    }

    /**
     * One open payout request per seller, enforced by the database rather
     * than by a check the UI can lose to a double submit.
     */
    private function addOneOpenPayoutConstraint(): void
    {
        $open = "'requested','under_review','approved','processing'";

        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX payout_requests_one_open_per_seller
                 ON payout_requests (seller_account_id)
                 WHERE status IN ({$open})"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_requests');
        Schema::dropIfExists('seller_bank_accounts');
        Schema::dropIfExists('seller_ledger_entries');
        Schema::dropIfExists('commission_rules');
    }
};

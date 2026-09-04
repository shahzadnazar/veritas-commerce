<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The payment lifecycle M0 sketched and M5 has to actually run.
 *
 * Three shapes of change. The attempt gains the columns a real provider
 * interaction needs — its own idempotency key, the states between "created"
 * and "charged", and the timestamps that make an attempt's history
 * readable. Provider events gain the fields an operator needs to answer
 * "did we process it, and if not why". And refunds move from one row per
 * seller order to a request/allocation pair, because a partial refund has
 * to say which item's money it reverses before it can reverse a commission.
 *
 * Nothing here recalculates anything. Every financial figure the refund
 * path writes is copied from the order item's own snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            // Present since M4 for the checkout link; the rest is new.
            $table->string('provider_status')->nullable()->after('status');
            $table->timestamp('succeeded_at')->nullable()->after('created_at');
            $table->timestamp('failed_at')->nullable()->after('succeeded_at');
            $table->timestamp('cancelled_at')->nullable()->after('failed_at');
            // Every state change is a row on the attempt's own history, so
            // "failed, retried, succeeded" cannot be flattened into
            // "succeeded" by an UPDATE.
            $table->unsignedInteger('event_sequence')->default(0)->after('cancelled_at');
        });

        /*
         * One live attempt per order.
         *
         * A partial index over the states in which an attempt is still
         * expecting money: a customer may accumulate failed and cancelled
         * attempts, and each is a row, but only one can be in flight. This
         * is the database's half of preparation idempotency — the other
         * half is the unique key on the request itself.
         */
        DB::statement("
            create unique index payment_attempts_one_open_per_order
            on payment_attempts (marketplace_order_id)
            where status in ('created', 'requires_payment_method', 'requires_action', 'processing')
        ");

        // A provider reference identifies exactly one attempt. Two attempts
        // claiming one PaymentIntent would let a single capture finalize
        // two orders.
        DB::statement('create unique index payment_attempts_provider_reference on payment_attempts (provider, provider_reference) where provider_reference is not null');

        DB::statement('alter table payment_attempts add constraint payment_attempts_amount_is_positive check (amount_minor > 0)');

        /*
         * The attempt's own state history. APPEND ONLY.
         *
         * §71: the current state may be succeeded, but the sequence that
         * got there has to remain visible — a card declined twice before
         * a third card worked is the thing a chargeback dispute turns on.
         */
        Schema::create('payment_attempt_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('provider_status')->nullable();
            $table->string('source');                            // provider_event | request | system
            $table->foreignId('provider_webhook_event_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['payment_attempt_id', 'created_at']);
        });

        Schema::table('provider_webhook_events', function (Blueprint $table): void {
            // What the event was about, so an operator can find the order
            // without opening the payload.
            $table->string('object_reference')->nullable()->after('type');
            $table->string('status')->default('received')->after('object_reference');
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            $table->string('signature_fingerprint', 64)->nullable()->after('payload');
            $table->timestamp('failed_at')->nullable()->after('processed_at');
        });

        DB::statement("create index provider_webhook_events_unprocessed on provider_webhook_events (provider, status) where status <> 'processed'");
        DB::statement('create index provider_webhook_events_object on provider_webhook_events (provider, object_reference)');

        /*
         * Payment transactions: what actually moved, as opposed to what
         * state a row is in.
         *
         * §34 asks for these to be separate from attempt state and never
         * overwritten. A capture and three partial refunds are four rows;
         * the money that moved is their sum, not a column somebody edits.
         */
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('marketplace_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('provider_transaction_reference')->nullable();
            $table->string('type');                              // capture | refund | reversal
            $table->char('currency', 3)->default('USD');
            // Signed: a capture is positive, a refund negative, so the net
            // position of an order is a sum rather than a case expression.
            $table->bigInteger('amount_minor');
            $table->string('status')->default('succeeded');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['marketplace_order_id', 'occurred_at']);
        });

        // One transaction per provider reference per type: a replayed
        // webhook cannot post the capture twice.
        DB::statement('create unique index payment_transactions_provider_reference on payment_transactions (provider, type, provider_transaction_reference) where provider_transaction_reference is not null');

        /*
         * Refunds, rebuilt.
         *
         * M0's `refunds` table was one row per seller order with the
         * reversal amounts on it. A partial refund needs to say WHICH item
         * it reverses before it can reverse the right commission, so a
         * refund is now a request with allocations under it — the same
         * parent/child shape the order itself has, for the same reason.
         */
        Schema::dropIfExists('refunds');

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference')->unique();
            $table->foreignId('marketplace_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider');
            $table->string('provider_refund_reference')->nullable();
            // The caller's own key, so a double-clicked refund button is
            // one refund.
            $table->string('idempotency_key', 64)->nullable();

            $table->char('currency', 3)->default('USD');
            $table->bigInteger('amount_minor');
            $table->string('status')->default('requested');
            $table->text('reason');
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();

            $table->foreignId('requested_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['marketplace_order_id', 'requested_at']);
            $table->index(['status', 'requested_at']);
        });

        DB::statement('create unique index refunds_idempotency_key on refunds (idempotency_key) where idempotency_key is not null');
        DB::statement('create unique index refunds_provider_reference on refunds (provider, provider_refund_reference) where provider_refund_reference is not null');
        DB::statement('alter table refunds add constraint refunds_amount_is_positive check (amount_minor > 0)');

        /*
         * Which item's money a refund reverses.
         *
         * §38: "refund $50" is not a financial instruction until it says
         * whose $50. The commission and earning reversals are copied from
         * the order item's snapshot at allocation time — never recomputed
         * from today's rate, which is the whole point of §39.
         */
        Schema::create('refund_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();

            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('quantity')->default(0);
            $table->bigInteger('amount_minor');
            $table->bigInteger('commission_reversed_minor');
            $table->bigInteger('earning_reversed_minor');
            $table->timestamp('created_at');

            $table->unique(['refund_id', 'order_item_id']);
            $table->index('seller_order_id');
            $table->index('order_item_id');
        });

        DB::statement('alter table refund_allocations add constraint refund_allocations_amounts_are_not_negative check (
            amount_minor >= 0 and commission_reversed_minor >= 0 and earning_reversed_minor >= 0
        )');

        // The same identity the order item itself carries: what is reversed
        // splits exactly into the platform's part and the seller's part.
        DB::statement('alter table refund_allocations add constraint refund_allocations_split_is_exact check (
            commission_reversed_minor + earning_reversed_minor = amount_minor
        )');

        /*
         * The ledger's idempotency key.
         *
         * A financial entry is posted by exactly one source event — a
         * payment finalizing, a refund succeeding — and a retried job must
         * find the row already there rather than post a second one. The
         * unique index is what makes "exactly once" a database property
         * instead of a hope about queue behaviour.
         */
        Schema::table('seller_ledger_entries', function (Blueprint $table): void {
            $table->string('source_key')->nullable()->after('note');
        });

        DB::statement('create unique index seller_ledger_entries_source_key on seller_ledger_entries (source_key) where source_key is not null');

        /*
         * The platform's own side of the split.
         *
         * Commission is the marketplace's revenue and needs the same
         * treatment the seller's earning gets: append-only, from the
         * snapshot, reversible by a further row rather than by an edit.
         */
        Schema::create('platform_revenue_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('marketplace_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_account_id')->constrained()->restrictOnDelete();

            $table->string('type');                              // commission | commission_reversal
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('amount_minor');                  // signed
            $table->decimal('rate_percent_snapshot', 5, 2)->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('platform_revenue_entries')->nullOnDelete();
            $table->string('source_key')->nullable();
            $table->timestamp('created_at');

            $table->index(['marketplace_order_id', 'created_at']);
            $table->index(['seller_account_id', 'created_at']);
        });

        DB::statement('create unique index platform_revenue_entries_source_key on platform_revenue_entries (source_key) where source_key is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_revenue_entries');

        DB::statement('drop index if exists seller_ledger_entries_source_key');
        Schema::table('seller_ledger_entries', function (Blueprint $table): void {
            $table->dropColumn('source_key');
        });

        Schema::dropIfExists('refund_allocations');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_attempt_events');

        DB::statement('drop index if exists provider_webhook_events_object');
        DB::statement('drop index if exists provider_webhook_events_unprocessed');
        Schema::table('provider_webhook_events', function (Blueprint $table): void {
            $table->dropColumn(['object_reference', 'status', 'attempts', 'signature_fingerprint', 'failed_at']);
        });

        DB::statement('alter table payment_attempts drop constraint if exists payment_attempts_amount_is_positive');
        DB::statement('drop index if exists payment_attempts_provider_reference');
        DB::statement('drop index if exists payment_attempts_one_open_per_order');
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn(['provider_status', 'succeeded_at', 'failed_at', 'cancelled_at', 'event_sequence']);
        });
    }
};

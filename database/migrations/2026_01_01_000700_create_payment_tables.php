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
         * APPEND ONLY. Every attempt is a row, including failures — a
         * customer retrying three times produces three rows against one
         * order, which is what makes the admin payments screen able to
         * answer "did it charge?" without opening the provider.
         */
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_reference')->nullable();
            $table->string('method')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('amount_minor');
            $table->string('status')->default('pending');
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->timestamp('created_at');

            $table->index(['marketplace_order_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('provider_charge_id')->unique();
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('amount_minor');
            $table->bigInteger('refunded_amount_minor')->default(0);
            $table->string('status')->default('captured');
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index('marketplace_order_id');
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference')->unique();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('amount_minor');
            // Reversed at the rate stored on the original order item, never
            // at today's rate.
            $table->bigInteger('commission_reversed_minor')->default(0);
            $table->bigInteger('earning_reversed_minor')->default(0);
            $table->string('reason');
            $table->string('status')->default('completed');
            $table->foreignId('issued_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index('seller_order_id');
        });

        /*
         * The idempotency ledger for inbound provider events.
         *
         * A webhook replayed three times must not post three ledger rows.
         * The unique index on (provider, event_id) is what guarantees that,
         * rather than an application-level check that races.
         */
        Schema::create('provider_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('event_id');
            $table->string('type');
            $table->jsonb('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();

            $table->unique(['provider', 'event_id']);
            $table->index(['provider', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_attempts');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One default location per seller in Phase 1. The table exists now so
        // multiple warehouses later are a data change, not a migration of
        // every stock row.
        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_account_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Default');
            $table->boolean('is_default')->default(true);
            $table->timestamps();

            $table->index('seller_account_id');
        });

        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->integer('on_hand')->default(0);
            $table->timestamps();

            $table->unique(['offer_id', 'inventory_location_id']);
        });

        /*
         * A reservation holds stock without changing the physical count.
         *
         * Decrementing on checkout submit would let a failed payment destroy
         * availability; a hold reserves, and expires if the payment never
         * captures.
         *
         *   available = on_hand - SUM(reservations WHERE status = 'held')
         *
         * available is never stored. It is derived, every time.
         */
        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status')->default('held');
            $table->string('reference')->nullable();             // cart or order reference
            $table->foreignId('marketplace_order_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['offer_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        /*
         * APPEND ONLY. Every change to on_hand writes a row carrying the
         * delta, the resulting quantity, a required reason and an actor.
         * Replaying the movements for an offer from zero must equal its
         * current on_hand — asserted nightly and in tests.
         */
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->integer('change');
            $table->integer('resulting_on_hand');
            $table->string('reason');
            $table->string('actor_type');                        // seller | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('seller_order_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['offer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_locations');
    }
};

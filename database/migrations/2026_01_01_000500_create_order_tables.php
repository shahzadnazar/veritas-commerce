<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gapless human-facing order numbers. A sequence table rather than
        // the identity column, so VC- numbers stay dense and readable.
        Schema::create('reference_sequences', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('next_value')->default(1);
        });

        /*
         * One customer checkout produces one marketplace order...
         */
        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference')->unique();               // VC-24081
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('status')->default('pending_payment');
            $table->char('currency', 3)->default('USD');

            $table->bigInteger('items_total_minor')->default(0);
            $table->bigInteger('shipping_total_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('discount_total_minor')->default(0);
            $table->bigInteger('grand_total_minor')->default(0);

            // The shipping address is snapshotted onto the order, not linked.
            // Editing a saved address must never rewrite history.
            $table->string('ship_name');
            $table->string('ship_line1');
            $table->string('ship_line2')->nullable();
            $table->string('ship_city');
            $table->string('ship_state', 64);
            $table->string('ship_postcode', 32);
            $table->char('ship_country', 2)->default('US');
            $table->string('ship_phone')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'placed_at']);
            $table->index(['status', 'placed_at']);
        });

        /*
         * ...and one seller sub-order per seller in the cart.
         *
         *   VC-24081
         *   ├── VC-24081-01  Seller A
         *   ├── VC-24081-02  Seller B
         *   └── VC-24081-03  Seller C
         *
         * The sub-order independently owns fulfilment, shipment, commission,
         * earning and payout eligibility. A seller sees only their own.
         */
        Schema::create('seller_orders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference')->unique();               // VC-24081-01
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');            // 1 -> "-01"

            $table->string('status')->default('pending_payment');
            $table->char('currency', 3)->default('USD');

            $table->bigInteger('items_total_minor')->default(0);
            $table->bigInteger('shipping_total_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('discount_total_minor')->default(0);
            $table->bigInteger('order_total_minor')->default(0);

            // Rolled up from the order items' own snapshots — never computed
            // from a current rate.
            $table->bigInteger('commission_total_minor')->default(0);
            $table->bigInteger('seller_earning_total_minor')->default(0);

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['marketplace_order_id', 'position']);
            $table->unique(['marketplace_order_id', 'seller_account_id']);
            $table->index(['seller_account_id', 'status', 'created_at']);
        });

        /*
         * The financial snapshot lives here, at line level.
         *
         * Every value a customer saw and every value the split produced is
         * written once and never recalculated. Changing an offer price, the
         * platform commission, or a category rule tomorrow leaves every row
         * below exactly as it is.
         */
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // Descriptive snapshots — the listing may be retitled or archived.
            $table->string('product_title');
            $table->string('variant_name')->nullable();
            $table->string('seller_sku');

            // Money snapshots.
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('unit_price_snapshot_minor');
            $table->unsignedInteger('quantity');
            $table->bigInteger('discount_snapshot_minor')->default(0);
            $table->bigInteger('line_total_minor');
            $table->bigInteger('tax_amount_minor')->default(0);
            $table->decimal('tax_rate_snapshot', 6, 4)->nullable();
            $table->string('tax_source')->nullable();

            // Commission snapshot: the rate, the rule that produced it, and
            // the two amounts. commission + earning always equals line total
            // net of tax handling — asserted by an invariant test.
            $table->decimal('commission_rate_snapshot', 5, 2);
            $table->foreignId('commission_rule_id')->nullable();
            $table->string('commission_scope_snapshot')->nullable();
            $table->bigInteger('commission_amount_minor');
            $table->bigInteger('seller_earning_amount_minor');
            $table->timestamp('snapshotted_at');

            $table->bigInteger('refunded_amount_minor')->default(0);
            $table->timestamps();

            $table->index('seller_order_id');
            $table->index('offer_id');
        });

        // APPEND ONLY. Every transition is its own row with an actor, so any
        // dispute can be reconstructed.
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('actor_type');                        // customer | seller | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['seller_order_id', 'created_at']);
            $table->index(['marketplace_order_id', 'created_at']);
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_order_id')->constrained()->cascadeOnDelete();
            $table->string('carrier');
            $table->string('carrier_code')->nullable();          // reserved for courier integrations
            $table->string('tracking_number');
            $table->timestamp('shipped_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('seller_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('seller_orders');
        Schema::dropIfExists('marketplace_orders');
        Schema::dropIfExists('reference_sequences');
    }
};

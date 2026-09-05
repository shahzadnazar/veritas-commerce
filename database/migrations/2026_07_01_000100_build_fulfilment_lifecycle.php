<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fulfilment as a first-class aggregate, not four columns on a seller order.
 *
 * M0 gave `shipments` a carrier, a tracking number and a shipped date — one
 * shipment per seller order, implied by the schema and impossible to grow
 * out of. A marketplace needs the other shape: a seller order ships in as
 * many parcels as it takes, each naming the exact order items and
 * quantities it carries, so "what is still to ship" is a fact the database
 * can answer rather than a guess from a status column.
 *
 * The counts on `order_items` are stored rather than derived for the same
 * reason M3 stores `reserved` on an inventory balance: a CHECK constraint
 * on a stored number makes over-shipping impossible even when the
 * application logic is wrong, and no aggregate can be locked in PostgreSQL.
 * A reconciliation test asserts the stored counts against the shipment
 * items that produced them.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The M0 table is replaced rather than extended. It carried NOT NULL
         * carrier, tracking number and shipped_at, which a shipment being
         * packed does not have yet, and nothing has shipped in any
         * environment — the fulfilment lifecycle starts here.
         */
        Schema::dropIfExists('shipments');

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            // VC-24081-01-S01 — readable on a packing slip and in a support
            // conversation. The id remains the identity.
            $table->string('reference')->unique();
            $table->foreignId('seller_order_id')->constrained()->cascadeOnDelete();

            // Position within the seller order, which is what makes the
            // reference deterministic and unique under concurrency.
            $table->unsignedSmallInteger('sequence');

            $table->string('status')->default('draft');

            /*
             * Carrier and tracking are nullable because a shipment exists
             * before it is handed over. What may not be null at the moment
             * of shipping is enforced in the domain, where the policy lives.
             */
            $table->string('carrier_name')->nullable();
            $table->string('carrier_code')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('tracking_url')->nullable();

            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('created_by_type')->nullable();       // seller | admin | system
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // One sequence per seller order: the unique index is what stops
            // two concurrent creations claiming the same number.
            $table->unique(['seller_order_id', 'sequence'], 'shipments_sequence_per_seller_order');
            $table->index(['seller_order_id', 'status']);
            $table->index('tracking_number');

            /*
             * Deliberately NOT globally unique. Carriers reuse tracking
             * number formats, and two different couriers may legitimately
             * issue the same string; a global unique index would reject a
             * real shipment. Scoped uniqueness is enforced in the domain
             * where the carrier is known.
             */
        });

        /*
         * What is actually in the box.
         *
         * Without this a "shipment" is only a status change on a seller
         * order, and a partial shipment cannot be represented at all — the
         * customer would be told their whole order shipped when one of
         * three items did.
         */
        Schema::create('shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamp('created_at');

            // One row per item per shipment; a second allocation of the
            // same item to the same parcel is an update, not a new row.
            $table->unique(['shipment_id', 'order_item_id']);
            $table->index('order_item_id');
        });

        DB::statement('ALTER TABLE shipment_items ADD CONSTRAINT shipment_items_quantity_is_positive CHECK (quantity > 0)');

        // APPEND ONLY. A shipment's state is worth as much as its history in
        // a dispute — "it says delivered" is not an answer without when, by
        // whom, and from what.
        Schema::create('shipment_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('actor_type');                        // seller | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            // The tracking as it stood after this transition, so a
            // correction can be read against what it replaced.
            $table->string('carrier_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('created_at');

            $table->index(['shipment_id', 'created_at']);
        });

        /*
         * A structured way to say "this cannot be fulfilled", short of a
         * support ticket system. Enough to be reported, seen by an admin,
         * and resolved with a record of who did it.
         */
        Schema::create('fulfilment_issues', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->text('note');
            $table->string('reported_by_type');                  // seller | admin
            $table->unsignedBigInteger('reported_by_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by_admin_id')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['seller_order_id', 'resolved_at']);
        });

        /*
         * Stored fulfilment counts, bounded by the quantity purchased.
         *
         * `allocated_quantity` counts units committed to a shipment that
         * has not been cancelled — incremented when the parcel is made up,
         * not when it leaves. That is deliberate: it makes the CHECK below
         * the thing that prevents over-allocation, so two requests racing
         * for the last unit cannot both win however the application
         * behaves. The same reasoning as the inventory ledger's stored
         * `reserved`, and the same backstop.
         *
         * How many units have actually left, and how many arrived, are
         * derived from the shipment items and their parcels' states —
         * except `delivered_quantity`, which is stored because the
         * clearing sweep reads it and a delivered unit never un-delivers.
         *
         * Refunded units also consume ordered quantity, and that is a
         * domain rule rather than a constraint: the refund lives in
         * another module's tables, so the locked calculation enforces it
         * and the CHECK catches the grosser mistake underneath.
         */
        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('allocated_quantity')->default(0)->after('quantity');
            $table->unsignedInteger('delivered_quantity')->default(0)->after('allocated_quantity');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_allocated_within_ordered CHECK (allocated_quantity <= quantity)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_delivered_within_allocated CHECK (delivered_quantity <= allocated_quantity)');

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->timestamp('processing_at')->nullable()->after('confirmed_at');
            $table->timestamp('packed_at')->nullable()->after('processing_at');
        });

        /*
         * When a delivered seller order's earnings finish clearing.
         *
         * Denormalised onto the seller order so the clearing sweep can find
         * work with an indexed range scan rather than by walking the ledger
         * — and so an operator can see the date without reading it out of a
         * financial table.
         */
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->timestamp('earnings_clear_at')->nullable()->after('delivered_at');
        });

        DB::statement('CREATE INDEX seller_orders_clearing_due ON seller_orders (earnings_clear_at) WHERE earnings_clear_at IS NOT NULL AND completed_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS seller_orders_clearing_due');

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropColumn(['processing_at', 'packed_at', 'earnings_clear_at']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['allocated_quantity', 'delivered_quantity']);
        });

        Schema::dropIfExists('fulfilment_issues');
        Schema::dropIfExists('shipment_status_history');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('seller_order_id')->constrained()->cascadeOnDelete();
            $table->string('carrier');
            $table->string('carrier_code')->nullable();
            $table->string('tracking_number');
            $table->timestamp('shipped_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('seller_order_id');
        });
    }
};

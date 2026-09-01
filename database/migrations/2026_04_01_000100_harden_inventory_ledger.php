<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory becomes a double-entry ledger over two quantities.
 *
 * M0 recorded movements against `on_hand` and derived `reserved` by summing
 * live reservations on every read. That was correct but had two costs M3
 * cannot pay: discovery has to know availability for every card on a page,
 * which a correlated SUM turns into a per-result query; and the invariant
 * "reserved never exceeds on_hand" lived only in PHP.
 *
 * So `reserved` becomes a column, every movement carries a change to BOTH
 * quantities, and `available` is a generated column — the database computes
 * it, which is the only way to guarantee the seller portal, the storefront
 * and the search index cannot each invent their own version of the formula.
 *
 * The ledger invariant is now two equalities, both asserted in the suite
 * and by `inventory:reconcile`:
 *
 *     SUM(on_hand_change)  == on_hand
 *     SUM(reserved_change) == reserved
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_balances', function (Blueprint $table): void {
            $table->integer('reserved')->default(0)->after('on_hand');
        });

        // Backfilled from the reservations that are actually live, so an
        // existing database lands on the same number the old derived
        // reader would have returned.
        DB::statement('
            update inventory_balances b
               set reserved = coalesce((
                   select sum(r.quantity)
                     from inventory_reservations r
                    where r.offer_id = b.offer_id
                      and r.inventory_location_id = b.inventory_location_id
                      and r.status = \'held\'
               ), 0)
        ');

        /*
         * `available` is generated, not maintained.
         *
         * A stored column would be a third number to keep in step with the
         * other two; a view would not be indexable. Generated means the
         * formula exists exactly once, in the schema, and every reader —
         * PHP, a discovery query, a psql session at 3am — gets the same
         * answer by construction.
         */
        DB::statement('
            alter table inventory_balances
                add column available integer
                generated always as (on_hand - reserved) stored
        ');

        DB::statement('alter table inventory_balances add constraint inventory_on_hand_not_negative check (on_hand >= 0)');
        DB::statement('alter table inventory_balances add constraint inventory_reserved_not_negative check (reserved >= 0)');
        // The invariant that stops overselling at the storage layer: you
        // cannot promise more units than you physically hold.
        DB::statement('alter table inventory_balances add constraint inventory_reserved_within_on_hand check (reserved <= on_hand)');

        // Discovery asks "is this offer buyable" for every card on a page.
        DB::statement('create index inventory_balances_available on inventory_balances (offer_id, available)');

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->integer('reserved_change')->default(0)->after('change');
            $table->integer('resulting_reserved')->default(0)->after('resulting_on_hand');
        });

        // `change` was only ever the on_hand delta; now that there are two,
        // the column has to say which one it is.
        DB::statement('alter table inventory_movements rename column change to on_hand_change');

        DB::statement('
            alter table inventory_movements
                add constraint inventory_movement_changes_something
                check (on_hand_change <> 0 or reserved_change <> 0)
        ');

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            // Which movement pair opened and closed this hold, so the
            // ledger and the reservation can always be reconciled from
            // either direction.
            $table->foreignId('opened_by_movement_id')->nullable()->after('resolved_at')
                ->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('closed_by_movement_id')->nullable()->after('opened_by_movement_id')
                ->constrained('inventory_movements')->nullOnDelete();

            $table->index(['reference', 'status']);
        });

        // A resolved reservation has a resolution time and a live one does
        // not: the pair cannot drift apart.
        DB::statement("
            alter table inventory_reservations
                add constraint reservations_resolution_is_dated
                check ((status = 'held') = (resolved_at is null))
        ");

        DB::statement('alter table inventory_reservations add constraint reservations_quantity_is_positive check (quantity > 0)');

        /*
         * Low-stock thresholds, resolved offer → store → platform default.
         *
         * Nullable at both levels so "not set" is distinguishable from
         * "set to zero", which is a real choice: a seller who never wants a
         * low-stock warning sets zero, and a seller who has not thought
         * about it inherits.
         */
        Schema::table('offers', function (Blueprint $table): void {
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('handling_days');
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->unsignedInteger('default_low_stock_threshold')->nullable()->after('is_open');
        });

        /*
         * The last stock state a seller was told about.
         *
         * Notifications fire on a transition, not on a level, so something
         * has to remember which side of the threshold the offer was on last
         * time. Without it, every save at four units in stock is another
         * "you are low on stock" email.
         */
        Schema::table('inventory_balances', function (Blueprint $table): void {
            $table->string('notified_state', 24)->nullable()->after('available');
            $table->timestamp('notified_at')->nullable()->after('notified_state');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_balances', function (Blueprint $table): void {
            $table->dropColumn(['notified_state', 'notified_at']);
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('default_low_stock_threshold');
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn('low_stock_threshold');
        });

        DB::statement('alter table inventory_reservations drop constraint if exists reservations_quantity_is_positive');
        DB::statement('alter table inventory_reservations drop constraint if exists reservations_resolution_is_dated');

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex(['reference', 'status']);
            $table->dropConstrainedForeignId('opened_by_movement_id');
            $table->dropConstrainedForeignId('closed_by_movement_id');
        });

        DB::statement('alter table inventory_movements drop constraint if exists inventory_movement_changes_something');
        DB::statement('alter table inventory_movements rename column on_hand_change to change');

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn(['reserved_change', 'resulting_reserved']);
        });

        DB::statement('drop index if exists inventory_balances_available');
        DB::statement('alter table inventory_balances drop constraint if exists inventory_reserved_within_on_hand');
        DB::statement('alter table inventory_balances drop constraint if exists inventory_reserved_not_negative');
        DB::statement('alter table inventory_balances drop constraint if exists inventory_on_hand_not_negative');
        DB::statement('alter table inventory_balances drop column if exists available');

        Schema::table('inventory_balances', function (Blueprint $table): void {
            $table->dropColumn('reserved');
        });
    }
};

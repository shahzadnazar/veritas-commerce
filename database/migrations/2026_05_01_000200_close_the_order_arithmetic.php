<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Order creation needs two things the checkout schema did not have.
 *
 * An email on the attempt, because `marketplace_orders.email` is NOT NULL
 * and a guest checkout has no account to read one from. It belongs on the
 * attempt rather than in the address snapshot: a receipt goes to a person,
 * a parcel goes to a place, and they are not always the same.
 *
 * And the arithmetic, in the database. §24 asks for a reconciliation
 * between a marketplace order, its seller orders and their items; a check
 * constraint is that reconciliation stated once, where nothing can route
 * around it — not a job that runs afterwards and reports a discrepancy
 * somebody has to go and fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_attempts', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('user_id');
        });

        // The totals add up, on both levels.
        DB::statement('alter table seller_orders add constraint seller_orders_total_is_exact check (
            items_total_minor + shipping_total_minor + tax_total_minor - discount_total_minor = order_total_minor
        )');

        DB::statement('alter table marketplace_orders add constraint marketplace_orders_total_is_exact check (
            items_total_minor + shipping_total_minor + tax_total_minor - discount_total_minor = grand_total_minor
        )');

        /*
         * And the split adds up.
         *
         * Every item's commission plus its earning is its line total, which
         * the item's own constraint already enforces. Rolled up, that means
         * a seller order's two commission columns must sum to its items
         * total — so a rollup written from anything other than the items'
         * own snapshots fails here rather than three months later in a
         * payout.
         */
        DB::statement('alter table seller_orders add constraint seller_orders_commission_split_is_exact check (
            commission_total_minor + seller_earning_total_minor = items_total_minor
        )');
    }

    public function down(): void
    {
        DB::statement('alter table seller_orders drop constraint if exists seller_orders_commission_split_is_exact');
        DB::statement('alter table marketplace_orders drop constraint if exists marketplace_orders_total_is_exact');
        DB::statement('alter table seller_orders drop constraint if exists seller_orders_total_is_exact');

        Schema::table('checkout_attempts', function (Blueprint $table): void {
            $table->dropColumn('email');
        });
    }
};

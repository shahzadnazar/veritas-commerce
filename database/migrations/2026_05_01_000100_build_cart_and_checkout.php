<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cart grows a line identity, and checkout gets somewhere to live.
 *
 * M0 gave carts a shape that assumed one line per offer. That is the right
 * rule today and the wrong one to hardcode: the moment a product carries a
 * personalisation — an engraving, a gift note, a configured length — two
 * lines for the same offer are two different things a customer wants, and
 * a unique index on `(cart_id, offer_id)` would silently merge them.
 *
 * So the uniqueness moves to a `line_identity` the domain computes. Today
 * it is derived from the offer and the variant; tomorrow it takes the
 * customisation with it, and the schema does not change.
 *
 * Checkout gets an attempt row rather than session variables. §14 asks for
 * one place holding checkout state, and idempotency needs somewhere durable
 * to record "this key already produced that order".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('status', 24)->default('active')->after('session_token');
            // Touched on every mutation, so an abandoned-cart sweep later
            // has something to sweep on that is not `updated_at`.
            $table->timestamp('last_activity_at')->nullable()->after('expires_at');
        });

        /*
         * One active cart per signed-in customer.
         *
         * A partial index rather than a plain unique, because a customer
         * legitimately accumulates converted and abandoned carts over time
         * — only the live one is exclusive.
         */
        DB::statement("
            create unique index carts_one_active_per_user
            on carts (user_id)
            where user_id is not null and status = 'active'
        ");

        /*
         * The same for an anonymous browser: one live cart per token.
         *
         * M0's plain unique on session_token goes with it. It predates the
         * cart having a lifecycle at all, and it makes one impossible: a
         * browser whose cart has been merged or has aged out could never
         * start another, because the retired row would hold its token
         * forever. The partial index keeps the rule that actually matters
         * — one LIVE cart per browser — and lets the history exist.
         */
        DB::statement('alter table carts drop constraint if exists carts_session_token_unique');
        DB::statement('drop index if exists carts_session_token_unique');

        DB::statement("
            create unique index carts_one_active_per_session
            on carts (session_token)
            where session_token is not null and status = 'active'
        ");

        // A cart belongs to a person or a browser, never to neither.
        DB::statement('
            alter table carts add constraint carts_have_an_owner
            check (user_id is not null or session_token is not null)
        ');

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('offer_id')
                ->constrained()->cascadeOnDelete();

            /*
             * What makes two lines the same line.
             *
             * Computed by the domain from the offer, the variant and — in
             * future — whatever else distinguishes one customer's version
             * of a product from another's. Stored rather than derived in
             * SQL so the rule lives in one readable place.
             */
            $table->string('line_identity', 64)->after('product_variant_id');
        });

        DB::statement("update cart_items set line_identity = 'offer:' || offer_id");

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'offer_id']);
            $table->unique(['cart_id', 'line_identity']);
        });

        DB::statement('alter table cart_items add constraint cart_items_quantity_is_positive check (quantity > 0)');

        /*
         * Saved shipping addresses already exist; the assumption in them
         * does not.
         *
         * M0 made `state` NOT NULL, which is a US-shaped schema even
         * though the column names are generic — Singapore, Malta and the
         * Vatican have no state, and a customer there could not save an
         * address at all. §33 says not to assume US-only at the database
         * level, so it becomes nullable. The names stay: `ship_state` and
         * `ship_postcode` already match on the order, and renaming both
         * for a preference would ripple with no functional gain.
         */
        DB::statement('alter table customer_addresses alter column state drop not null');

        /*
         * A checkout attempt.
         *
         * The idempotency record §15 needs: a customer double-clicking,
         * refreshing or retrying after a timeout presents the same key, and
         * gets the same order back rather than a second one. The key is
         * unique across the table, so the guarantee is the database's
         * rather than a hopeful read-then-write.
         */
        Schema::create('checkout_attempts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketplace_order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 32)->default('created');
            $table->char('currency', 3)->default('USD');

            // The quote as it stood when the attempt was accepted. Not the
            // order's financial record — that is the order's — but enough
            // to show what the customer agreed to.
            $table->bigInteger('items_total_minor')->default(0);
            $table->bigInteger('shipping_total_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('grand_total_minor')->default(0);

            // The address snapshot, taken at the attempt so it cannot move
            // under the customer between review and order creation.
            $table->jsonb('shipping_address')->nullable();

            $table->text('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->foreignId('checkout_attempt_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            // When an unpaid order gives its stock back.
            $table->timestamp('payment_expires_at')->nullable()->after('placed_at');
            // The reference every reservation for this order carries, so
            // release and commit can find them all in one query.
            $table->string('reservation_reference')->nullable()->after('payment_expires_at');

            $table->index('reservation_reference');
            $table->index(['status', 'payment_expires_at']);
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            // §51: the provider call is retried like anything else, and
            // the key is what stops a retry becoming a second charge.
            $table->string('idempotency_key', 64)->nullable()->after('provider_reference');
            $table->foreignId('checkout_attempt_id')->nullable()->after('marketplace_order_id')
                ->constrained()->nullOnDelete();
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        DB::statement('create unique index payment_attempts_idempotency_key on payment_attempts (idempotency_key) where idempotency_key is not null');

        /*
         * Snapshots M0 did not have, that §24 asks for.
         *
         * Seller and store ids are deliberately NOT duplicated here: they
         * live on the parent seller order, an item cannot move between
         * seller orders, and a second copy is a second thing to keep in
         * step. The display *names* are snapshotted, because those change.
         */
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('brand_name_snapshot')->nullable()->after('product_title');
            $table->string('store_name_snapshot')->nullable()->after('brand_name_snapshot');
            $table->string('product_slug_snapshot')->nullable()->after('store_name_snapshot');
            $table->jsonb('variant_options_snapshot')->nullable()->after('variant_name');
        });

        DB::statement('alter table order_items add constraint order_items_quantity_is_positive check (quantity > 0)');
        DB::statement('alter table order_items add constraint order_items_money_is_not_negative check (
            unit_price_snapshot_minor >= 0
            and line_total_minor >= 0
            and commission_amount_minor >= 0
            and seller_earning_amount_minor >= 0
            and discount_snapshot_minor >= 0
        )');

        // Commission and earning are a split of the line: neither can
        // exceed it, and together they are it.
        DB::statement('alter table order_items add constraint order_items_split_is_exact check (
            commission_amount_minor + seller_earning_amount_minor = line_total_minor
        )');

        DB::statement('alter table seller_orders add constraint seller_orders_money_is_not_negative check (
            items_total_minor >= 0 and order_total_minor >= 0
            and commission_total_minor >= 0 and seller_earning_total_minor >= 0
        )');

        DB::statement('alter table seller_orders add constraint seller_orders_position_is_positive check (position > 0)');

        DB::statement('alter table marketplace_orders add constraint marketplace_orders_money_is_not_negative check (
            items_total_minor >= 0 and grand_total_minor >= 0
        )');
    }

    public function down(): void
    {
        foreach ([
            'marketplace_orders_money_is_not_negative',
            'seller_orders_position_is_positive',
            'seller_orders_money_is_not_negative',
        ] as $constraint) {
            $table = str_starts_with($constraint, 'marketplace') ? 'marketplace_orders' : 'seller_orders';
            DB::statement("alter table {$table} drop constraint if exists {$constraint}");
        }

        foreach (['order_items_quantity_is_positive', 'order_items_money_is_not_negative', 'order_items_split_is_exact'] as $constraint) {
            DB::statement("alter table order_items drop constraint if exists {$constraint}");
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn([
                'brand_name_snapshot', 'store_name_snapshot',
                'product_slug_snapshot', 'variant_options_snapshot',
            ]);
        });

        DB::statement('drop index if exists payment_attempts_idempotency_key');

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('checkout_attempt_id');
            $table->dropColumn(['idempotency_key', 'updated_at']);
        });

        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropIndex(['reservation_reference']);
            $table->dropIndex(['status', 'payment_expires_at']);
            $table->dropConstrainedForeignId('checkout_attempt_id');
            $table->dropColumn(['payment_expires_at', 'reservation_reference']);
        });

        Schema::dropIfExists('checkout_attempts');

        DB::statement('drop index if exists customer_addresses_one_default');
        Schema::dropIfExists('customer_addresses');

        DB::statement('alter table cart_items drop constraint if exists cart_items_quantity_is_positive');

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'line_identity']);
            $table->unique(['cart_id', 'offer_id']);
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn('line_identity');
        });

        DB::statement('alter table carts drop constraint if exists carts_have_an_owner');
        DB::statement('drop index if exists carts_one_active_per_session');
        DB::statement('create unique index carts_session_token_unique on carts (session_token)');
        DB::statement('drop index if exists carts_one_active_per_user');

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn(['status', 'last_activity_at']);
        });
    }
};

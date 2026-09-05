<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews, wishlists, and the projections that make discovery and
 * analytics answerable without scanning the event stream on every request.
 *
 * Two rules shape every table below.
 *
 * FIRST, everything hangs off the CANONICAL PRODUCT, not the seller offer.
 * A review is a statement about a thing, and the thing does not change
 * because a second shop starts selling it. One iPhone, one rating, one
 * wishlist entry, one recommendation — however many sellers list it.
 *
 * SECOND, every table here is DERIVED and REBUILDABLE except the reviews
 * and wishlist entries themselves, which are what customers actually
 * wrote and saved. Summaries, popularity scores, associations and daily
 * metrics can all be dropped and recomputed from the rows underneath
 * them, which is what makes `reviews:reconcile-ratings`,
 * `recommendations:rebuild` and `analytics:rebuild` safe to run.
 *
 * Nothing here is financial. These tables are read by dashboards and
 * written by projections; the ledger, the orders and the payouts are read
 * and never touched, and an invariant test proves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->buildReviews();
        $this->buildRatingSummaries();
        $this->buildWishlist();
        $this->buildRecommendationProjections();
        $this->buildAnalyticsProjections();
    }

    /**
     * What a customer said about a product, and what happened to it.
     *
     * `verified_purchase` is stored rather than derived on read because it
     * is a statement about the evidence AT THE TIME the review was
     * written. An order refunded a year later does not retroactively make
     * the review a lie about who bought it — and recomputing it on every
     * page would make a rating flicker as unrelated records changed.
     *
     * The evidence itself is kept beside it: `order_item_id` is the line
     * the review is founded on, so a moderator can check the claim rather
     * than trust the flag. It is never serialised to a public DTO.
     */
    private function buildReviews(): void
    {
        Schema::create('product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // The purchase this review is founded on. Nullable because a
            // review may outlive the pruning of an ancient order line, and
            // because the verified flag records what was true when it was
            // written rather than what can still be joined to today.
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('seller_order_id')->nullable()->constrained('seller_orders')->nullOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('body');

            $table->string('status')->default('published');
            $table->boolean('verified_purchase')->default(false);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();

            $table->text('moderation_reason')->nullable();
            $table->foreignId('moderated_by_admin_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();

            $table->timestamps();

            // The product page reads published reviews newest first.
            $table->index(['product_id', 'status', 'id']);
            $table->index(['user_id', 'id']);
        });

        /*
         * A rating is one of five whole numbers.
         *
         * Enforced in the database because it is the one field that
         * arithmetic downstream depends on: a 0 would drag an average
         * below its own scale, a 6 would push it above, and a decimal
         * would make the integer sum in the summary meaningless.
         */
        DB::statement(
            'alter table product_reviews
             add constraint product_reviews_rating_is_one_to_five
             check (rating >= 1 and rating <= 5)'
        );

        /*
         * One live review per customer per canonical product.
         *
         * Withdrawn reviews are excluded, so a customer who takes theirs
         * down may write another. Rejected ones are NOT excluded: letting
         * a refused review be replaced by another would make moderation a
         * formality, and a genuine mistake is undone by restoring it.
         */
        DB::statement(
            "create unique index product_reviews_one_live_per_customer
             on product_reviews (user_id, product_id)
             where status <> 'withdrawn'"
        );

        /*
         * What happened to a review, and who did it. Append-only.
         *
         * The review row carries its current state because that is what a
         * query filters on; this carries how it got there, which is what
         * an appeal needs. §9: moderation history is never silently lost.
         */
        Schema::create('product_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_review_id')->constrained()->cascadeOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');

            $table->string('actor_type')->nullable();        // customer | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['product_review_id', 'id']);
        });
    }

    /**
     * The rating a product page shows, precomputed.
     *
     * One row per canonical product — never one per seller offer, which is
     * the whole point of §3. Rebuildable in its entirety from
     * `product_reviews`, which is what `reviews:reconcile-ratings` does and
     * what makes a stale row a bug that gets found rather than a number
     * nobody can check.
     *
     * The sum is stored beside the average so the average is derived
     * arithmetic rather than an independently maintained float: a summary
     * where `rating_sum / count` disagrees with `rating_average` is a
     * detectable corruption, and the reconciliation detects exactly that.
     */
    private function buildRatingSummaries(): void
    {
        Schema::create('product_rating_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();

            $table->unsignedInteger('published_review_count')->default(0);
            $table->unsignedInteger('verified_review_count')->default(0);
            $table->unsignedInteger('rating_sum')->default(0);

            // Two decimal places, which is what a product page shows and
            // what JSON-LD emits. Not a float: 4.35 must round-trip.
            $table->decimal('rating_average', 3, 2)->nullable();

            $table->unsignedInteger('count_1')->default(0);
            $table->unsignedInteger('count_2')->default(0);
            $table->unsignedInteger('count_3')->default(0);
            $table->unsignedInteger('count_4')->default(0);
            $table->unsignedInteger('count_5')->default(0);

            $table->timestamp('recomputed_at')->nullable();
            $table->timestamps();

            // Ordering products by rating, for discovery later.
            $table->index(['rating_average', 'published_review_count']);
        });
    }

    /**
     * Saved products.
     *
     * There is no `wishlists` parent table, deliberately. Phase-1 policy is
     * one wishlist per authenticated customer (§24), so a parent row would
     * carry a single foreign key and nothing else — a join to learn what
     * the customer id already says. If a later phase brings named lists,
     * this table gains a `wishlist_id` and the parent arrives with it.
     */
    private function buildWishlist(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->timestamp('created_at');

            // Saving the same thing twice is one save, and the database
            // says so rather than the button being disabled.
            $table->unique(['user_id', 'product_id'], 'wishlist_items_one_per_customer');

            $table->index(['user_id', 'id']);
            $table->index('product_id');
        });
    }

    /**
     * Derived discovery data. Dropped and rebuilt by
     * `recommendations:rebuild`; authoritative for nothing.
     */
    private function buildRecommendationProjections(): void
    {
        Schema::create('product_popularity_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // 7 or 30 days. Stored rather than implied so two windows can
            // coexist and a caller says which one it means.
            $table->unsignedSmallInteger('window_days');

            // Integer, because the weights are integers and a float score
            // would make "why is this ranked here" unanswerable.
            $table->unsignedBigInteger('score')->default(0);

            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('search_click_count')->default(0);
            $table->unsignedInteger('wishlist_count')->default(0);
            $table->unsignedInteger('cart_count')->default(0);
            $table->unsignedInteger('purchase_count')->default(0);

            $table->timestamp('computed_at');

            $table->unique(['product_id', 'window_days'], 'popularity_one_per_product_window');
            $table->index(['window_days', 'score']);
        });

        Schema::create('product_associations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('associated_product_id')->constrained('products')->cascadeOnDelete();

            // viewed_together | bought_together
            $table->string('kind');

            // How many distinct sessions or orders produced the pair. The
            // threshold is applied when reading, so lowering it later does
            // not require a rebuild.
            $table->unsignedInteger('support')->default(0);
            $table->unsignedBigInteger('score')->default(0);

            $table->timestamp('computed_at');

            $table->unique(['product_id', 'associated_product_id', 'kind'], 'associations_one_per_pair_kind');
            $table->index(['product_id', 'kind', 'score']);
        });

        // A product is not associated with itself, and a pair that said so
        // would sit at the top of every list it appeared in.
        DB::statement(
            'alter table product_associations
             add constraint product_associations_are_between_two_products
             check (product_id <> associated_product_id)'
        );
    }

    /**
     * Daily rollups. Dropped and rebuilt by `analytics:rebuild`.
     *
     * Every table is keyed by a DATE plus its subject, which is what makes
     * a rebuild idempotent: recomputing a day replaces exactly that day's
     * rows and touches nothing else.
     *
     * The financial columns on the marketplace and seller tables are COPIED
     * from the M7 financial truth at rebuild time, never recomputed from
     * behaviour. §48: a GMV derived from clickstream is a number that
     * disagrees with the ledger, and the ledger is right.
     */
    private function buildAnalyticsProjections(): void
    {
        Schema::create('daily_marketplace_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->char('currency', 3)->default('USD');

            // Behaviour, from interaction events.
            $table->unsignedInteger('product_views')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('searches')->default(0);
            $table->unsignedInteger('zero_result_searches')->default(0);
            $table->unsignedInteger('search_clicks')->default(0);
            $table->unsignedInteger('cart_adds')->default(0);
            $table->unsignedInteger('checkouts_started')->default(0);
            $table->unsignedInteger('wishlist_adds')->default(0);
            $table->unsignedInteger('recommendation_impressions')->default(0);
            $table->unsignedInteger('recommendation_clicks')->default(0);

            // Commerce, from orders and payments.
            $table->unsignedInteger('paid_orders')->default(0);
            $table->unsignedInteger('new_customers')->default(0);

            // Money, copied from M7's definitions. Signed, because net
            // sales after refunds can be below zero on a quiet day.
            $table->bigInteger('gmv_minor')->default(0);
            $table->bigInteger('refunds_minor')->default(0);
            $table->bigInteger('commission_minor')->default(0);

            $table->timestamp('computed_at');

            $table->unique(['day', 'currency'], 'marketplace_metrics_one_per_day_currency');
        });

        Schema::create('daily_product_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('search_impressions')->default(0);
            $table->unsignedInteger('search_clicks')->default(0);
            $table->unsignedInteger('wishlist_adds')->default(0);
            $table->unsignedInteger('cart_adds')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->unsignedInteger('units_sold')->default(0);

            // From order items on paid orders, never from clickstream.
            $table->bigInteger('gross_minor')->default(0);

            $table->timestamp('computed_at');

            $table->unique(['day', 'product_id'], 'product_metrics_one_per_day_product');
            $table->index(['product_id', 'day']);
        });

        Schema::create('daily_seller_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->foreignId('seller_account_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('store_views')->default(0);
            // Only where the seller's own offer was involved — see §52.
            $table->unsignedInteger('offer_impressions')->default(0);
            $table->unsignedInteger('offer_clicks')->default(0);

            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('units_sold')->default(0);
            $table->unsignedInteger('delivered_orders')->default(0);
            $table->unsignedInteger('refunded_orders')->default(0);

            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('refunds_minor')->default(0);
            $table->bigInteger('earnings_minor')->default(0);

            $table->timestamp('computed_at');

            $table->unique(['day', 'seller_account_id'], 'seller_metrics_one_per_day_seller');
            $table->index(['seller_account_id', 'day']);
        });

        Schema::create('daily_search_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('day');

            // Normalised: trimmed and lowercased, so "Kettle" and "kettle "
            // are one query rather than two rows nobody can compare.
            $table->string('query_normalised', 200);

            $table->unsignedInteger('searches')->default(0);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('zero_result_searches')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('cart_adds')->default(0);
            $table->unsignedInteger('purchases')->default(0);

            $table->timestamp('computed_at');

            $table->unique(['day', 'query_normalised'], 'search_metrics_one_per_day_query');
            $table->index(['day', 'searches']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_search_metrics');
        Schema::dropIfExists('daily_seller_metrics');
        Schema::dropIfExists('daily_product_metrics');
        Schema::dropIfExists('daily_marketplace_metrics');
        Schema::dropIfExists('product_associations');
        Schema::dropIfExists('product_popularity_scores');
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('product_rating_summaries');
        Schema::dropIfExists('product_review_events');
        Schema::dropIfExists('product_reviews');
    }
};

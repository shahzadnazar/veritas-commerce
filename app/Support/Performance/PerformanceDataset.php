<?php

declare(strict_types=1);

namespace App\Support\Performance;

use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A marketplace at launch scale, generated from arithmetic.
 *
 * This exists to make query plans mean something. PostgreSQL will scan a
 * four-hundred-row table sequentially however good the index is, so every
 * plan captured against factory-built test data is a plan for a database
 * that will never exist. The audit needs ten thousand products, twenty
 * thousand orders and a quarter of a million interaction events before it
 * can say anything at all about whether an index earns its keep.
 *
 * Three properties matter more than the row counts.
 *
 * **Deterministic.** No `random()` anywhere. Every value is a pure
 * function of the row's ordinal, scrambled through a Lehmer step
 * (`n * 48271 mod 2^31-1`) so that neighbouring rows are unrelated
 * without being unpredictable. Two operators on two machines get the same
 * database and can compare plans. The one exception is the time anchor:
 * rows are dated relative to `date_trunc('day', now())`, because a
 * dataset frozen at a literal date would drift out of every "last thirty
 * days" window the application actually queries.
 *
 * **Skewed.** Uniform data is the trap. If every seller has the same
 * number of offers and every category the same number of products, the
 * planner sees flat statistics, picks whatever is cheapest on average,
 * and the plan collapses in production where one seller has eight hundred
 * offers and one category has three thousand products. So the skew is
 * generated on purpose: three seller tiers spanning a 25x catalogue
 * range, a contested band of products carrying twenty offers each against
 * a long tail carrying one, and a category distribution where five
 * categories hold a third of the catalogue.
 *
 * **Scattered.** Physical order is a statistic too. `products.category_id`
 * assigned in ascending blocks would give PostgreSQL a correlation of 1.0
 * and flatter every index scan that reads it. Every derived attribute is
 * therefore drawn from the scrambled ordinal, not the ordinal, so rows
 * that are adjacent on disk are unrelated in content — which is what a
 * real table looks like after a year of writes.
 *
 * Nothing here touches the network. No Stripe, no object storage, no
 * mail, no queue: the rows are written as they would look after those
 * things had already happened.
 *
 * Every row carries the marker `0PERF` in its `public_id`, so a database
 * can always be asked whether it is contaminated. See
 * {@see PerformanceDataset::contamination()}.
 */
final class PerformanceDataset
{
    /**
     * The prefix stamped into every generated `public_id`.
     *
     * Crockford base32 characters only, so the values remain shaped like
     * the ULIDs the application issues, and 26 characters long, because
     * the columns are `char(26)` and a short value would be space-padded
     * into something that no longer starts with the marker.
     */
    public const MARKER = '0PERF';

    /**
     * The cheap contamination probe.
     *
     * Four small tables that the generator always writes and that stay
     * small in production, so a deployment check can ask "is this the
     * scale dataset?" without a sequential scan over the order history.
     * If the dataset is present at all, every one of these is marked.
     */
    public const SENTINEL_TABLES = ['seller_accounts', 'stores', 'categories', 'brands'];

    /** Tables whose `public_id` is checked when looking for contamination. */
    private const MARKED_TABLES = [
        'users', 'categories', 'brands', 'seller_accounts', 'stores',
        'products', 'offers', 'marketplace_orders', 'seller_orders',
        'order_items', 'seller_ledger_entries', 'payout_requests',
        'product_reviews',
    ];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var Closure(string): void */
    private $progress;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Build the whole dataset, returning the row count of each table.
     *
     * Not wrapped in a single transaction on purpose. The intermediate
     * `ANALYZE` calls need the rows visible to the planner for the steps
     * that follow — the order-item generator picks offers through a
     * temporary index, and doing that against unanalysed tables turns a
     * ninety-second build into a twenty-minute one.
     *
     * @param  callable(string): void  $progress
     * @return array<string, int>
     */
    public function build(PerformanceScale $scale, callable $progress): array
    {
        $this->progress = $progress(...);
        $this->counts = [];

        $this->wipe();
        $this->seedPeople($scale);
        $this->seedCatalogue($scale);
        $this->seedOffers($scale);
        $this->seedSearchDocuments();
        $this->seedOrders($scale);
        $this->seedFinance($scale);
        $this->seedEngagement($scale);
        $this->resetSequences();
        $this->analyse();

        return $this->counts;
    }

    /**
     * How many marked rows a database is carrying.
     *
     * The counterpart to the guard on the command: the guard stops the
     * dataset being written somewhere it should not be, and this answers
     * the question afterwards. A production database is expected to
     * return zero from every table.
     *
     * @param  array<int, string>|null  $tables  defaults to every marked table
     * @return array<string, int>
     */
    public static function contamination(?ConnectionInterface $connection = null, ?array $tables = null): array
    {
        $db = $connection ?? DB::connection();
        $found = [];

        foreach ($tables ?? self::MARKED_TABLES as $table) {
            $count = (int) $db->table($table)->where('public_id', 'like', self::MARKER.'%')->count();

            if ($count > 0) {
                $found[$table] = $count;
            }
        }

        return $found;
    }

    /**
     * Empty every application table.
     *
     * Discovered from the catalogue rather than listed, so a table added
     * by a later migration cannot silently survive a rebuild and leave
     * the dataset non-deterministic. `migrations` is the only exception:
     * truncating it would make the schema look unbuilt.
     */
    private function wipe(): void
    {
        $this->step('Emptying the target database');

        /** @var array<int, object{tablename: string}> $tables */
        $tables = $this->db->select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations' ORDER BY tablename"
        );

        $names = array_map(static fn (object $row): string => '"'.$row->tablename.'"', $tables);

        if ($names === []) {
            return;
        }

        $this->db->statement('TRUNCATE TABLE '.implode(', ', $names).' RESTART IDENTITY CASCADE');
    }

    private function seedPeople(PerformanceScale $scale): void
    {
        $this->step('Customers, sellers and stores');

        /*
         * A bcrypt hash of thirty-two random bytes that are then thrown
         * away. Valid in shape, so nothing that inspects the column
         * breaks, and unknown to everyone including this process, so the
         * dataset ships no usable credential. A load test that needs to
         * log in sets a password on one row itself.
         */
        $unusable = password_hash(base64_encode(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 4]);

        $this->run('users', <<<'SQL'
            INSERT INTO users (id, public_id, email, email_verified_at, password, first_name, last_name, phone, marketing_opt_in, created_at, updated_at)
            SELECT n,
                   :pid(n),
                   'perf-customer-' || n || '@veritas.invalid',
                   CASE WHEN :h(n) % 10 < 8 THEN :anchor - ((:h(n) % 500) || ' days')::interval END,
                   ?,
                   'Perf',
                   'Customer ' || n,
                   CASE WHEN :h(n) % 3 = 0 THEN '+1555' || lpad((:h(n) % 10000000)::text, 7, '0') END,
                   (:h(n) % 4 = 0),
                   :anchor - ((:h(n) % 700) || ' days')::interval,
                   :anchor
            FROM generate_series(1, :users) n
            SQL, $scale, [$unusable]);

        /*
         * Seller staff live above the customer id range so the two are
         * never confused, and so a query for "the users of this seller"
         * cannot accidentally pass by matching on id order.
         */
        $this->run('users (seller staff)', <<<'SQL'
            INSERT INTO users (id, public_id, email, email_verified_at, password, first_name, last_name, marketing_opt_in, created_at, updated_at)
            SELECT :users + s,
                   :pid(:users + s),
                   'perf-seller-' || s || '@veritas.invalid',
                   :anchor - ((:h(s) % 400) || ' days')::interval,
                   ?,
                   'Perf',
                   'Seller ' || s,
                   false,
                   :anchor - ((:h(s) % 400) || ' days')::interval,
                   :anchor
            FROM generate_series(1, :sellers) s
            SQL, $scale, [$unusable]);

        $this->run('seller_accounts', <<<'SQL'
            INSERT INTO seller_accounts (id, public_id, legal_name, business_type, status, approved_at, suspended_at, suspension_reason, ships_from_city, ships_from_state, clearing_period_days, created_at, updated_at)
            SELECT s,
                   :pid(s),
                   'Perf Trading ' || s || ' LLC',
                   CASE :h(s) % 3 WHEN 0 THEN 'llc' WHEN 1 THEN 'sole_trader' ELSE 'corporation' END,
                   CASE WHEN :h(s) % 100 < 88 THEN 'approved' WHEN :h(s) % 100 < 94 THEN 'pending' WHEN :h(s) % 100 < 98 THEN 'suspended' ELSE 'closed' END,
                   CASE WHEN :h(s) % 100 < 88 OR :h(s) % 100 >= 94 THEN :anchor - ((:h(s) % 500 + 30) || ' days')::interval END,
                   CASE WHEN :h(s) % 100 >= 94 AND :h(s) % 100 < 98 THEN :anchor - ((:h(s) % 60) || ' days')::interval END,
                   CASE WHEN :h(s) % 100 >= 94 AND :h(s) % 100 < 98 THEN 'Perf dataset: suspended for review' END,
                   'City ' || (:h(s) % 220),
                   (ARRAY['CA','NY','TX','WA','OR','IL','FL','MA'])[1 + (:h(s) % 8)],
                   CASE WHEN :h(s) % 5 = 0 THEN 14 ELSE 7 END,
                   :anchor - ((:h(s) % 500 + 40) || ' days')::interval,
                   :anchor
            FROM generate_series(1, :sellers) s
            SQL, $scale);

        $this->run('seller_memberships', <<<'SQL'
            INSERT INTO seller_memberships (seller_account_id, user_id, role, invited_at, accepted_at, created_at, updated_at)
            SELECT s, :users + s, 'owner',
                   :anchor - ((:h(s) % 400) || ' days')::interval,
                   :anchor - ((:h(s) % 400) || ' days')::interval,
                   :anchor - ((:h(s) % 400) || ' days')::interval,
                   :anchor
            FROM generate_series(1, :sellers) s
            SQL, $scale);

        $this->run('stores', <<<'SQL'
            INSERT INTO stores (id, public_id, seller_account_id, name, slug, description, is_open, timezone, business_city, business_state, business_country, default_low_stock_threshold, created_at, updated_at)
            SELECT s, :pid(s), s,
                   'Perf Store ' || s,
                   'perf-store-' || s,
                   'A generated storefront for scale testing. Store number ' || s || '.',
                   (:h(s) % 20 <> 0),
                   'UTC',
                   'City ' || (:h(s) % 220),
                   (ARRAY['CA','NY','TX','WA','OR','IL','FL','MA'])[1 + (:h(s) % 8)],
                   'US',
                   CASE WHEN :h(s) % 4 = 0 THEN 10 ELSE 5 END,
                   :anchor - ((:h(s) % 500 + 20) || ' days')::interval,
                   :anchor
            FROM generate_series(1, :sellers) s
            SQL, $scale);

        $this->run('inventory_locations', <<<'SQL'
            INSERT INTO inventory_locations (id, seller_account_id, name, is_default, created_at, updated_at)
            SELECT s, s, 'Default', true, :anchor, :anchor
            FROM generate_series(1, :sellers) s
            SQL, $scale);

        $this->run('payout_accounts', <<<'SQL'
            INSERT INTO payout_accounts (id, public_id, seller_account_id, type, provider, provider_account_reference, display_label, last4, country, currency, status, verified_at, changed_at, created_at, updated_at)
            SELECT s, :pid(s), s, 'bank_account', 'manual',
                   'perf-destination-' || s,
                   'Perf Bank ****' || lpad((:h(s) % 10000)::text, 4, '0'),
                   lpad((:h(s) % 10000)::text, 4, '0'),
                   'US', 'USD',
                   CASE WHEN :h(s) % 10 < 9 THEN 'verified' ELSE 'pending' END,
                   CASE WHEN :h(s) % 10 < 9 THEN :anchor - ((:h(s) % 300) || ' days')::interval END,
                   :anchor - ((:h(s) % 300) || ' days')::interval,
                   :anchor - ((:h(s) % 300) || ' days')::interval,
                   :anchor
            FROM generate_series(1, :sellers) s
            SQL, $scale);
    }

    private function seedCatalogue(PerformanceScale $scale): void
    {
        $this->step('Categories, brands and products');

        $this->run('categories', <<<'SQL'
            INSERT INTO categories (id, public_id, parent_id, name, slug, description, is_visible, position, path, depth, created_at, updated_at)
            SELECT n, :pid(n),
                   CASE
                       WHEN n <= :roots THEN NULL
                       WHEN n <= :roots + :mids THEN 1 + ((n - :roots - 1) % :roots)
                       ELSE :roots + 1 + ((n - :roots - :mids - 1) % :mids)
                   END,
                   'Perf Category ' || n,
                   'perf-category-' || n,
                   'Generated category ' || n || ' for scale testing.',
                   (n % 40 <> 0),
                   n,
                   CASE
                       WHEN n <= :roots THEN '/' || n
                       WHEN n <= :roots + :mids THEN '/' || (1 + ((n - :roots - 1) % :roots)) || '/' || n
                       ELSE '/' || (1 + (((:roots + 1 + ((n - :roots - :mids - 1) % :mids)) - :roots - 1) % :roots))
                            || '/' || (:roots + 1 + ((n - :roots - :mids - 1) % :mids))
                            || '/' || n
                   END,
                   CASE WHEN n <= :roots THEN 0 WHEN n <= :roots + :mids THEN 1 ELSE 2 END,
                   :anchor - '400 days'::interval,
                   :anchor
            FROM generate_series(1, :categories) n
            SQL, $scale);

        $this->run('brands', <<<'SQL'
            INSERT INTO brands (id, public_id, name, slug, normalised_name, is_active, approved_at, description, created_at, updated_at)
            SELECT n, :pid(n),
                   (ARRAY['Aeris','Vantor','Nimbus','Corvid','Lumen','Halcyon','Verdant','Orrery','Kestrel','Basalt'])[1 + (:h(n) % 10)] || ' ' || n,
                   'perf-brand-' || n,
                   lower((ARRAY['Aeris','Vantor','Nimbus','Corvid','Lumen','Halcyon','Verdant','Orrery','Kestrel','Basalt'])[1 + (:h(n) % 10)] || ' ' || n),
                   (:h(n) % 25 <> 0),
                   :anchor - ((:h(n) % 500) || ' days')::interval,
                   'Generated brand for scale testing.',
                   :anchor - ((:h(n) % 500) || ' days')::interval,
                   :anchor
            FROM generate_series(1, :brands) n
            SQL, $scale);

        /*
         * Category, brand and status are all drawn from the scrambled
         * ordinal rather than the ordinal. Assigning them in ascending
         * blocks would be simpler and would hand PostgreSQL a physical
         * correlation of 1.0 on `category_id` — every index scan would
         * look free, and none of it would survive contact with a real
         * table. Scattering costs nothing here and keeps the statistics
         * honest.
         */
        $this->run('products', <<<'SQL'
            INSERT INTO products (
                id, public_id, category_id, brand_id, title, slug, description, gtin, upc, ean,
                normalised_title, model_number, created_by_seller_account_id, status,
                submitted_at, reviewed_at, published_at, created_at, updated_at
            )
            SELECT n, :pid(n),
                   CASE
                       WHEN :h(n) % 10000 < 3000 THEN :leafBase + 1 + (:h(n) % :leafBig)
                       WHEN :h(n) % 10000 < 6000 THEN :leafBase + 1 + (:h(n) % :leafMid)
                       ELSE :leafBase + 1 + (:h(n) % :leaves)
                   END,
                   CASE
                       WHEN :h2(n) % 100 < 8 THEN NULL
                       WHEN :h2(n) % 100 < 48 THEN 1 + (:h2(n) % 20)
                       ELSE 1 + (:h2(n) % :brands)
                   END,
                   (ARRAY['Compact','Wide','Insulated','Folding','Rugged','Silent','Precision','Featherweight','Modular','Heritage'])[1 + (:h(n) % 10)]
                       || ' ' || (ARRAY['Stainless','Walnut','Titanium','Canvas','Ceramic','Copper','Bamboo','Merino'])[1 + (:h2(n) % 8)]
                       || ' ' || (ARRAY['Kettle','Lantern','Backpack','Chair','Grinder','Speaker','Trowel','Notebook','Kayak','Thermometer','Blender','Headlamp'])[1 + ((:h(n) / 7) % 12)]
                       || ' ' || (ARRAY['X','S','Pro','Mk'])[1 + (:h2(n) % 4)] || (1000 + (:h(n) % 9000)),
                   'perf-product-' || n,
                   'A generated catalogue entry used for scale testing. It describes a '
                       || lower((ARRAY['Kettle','Lantern','Backpack','Chair','Grinder','Speaker','Trowel','Notebook','Kayak','Thermometer','Blender','Headlamp'])[1 + ((:h(n) / 7) % 12)])
                       || ' with an unremarkable specification and a body of text long enough for full-text ranking to have something to weigh.',
                   CASE WHEN :h(n) % 10 < 6 THEN lpad((:h(n))::text, 13, '0') END,
                   CASE WHEN :h(n) % 10 < 3 THEN lpad((:h2(n))::text, 12, '0') END,
                   CASE WHEN :h(n) % 10 = 9 THEN lpad(((:h(n) / 3) + 1)::text, 13, '0') END,
                   lower((ARRAY['Compact','Wide','Insulated','Folding','Rugged','Silent','Precision','Featherweight','Modular','Heritage'])[1 + (:h(n) % 10)]
                       || ' ' || (ARRAY['Stainless','Walnut','Titanium','Canvas','Ceramic','Copper','Bamboo','Merino'])[1 + (:h2(n) % 8)]
                       || ' ' || (ARRAY['Kettle','Lantern','Backpack','Chair','Grinder','Speaker','Trowel','Notebook','Kayak','Thermometer','Blender','Headlamp'])[1 + ((:h(n) / 7) % 12)]),
                   'MDL-' || (1000 + (:h(n) % 9000)),
                   1 + (:h2(n) % :sellers),
                   CASE
                       WHEN :h(n) % 100 < 78 THEN 'published'
                       WHEN :h(n) % 100 < 84 THEN 'draft'
                       WHEN :h(n) % 100 < 89 THEN 'pending_review'
                       WHEN :h(n) % 100 < 92 THEN 'changes_requested'
                       WHEN :h(n) % 100 < 95 THEN 'approved'
                       WHEN :h(n) % 100 < 97 THEN 'rejected'
                       WHEN :h(n) % 100 < 99 THEN 'suspended'
                       ELSE 'archived'
                   END,
                   :anchor - ((:h(n) % 600 + 5) || ' days')::interval,
                   CASE WHEN :h(n) % 100 < 78 OR :h(n) % 100 >= 89 THEN :anchor - ((:h(n) % 600) || ' days')::interval END,
                   CASE WHEN :h(n) % 100 < 78 THEN :anchor - ((:h(n) % 600) || ' days')::interval END,
                   :anchor - ((:h(n) % 600 + 6) || ' days')::interval,
                   :anchor - ((:h(n) % 90) || ' days')::interval
            FROM generate_series(1, :products) n
            SQL, $scale);

        $this->run('product_media', <<<'SQL'
            INSERT INTO product_media (product_id, public_id, path, disk, position, is_primary, mime, bytes, width, height, alt_text, processing_state, processed_at, created_at, updated_at)
            SELECT p.id, :pid(p.id),
                   'perf/products/' || p.id || '.jpg',
                   'public', 0, true, 'image/jpeg',
                   40000 + (:h(p.id) % 400000),
                   1200, 1200,
                   p.title,
                   'ready',
                   :anchor - ((:h(p.id) % 300) || ' days')::interval,
                   :anchor - ((:h(p.id) % 300) || ' days')::interval,
                   :anchor
            FROM products p
            WHERE :h(p.id) % 10 < 9
            SQL, $scale);
    }

    /**
     * Offers, and the skew that makes them worth measuring.
     *
     * Three tiers of seller and two bands of product. Ten sellers carry a
     * catalogue of eight hundred, fifty carry two hundred, and the
     * remaining two hundred and forty carry thirty — a 25x spread, which
     * is the spread that decides whether `offers(seller_account_id, ...)`
     * is worth its pages. Crossing that, a contested band of three
     * hundred products attracts an offer from most sellers while the
     * ten-thousand-strong tail attracts one or two, so `product_id`
     * statistics are bimodal rather than flat.
     *
     * The arithmetic keeps `(seller_account_id, product_id)` unique
     * without a retry loop: within the contested band a seller walks
     * consecutive ids from its own offset, and outside it a seller walks
     * a fixed stride chosen coprime to the band width, so no seller can
     * land on the same product twice.
     */
    private function seedOffers(PerformanceScale $scale): void
    {
        $this->step('Offers and stock');

        $this->run('offers', <<<'SQL'
            WITH seller AS (
                SELECT s,
                       CASE
                           WHEN s <= :largeSellers THEN :largeOffers
                           WHEN s <= :largeSellers + :mediumSellers THEN :mediumOffers
                           ELSE :smallOffers
                       END
                       -- Jitter inside the tier, so the catalogue-size
                       -- histogram is three humps rather than three
                       -- spikes and a "sellers larger than average"
                       -- query has a real distribution to cut.
                       + (:h(s) % (1 + (CASE
                           WHEN s <= :largeSellers THEN :largeOffers
                           WHEN s <= :largeSellers + :mediumSellers THEN :mediumOffers
                           ELSE :smallOffers
                       END / 4))) AS n
                FROM generate_series(1, :sellers) s
            ),
            slot AS (
                SELECT seller.s,
                       i,
                       LEAST(seller.n, :contestedPerSeller) AS hot
                FROM seller, LATERAL generate_series(1, seller.n) i
            ),
            offer AS (
                SELECT s, i,
                       CASE
                           WHEN i <= hot THEN 1 + ((s * 13 + i) % :contested)
                           ELSE :contested + 1 + (((s::bigint * 977) + ((i - hot) * :coldStep)) % (:products - :contested))
                       END AS product_id,
                       /*
                        * Ids assigned in scrambled order, and the rows
                        * then inserted in id order. Numbering by
                        * (seller, slot) instead would bury every
                        * seller's offers in one run of pages and hand
                        * `seller_account_id` a physical correlation of
                        * 1.0 — under which an index scan on it looks
                        * free and the measurement is worthless. Real
                        * offers arrive interleaved over months.
                        */
                       row_number() OVER (ORDER BY (((s * 7919) + i)::bigint * 48271) % 2147483647) AS id
                FROM slot
            )
            INSERT INTO offers (
                id, public_id, seller_account_id, store_id, product_id, product_variant_id,
                seller_sku, condition, price_minor, compare_at_price_minor, currency, status,
                published_at, archived_at, handling_days, shipping_flat_minor, low_stock_threshold,
                created_at, updated_at
            )
            SELECT o.id, :pid(o.id), o.s, o.s, o.product_id, NULL,
                   'PERF-' || o.s || '-' || o.i,
                   CASE
                       WHEN :h(o.id) % 100 < 80 THEN 'new'
                       WHEN :h(o.id) % 100 < 88 THEN 'refurbished'
                       WHEN :h(o.id) % 100 < 94 THEN 'used_like_new'
                       WHEN :h(o.id) % 100 < 98 THEN 'used_good'
                       ELSE 'used_acceptable'
                   END,
                   999 + (:h(o.product_id) % 48000) + ((:h2(o.id) % 40) * 137),
                   CASE WHEN :h2(o.id) % 4 = 0 THEN 999 + (:h(o.product_id) % 48000) + ((:h2(o.id) % 40) * 137) + 500 + (:h2(o.id) % 3000) END,
                   'USD',
                   CASE
                       WHEN :h2(o.id) % 100 < 85 THEN 'published'
                       WHEN :h2(o.id) % 100 < 90 THEN 'draft'
                       WHEN :h2(o.id) % 100 < 94 THEN 'pending_review'
                       WHEN :h2(o.id) % 100 < 96 THEN 'approved'
                       WHEN :h2(o.id) % 100 < 98 THEN 'suspended'
                       ELSE 'archived'
                   END,
                   CASE WHEN :h2(o.id) % 100 < 85 THEN :anchor - ((:h(o.id) % 500) || ' days')::interval END,
                   CASE WHEN :h2(o.id) % 100 >= 98 THEN :anchor - ((:h(o.id) % 100) || ' days')::interval END,
                   1 + (:h(o.id) % 4),
                   CASE WHEN :h(o.id) % 3 = 0 THEN 0 ELSE 399 + ((:h(o.id) % 12) * 50) END,
                   CASE WHEN :h2(o.id) % 6 = 0 THEN 3 END,
                   :anchor - ((:h(o.id) % 500 + 1) || ' days')::interval,
                   :anchor - ((:h(o.id) % 60) || ' days')::interval
            FROM offer o
            JOIN products p ON p.id = o.product_id
            ORDER BY o.id
            SQL, $scale);

        /*
         * A fifth of the stock rows are at zero on hand. Out of stock is
         * not an edge case in a marketplace, it is a permanent fraction
         * of the catalogue, and it is the fraction that decides whether
         * the discovery index can answer "in stock, cheapest first"
         * without reading everything.
         */
        $this->run('inventory_balances', <<<'SQL'
            INSERT INTO inventory_balances (offer_id, inventory_location_id, on_hand, reserved, notified_state, created_at, updated_at)
            SELECT o.id, o.seller_account_id,
                   CASE WHEN :h(o.id) % 100 < 20 THEN 0 ELSE 1 + (:h(o.id) % 240) END,
                   CASE WHEN :h(o.id) % 100 < 20 THEN 0 ELSE LEAST(1 + (:h(o.id) % 240), :h2(o.id) % 4) END,
                   CASE WHEN :h(o.id) % 100 < 20 THEN 'out_of_stock' END,
                   :anchor - ((:h(o.id) % 500 + 1) || ' days')::interval,
                   :anchor - ((:h(o.id) % 30) || ' days')::interval
            FROM offers o
            SQL, $scale);

        /*
         * The movement ledger behind those balances, because `on_hand`
         * and `reserved` are cached numbers and `inventory:reconcile`
         * exists to prove they still equal their sources. A dataset that
         * skipped this would fail the application's own consistency
         * check on the first run, and an audit that starts from an
         * inconsistent database has nothing to stand on.
         *
         * Stock arrives in one to three deliveries rather than a single
         * opening balance: the table is append-only and hot in
         * production, and its size relative to `inventory_balances` is
         * part of what makes it worth measuring.
         */
        $this->run('inventory_movements', <<<'SQL'
            WITH split AS (
                SELECT b.offer_id, b.inventory_location_id, b.on_hand,
                       1 + (:h(b.offer_id) % 3) AS parts
                FROM inventory_balances b
                WHERE b.on_hand > 0
            ),
            chunked AS (
                SELECT s.*, i,
                       CASE WHEN i < s.parts THEN s.on_hand / s.parts
                            ELSE s.on_hand - ((s.on_hand / s.parts) * (s.parts - 1)) END AS change
                FROM split s, LATERAL generate_series(1, s.parts) i
            ),
            running AS (
                SELECT c.*,
                       sum(c.change) OVER (PARTITION BY c.offer_id ORDER BY c.i ROWS UNBOUNDED PRECEDING) AS resulting
                FROM chunked c
                WHERE c.change > 0
            )
            INSERT INTO inventory_movements (
                public_id, offer_id, inventory_location_id, on_hand_change, resulting_on_hand,
                reserved_change, resulting_reserved, reason, actor_type, actor_id, created_at
            )
            SELECT :pid((r.offer_id * 8) + r.i), r.offer_id, r.inventory_location_id,
                   r.change, r.resulting, 0, 0,
                   CASE WHEN r.i = 1 THEN 'opening_stock' ELSE 'restock_received' END,
                   'seller_user', NULL,
                   :anchor - ((:h((r.offer_id * 8) + r.i) % 400) || ' days')::interval
            FROM running r
            SQL, $scale);

        $this->run('inventory_reservations', <<<'SQL'
            INSERT INTO inventory_reservations (
                public_id, offer_id, inventory_location_id, quantity, status, reference,
                expires_at, created_at, updated_at
            )
            SELECT :pid(b.offer_id), b.offer_id, b.inventory_location_id, b.reserved, 'held',
                   'perf-hold-' || b.offer_id,
                   now() + '15 minutes'::interval,
                   now() - ((:h(b.offer_id) % 900) || ' seconds')::interval,
                   now()
            FROM inventory_balances b
            WHERE b.reserved > 0
            SQL, $scale);

        $this->run('inventory_movements (holds)', <<<'SQL'
            INSERT INTO inventory_movements (
                public_id, offer_id, inventory_location_id, on_hand_change, resulting_on_hand,
                reserved_change, resulting_reserved, reason, actor_type, actor_id, created_at
            )
            SELECT :pid((b.offer_id * 8) + 7), b.offer_id, b.inventory_location_id,
                   0, b.on_hand, b.reserved, b.reserved, 'order_reservation', 'customer', NULL,
                   now() - ((:h(b.offer_id) % 900) || ' seconds')::interval
            FROM inventory_balances b
            WHERE b.reserved > 0
            SQL, $scale);
    }

    /**
     * The read model discovery actually queries.
     *
     * Rebuilt here from the rows just written rather than by replaying
     * the application's indexer, because the indexer runs one product at
     * a time through a queue and ten thousand round trips would dominate
     * the build. The shape is the same: what matters for the audit is
     * that `is_public`, `in_stock`, the price range and the tsvector
     * agree with the offers underneath them, which a single grouped
     * statement guarantees more reliably than ten thousand jobs.
     */
    private function seedSearchDocuments(): void
    {
        $this->step('Search documents');

        $this->run('product_search_documents', <<<'SQL'
            WITH live AS (
                SELECT o.product_id,
                       count(*) AS offer_count,
                       count(*) FILTER (WHERE b.on_hand - b.reserved > 0) AS in_stock_offer_count,
                       min(o.price_minor) AS lowest,
                       max(o.price_minor) AS highest,
                       array_agg(DISTINCT o.condition) AS conditions
                FROM offers o
                JOIN inventory_balances b ON b.offer_id = o.id
                JOIN seller_accounts sa ON sa.id = o.seller_account_id
                JOIN stores st ON st.id = o.store_id
                WHERE o.status = 'published' AND sa.status = 'approved' AND st.is_open
                GROUP BY o.product_id
            )
            INSERT INTO product_search_documents (
                product_id, title, brand_name, category_path, searchable_text, is_public,
                lowest_price_minor, highest_price_minor, currency, offer_count, in_stock,
                in_stock_offer_count, indexed_at, slug, normalised_title, category_id, brand_id,
                primary_image_disk, primary_image_path, primary_image_alt, published_at,
                category_ancestor_ids, conditions, identifiers, attributes
            )
            SELECT p.id, p.title, b.name, c.name,
                   p.title || ' ' || COALESCE(b.name, '') || ' ' || c.name || ' ' || COALESCE(p.description, ''),
                   (p.status = 'published' AND live.offer_count IS NOT NULL),
                   live.lowest, live.highest, 'USD',
                   COALESCE(live.offer_count, 0),
                   COALESCE(live.in_stock_offer_count, 0) > 0,
                   COALESCE(live.in_stock_offer_count, 0),
                   :anchor,
                   p.slug, p.normalised_title, p.category_id, p.brand_id,
                   m.disk, m.path, m.alt_text, p.published_at,
                   string_to_array(trim(both '/' from c.path), '/')::bigint[],
                   COALESCE(live.conditions, ARRAY[]::text[]),
                   array_remove(ARRAY[p.gtin, p.upc, p.ean, p.model_number], NULL),
                   jsonb_build_object(
                       'material', (ARRAY['stainless','walnut','titanium','canvas','ceramic','copper','bamboo','merino'])[1 + (:h2(p.id) % 8)],
                       'colour', (ARRAY['black','white','sage','ochre','navy','clay'])[1 + (:h(p.id) % 6)]
                   )
            FROM products p
            JOIN categories c ON c.id = p.category_id
            LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN product_media m ON m.product_id = p.id AND m.is_primary
            LEFT JOIN live ON live.product_id = p.id
            SQL);
    }

    /**
     * Orders, seller orders, items and shipments.
     *
     * Totals are written as zero and then filled in from the rows below
     * them. It would be shorter to compute an order's grand total in the
     * same statement that inserts it, and it would also be a second
     * implementation of the arithmetic the check constraints enforce —
     * one that could drift. Aggregating upward from the items means the
     * constraints are proving the seeder correct rather than the seeder
     * assuming it.
     */
    private function seedOrders(PerformanceScale $scale): void
    {
        $this->step('Orders');

        /*
         * Two throwaway tables so an item can pick an offer that its
         * seller actually lists, in one indexed lookup rather than a
         * correlated subquery per row. They live for this connection
         * only and are dropped at the end of the build.
         */
        $this->db->statement(<<<'SQL'
            CREATE TEMPORARY TABLE perf_seller AS
            SELECT o.seller_account_id, o.store_id, count(*) AS offer_count,
                   row_number() OVER (ORDER BY o.seller_account_id) AS rn
            FROM offers o
            JOIN seller_accounts sa ON sa.id = o.seller_account_id AND sa.status = 'approved'
            JOIN stores st ON st.id = o.store_id AND st.is_open
            WHERE o.status = 'published'
            GROUP BY o.seller_account_id, o.store_id
            SQL);

        $this->db->statement('CREATE UNIQUE INDEX perf_seller_rn ON perf_seller (rn)');

        $this->db->statement(<<<'SQL'
            CREATE TEMPORARY TABLE perf_offer AS
            SELECT o.id AS offer_id, o.seller_account_id, o.product_id, o.price_minor, o.seller_sku,
                   row_number() OVER (PARTITION BY o.seller_account_id ORDER BY o.id) AS rn
            FROM offers o
            JOIN seller_accounts sa ON sa.id = o.seller_account_id AND sa.status = 'approved'
            JOIN stores st ON st.id = o.store_id AND st.is_open
            WHERE o.status = 'published'
            SQL);

        $this->db->statement('CREATE UNIQUE INDEX perf_offer_pick ON perf_offer (seller_account_id, rn)');
        $this->db->statement('ANALYZE perf_seller');
        $this->db->statement('ANALYZE perf_offer');

        $sellerCount = (int) $this->db->table('perf_seller')->count();

        if ($sellerCount === 0) {
            throw new RuntimeException('No approved seller has a published offer; the order generator has nothing to sell.');
        }

        $this->run('marketplace_orders', <<<'SQL'
            INSERT INTO marketplace_orders (
                id, public_id, reference, user_id, email, status, currency,
                ship_name, ship_line1, ship_city, ship_state, ship_postcode, ship_country, ship_phone,
                placed_at, completed_at, cancelled_at, payment_expires_at, created_at, updated_at
            )
            SELECT n, :pid(n),
                   'PERF-' || lpad(n::text, 10, '0'),
                   1 + (:h(n) % :users),
                   'perf-customer-' || (1 + (:h(n) % :users)) || '@veritas.invalid',
                   CASE
                       WHEN :h(n) % 100 < 8 THEN 'pending_payment'
                       WHEN :h(n) % 100 < 14 THEN 'cancelled'
                       WHEN :h(n) % 100 < 22 THEN 'paid'
                       WHEN :h(n) % 100 < 30 THEN 'processing'
                       WHEN :h(n) % 100 < 42 THEN 'shipped'
                       WHEN :h(n) % 100 < 58 THEN 'delivered'
                       WHEN :h(n) % 100 < 61 THEN 'partially_refunded'
                       ELSE 'completed'
                   END,
                   'USD',
                   'Perf Customer ' || (1 + (:h(n) % :users)),
                   (100 + (:h(n) % 9000)) || ' Generated Street',
                   'City ' || (:h(n) % 220),
                   (ARRAY['CA','NY','TX','WA','OR','IL','FL','MA'])[1 + (:h(n) % 8)],
                   lpad((:h(n) % 99999)::text, 5, '0'),
                   'US',
                   '+1555' || lpad((:h2(n) % 10000000)::text, 7, '0'),
                   :anchor - ((:h(n) % 400) || ' days')::interval - ((:h2(n) % 86400) || ' seconds')::interval,
                   CASE WHEN :h(n) % 100 >= 61 THEN :anchor - ((:h(n) % 400) || ' days')::interval + '9 days'::interval END,
                   CASE WHEN :h(n) % 100 >= 8 AND :h(n) % 100 < 14 THEN :anchor - ((:h(n) % 400) || ' days')::interval + '1 hour'::interval END,
                   CASE WHEN :h(n) % 100 < 8 THEN :anchor - ((:h(n) % 400) || ' days')::interval + '30 minutes'::interval END,
                   :anchor - ((:h(n) % 400) || ' days')::interval - ((:h2(n) % 86400) || ' seconds')::interval,
                   :anchor - ((:h(n) % 400) || ' days')::interval
            FROM generate_series(1, :orders) n
            SQL, $scale);

        /*
         * Sixty per cent of orders go to one of the ten largest sellers.
         * A uniform draw would give every seller the same order history
         * and hide the case the seller dashboard has to survive: the
         * store with forty thousand rows behind its first page.
         */
        $this->run('seller_orders', <<<'SQL'
            WITH pick AS (
                SELECT o.n, j,
                       CASE
                           WHEN :h2((o.n * 4) + j) % 100 < 60 THEN 1 + (:h((o.n * 4) + j) % LEAST(10, :sellerCount))
                           ELSE 1 + (:h((o.n * 4) + j) % :sellerCount)
                       END AS rn
                FROM (SELECT n, 1 + (:h2(n) % 3) AS k FROM generate_series(1, :orders) n) o,
                     LATERAL generate_series(1, o.k) j
            ),
            deduped AS (
                SELECT DISTINCT ON (n, rn) n, rn, j FROM pick ORDER BY n, rn, j
            ),
            numbered AS (
                SELECT n, rn,
                       row_number() OVER (PARTITION BY n ORDER BY rn) AS "position",
                       row_number() OVER (ORDER BY n, rn) AS id
                FROM deduped
            )
            INSERT INTO seller_orders (
                id, public_id, reference, marketplace_order_id, seller_account_id, store_id,
                position, status, currency, confirmed_at, processing_at, packed_at, shipped_at,
                delivered_at, completed_at, cancelled_at, earnings_clear_at, created_at, updated_at
            )
            SELECT nb.id, :pid(nb.id),
                   mo.reference || '-' || nb."position",
                   mo.id, ps.seller_account_id, ps.store_id, nb."position",
                   CASE
                       WHEN mo.status = 'partially_shipped' AND nb."position" > 1 THEN 'confirmed'
                       WHEN mo.status = 'partially_delivered' AND nb."position" > 1 THEN 'shipped'
                       WHEN mo.status = 'partially_refunded' THEN 'delivered'
                       ELSE mo.status
                   END,
                   'USD',
                   CASE WHEN mo.status NOT IN ('pending_payment', 'cancelled') THEN mo.placed_at + '20 minutes'::interval END,
                   CASE WHEN mo.status NOT IN ('pending_payment', 'cancelled', 'paid') THEN mo.placed_at + '1 day'::interval END,
                   CASE WHEN mo.status IN ('shipped', 'delivered', 'completed', 'partially_refunded') THEN mo.placed_at + '2 days'::interval END,
                   CASE WHEN mo.status IN ('shipped', 'delivered', 'completed', 'partially_refunded') THEN mo.placed_at + '3 days'::interval END,
                   CASE WHEN mo.status IN ('delivered', 'completed', 'partially_refunded') THEN mo.placed_at + '6 days'::interval END,
                   mo.completed_at,
                   mo.cancelled_at,
                   CASE WHEN mo.status IN ('delivered', 'completed', 'partially_refunded')
                        THEN mo.placed_at + '6 days'::interval + ((COALESCE(sa.clearing_period_days, 7)) || ' days')::interval END,
                   mo.created_at,
                   mo.updated_at
            FROM numbered nb
            JOIN perf_seller ps ON ps.rn = nb.rn
            JOIN marketplace_orders mo ON mo.id = nb.n
            JOIN seller_accounts sa ON sa.id = ps.seller_account_id
            SQL, $scale, [], ['sellerCount' => $sellerCount]);

        $this->run('order_items', <<<'SQL'
            WITH item AS (
                SELECT so.id AS seller_order_id, so.seller_account_id, so.status, so.created_at, j,
                       row_number() OVER (ORDER BY so.id, j) AS id
                FROM seller_orders so,
                     LATERAL generate_series(1, 1 + (:h(so.id) % 2)) j
            ),
            total AS (
                SELECT seller_account_id, count(*) AS offers FROM perf_offer GROUP BY seller_account_id
            ),
            priced AS (
                SELECT it.*, po.offer_id, po.product_id, po.price_minor, po.seller_sku,
                       1 + (:h((it.seller_order_id * 8) + it.j) % 3) AS quantity
                FROM item it
                JOIN total t ON t.seller_account_id = it.seller_account_id
                JOIN perf_offer po ON po.seller_account_id = it.seller_account_id
                                  AND po.rn = 1 + (:h((it.seller_order_id * 8) + it.j) % t.offers)
            ),
            lined AS (
                SELECT priced.*, price_minor * quantity AS line_total
                FROM priced
            )
            INSERT INTO order_items (
                id, public_id, seller_order_id, offer_id, product_id, product_title, seller_sku,
                currency, unit_price_snapshot_minor, quantity, discount_snapshot_minor, line_total_minor,
                tax_amount_minor, tax_rate_snapshot, tax_source, commission_rate_snapshot,
                commission_scope_snapshot, commission_amount_minor, seller_earning_amount_minor,
                snapshotted_at, allocated_quantity, delivered_quantity,
                brand_name_snapshot, store_name_snapshot, product_slug_snapshot, created_at, updated_at
            )
            SELECT l.id, :pid(l.id), l.seller_order_id, l.offer_id, l.product_id,
                   p.title, l.seller_sku, 'USD', l.price_minor, l.quantity, 0, l.line_total,
                   CASE WHEN :h2(l.id) % 2 = 0 THEN (l.line_total * 7) / 100 ELSE 0 END,
                   CASE WHEN :h2(l.id) % 2 = 0 THEN 7.00 ELSE 0.00 END,
                   'flat_rate',
                   12.00, 'global',
                   (l.line_total * 12) / 100,
                   l.line_total - ((l.line_total * 12) / 100),
                   l.created_at,
                   CASE WHEN l.status IN ('shipped', 'delivered', 'completed', 'partially_refunded') THEN l.quantity ELSE 0 END,
                   CASE WHEN l.status IN ('delivered', 'completed', 'partially_refunded') THEN l.quantity ELSE 0 END,
                   b.name, st.name, p.slug, l.created_at, l.created_at
            FROM lined l
            JOIN products p ON p.id = l.product_id
            JOIN seller_orders so ON so.id = l.seller_order_id
            JOIN stores st ON st.id = so.store_id
            LEFT JOIN brands b ON b.id = p.brand_id
            SQL, $scale);

        $this->step('Rolling item totals up into the orders');

        $this->db->statement(<<<'SQL'
            UPDATE seller_orders so
            SET items_total_minor = agg.items,
                tax_total_minor = agg.tax,
                shipping_total_minor = agg.shipping,
                commission_total_minor = agg.commission,
                seller_earning_total_minor = agg.earning,
                order_total_minor = agg.items + agg.shipping + agg.tax
            FROM (
                SELECT oi.seller_order_id,
                       sum(oi.line_total_minor) AS items,
                       sum(oi.tax_amount_minor) AS tax,
                       sum(oi.commission_amount_minor) AS commission,
                       sum(oi.seller_earning_amount_minor) AS earning,
                       CASE WHEN (oi.seller_order_id * 48271 % 2147483647) % 3 = 0 THEN 0 ELSE 599 END AS shipping
                FROM order_items oi
                GROUP BY oi.seller_order_id
            ) agg
            WHERE agg.seller_order_id = so.id
            SQL);

        $this->db->statement(<<<'SQL'
            UPDATE marketplace_orders mo
            SET items_total_minor = agg.items,
                shipping_total_minor = agg.shipping,
                tax_total_minor = agg.tax,
                grand_total_minor = agg.items + agg.shipping + agg.tax
            FROM (
                SELECT so.marketplace_order_id,
                       sum(so.items_total_minor) AS items,
                       sum(so.shipping_total_minor) AS shipping,
                       sum(so.tax_total_minor) AS tax
                FROM seller_orders so
                GROUP BY so.marketplace_order_id
            ) agg
            WHERE agg.marketplace_order_id = mo.id
            SQL);

        $this->run('shipments', <<<'SQL'
            INSERT INTO shipments (id, public_id, reference, seller_order_id, sequence, status,
                carrier_name, carrier_code, tracking_number, packed_at, shipped_at, delivered_at,
                created_by_type, created_by_id, created_at, updated_at)
            SELECT row_number() OVER (ORDER BY so.id), :pid(so.id),
                   so.reference || '-S1', so.id, 1,
                   CASE WHEN so.status IN ('delivered', 'completed', 'partially_refunded') THEN 'delivered' ELSE 'shipped' END,
                   (ARRAY['Perf Courier','Perf Freight','Perf Post'])[1 + (:h(so.id) % 3)],
                   (ARRAY['perf_courier','perf_freight','perf_post'])[1 + (:h(so.id) % 3)],
                   'PERFTRK' || lpad((:h(so.id) % 100000000)::text, 10, '0'),
                   so.packed_at, so.shipped_at, so.delivered_at,
                   'seller_user', NULL,
                   so.shipped_at, so.updated_at
            FROM seller_orders so
            WHERE so.status IN ('shipped', 'delivered', 'completed', 'partially_refunded')
            SQL, $scale);

        $this->run('shipment_items', <<<'SQL'
            INSERT INTO shipment_items (shipment_id, order_item_id, quantity, created_at)
            SELECT s.id, oi.id, oi.quantity, s.created_at
            FROM shipments s
            JOIN order_items oi ON oi.seller_order_id = s.seller_order_id
            WHERE oi.allocated_quantity > 0
            SQL, $scale);
    }

    /**
     * The ledger, and payouts drawn against it.
     *
     * Coherent with {@see GetSellerFinancialPosition}
     * rather than merely voluminous: earnings sit in the bucket their
     * clearing date implies, a settled payout is a negative `paid` entry
     * offsetting earnings that stay `available`, and only an open request
     * holds allocations. A dataset that got this wrong would still
     * produce query plans, but every finance screen measured against it
     * would be reading numbers the application would never have written.
     *
     * Payout chunks are deliberately whole: a request is drawn over
     * exactly eight cleared earnings, so the remainder stays withdrawable
     * and the sellers in this dataset are not all pinned at a zero
     * balance — which is the state the payout screens are least
     * interesting in.
     */
    private function seedFinance(PerformanceScale $scale): void
    {
        $this->step('Ledger, payouts and platform revenue');

        $this->run('seller_ledger_entries', <<<'SQL'
            WITH earning AS (
                SELECT oi.id AS order_item_id, so.id AS seller_order_id, so.seller_account_id,
                       oi.seller_earning_amount_minor AS amount,
                       so.earnings_clear_at,
                       so.status,
                       so.created_at,
                       CASE
                           WHEN so.status IN ('delivered', 'completed', 'partially_refunded')
                                AND so.earnings_clear_at <= now() THEN 'available'
                           WHEN so.status IN ('delivered', 'completed', 'partially_refunded') THEN 'clearing'
                           ELSE 'pending'
                       END AS entry_status
                FROM order_items oi
                JOIN seller_orders so ON so.id = oi.seller_order_id
                WHERE so.status NOT IN ('pending_payment', 'cancelled')
            ),
            running AS (
                SELECT e.*,
                       row_number() OVER (ORDER BY e.seller_account_id, e.order_item_id) AS id,
                       sum(e.amount) OVER (PARTITION BY e.seller_account_id ORDER BY e.order_item_id
                                           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS balance_after
                FROM earning e
            )
            INSERT INTO seller_ledger_entries (
                id, public_id, seller_account_id, type, status, currency, amount_minor,
                balance_after_minor, seller_order_id, order_item_id, available_at, source_key, created_at
            )
            SELECT r.id, :pid(r.id), r.seller_account_id, 'sale_earning', r.entry_status, 'USD',
                   r.amount, r.balance_after, r.seller_order_id, r.order_item_id,
                   r.earnings_clear_at,
                   'perf:earning:' || r.order_item_id,
                   r.created_at
            FROM running r
            SQL, $scale);

        /*
         * The earnings above were written with explicit ids; the payout
         * debits below let the sequence assign them. Without this the
         * sequence is still sitting at 1 and the first debit collides
         * with the first earning.
         */
        $this->resyncSequence('seller_ledger_entries');

        /*
         * The chunking table is kept rather than recomputed, because the
         * request, its allocations and its settlement entry must all be
         * drawn over exactly the same ledger rows. Recomputing the
         * window three times would work until one of the three queries
         * was edited.
         */
        $this->db->statement(<<<'SQL'
            CREATE TEMPORARY TABLE perf_payout_chunk AS
            WITH e AS (
                SELECT id, seller_account_id, amount_minor,
                       row_number() OVER (PARTITION BY seller_account_id ORDER BY id) AS rn
                FROM seller_ledger_entries
                WHERE status = 'available' AND type = 'sale_earning'
            ),
            grouped AS (
                SELECT seller_account_id, ((rn - 1) / 8) AS chunk_no, id AS entry_id, amount_minor
                FROM e
            ),
            complete AS (
                SELECT seller_account_id, chunk_no, sum(amount_minor) AS amount
                FROM grouped
                GROUP BY seller_account_id, chunk_no
                HAVING count(*) = 8 AND sum(amount_minor) > 0
            ),
            numbered AS (
                SELECT c.*, row_number() OVER (ORDER BY c.seller_account_id, c.chunk_no) AS request_id
                FROM complete c
            )
            SELECT g.entry_id, g.amount_minor, n.seller_account_id, n.chunk_no, n.amount, n.request_id
            FROM numbered n
            JOIN grouped g ON g.seller_account_id = n.seller_account_id AND g.chunk_no = n.chunk_no
            SQL);

        $this->db->statement('DELETE FROM perf_payout_chunk WHERE request_id > '.$scale->payoutRequests());
        $this->db->statement('CREATE INDEX perf_payout_chunk_request ON perf_payout_chunk (request_id)');
        $this->db->statement('ANALYZE perf_payout_chunk');

        /*
         * A seller may hold exactly one open request at a time — there
         * is a partial unique index saying so, and it is a correctness
         * constraint, not a performance one. So only a seller's newest
         * chunk is eligible to be open; everything older is settled or
         * rejected. The generator is shaped around the constraint rather
         * than the constraint being relaxed to suit the generator.
         */
        $this->run('payout_requests', <<<'SQL'
            WITH req AS (
                SELECT DISTINCT request_id, seller_account_id, chunk_no, amount FROM perf_payout_chunk
            ),
            decided AS (
                SELECT r.request_id, r.seller_account_id, r.amount,
                       CASE
                           WHEN row_number() OVER (PARTITION BY r.seller_account_id ORDER BY r.chunk_no DESC) = 1
                                AND :h(r.request_id) % 100 < 30 THEN 'requested'
                           WHEN row_number() OVER (PARTITION BY r.seller_account_id ORDER BY r.chunk_no DESC) = 1
                                AND :h(r.request_id) % 100 < 45 THEN 'under_review'
                           WHEN row_number() OVER (PARTITION BY r.seller_account_id ORDER BY r.chunk_no DESC) = 1
                                AND :h(r.request_id) % 100 < 55 THEN 'approved'
                           WHEN :h(r.request_id) % 100 < 92 THEN 'paid'
                           ELSE 'rejected'
                       END AS status,
                       :anchor - ((:h(r.request_id) % 200 + 2) || ' days')::interval AS requested_at,
                       :anchor - ((:h(r.request_id) % 200) || ' days')::interval AS acted_at
                FROM req r
            )
            INSERT INTO payout_requests (
                id, public_id, reference, seller_account_id, currency, amount_minor, status,
                requested_at, requested_by_user_id, payout_account_id, destination_label,
                destination_type, seller_name_snapshot, reviewed_at, approved_at, paid_at,
                decided_at, settlement_method, settlement_ref, created_at, updated_at
            )
            SELECT d.request_id, :pid(d.request_id),
                   'PO-' || lpad(d.request_id::text, 9, '0'),
                   d.seller_account_id, 'USD', d.amount, d.status,
                   d.requested_at,
                   :users + d.seller_account_id,
                   d.seller_account_id,
                   'Perf Bank ****' || lpad((:h(d.seller_account_id) % 10000)::text, 4, '0'),
                   'bank_account',
                   sa.legal_name,
                   CASE WHEN d.status <> 'requested' THEN d.acted_at END,
                   CASE WHEN d.status IN ('approved', 'paid') THEN d.acted_at END,
                   CASE WHEN d.status = 'paid' THEN d.acted_at END,
                   CASE WHEN d.status IN ('paid', 'rejected') THEN d.acted_at END,
                   CASE WHEN d.status = 'paid' THEN 'manual_bank_transfer' END,
                   CASE WHEN d.status = 'paid' THEN 'PERFSETTLE-' || d.request_id END,
                   d.requested_at,
                   :anchor
            FROM decided d
            JOIN seller_accounts sa ON sa.id = d.seller_account_id
            SQL, $scale);

        $this->run('payout_allocations', <<<'SQL'
            INSERT INTO payout_allocations (
                id, public_id, payout_request_id, seller_ledger_entry_id, seller_account_id,
                currency, amount_minor, status, created_at, settled_at, released_at
            )
            SELECT a.id, :pid(a.id),
                   a.request_id, a.entry_id, a.seller_account_id, 'USD', a.amount_minor,
                   CASE pr.status
                       WHEN 'paid' THEN 'settled'
                       WHEN 'rejected' THEN 'released'
                       ELSE 'held'
                   END,
                   pr.requested_at,
                   CASE WHEN pr.status = 'paid' THEN pr.paid_at END,
                   CASE WHEN pr.status = 'rejected' THEN pr.decided_at END
            FROM (
                SELECT c.*, row_number() OVER (ORDER BY c.request_id, c.entry_id) AS id
                FROM perf_payout_chunk c
            ) a
            JOIN payout_requests pr ON pr.id = a.request_id
            SQL, $scale);

        /*
         * The settlement debit. Negative, status `paid`, and folded into
         * available by the projection — which is why the earnings it was
         * drawn against are left alone rather than re-statused.
         */
        $this->run('seller_ledger_entries (payouts)', <<<'SQL'
            INSERT INTO seller_ledger_entries (
                public_id, seller_account_id, type, status, currency, amount_minor,
                balance_after_minor, payout_request_id, source_key, created_at
            )
            SELECT :pid(1000000 + pr.id), pr.seller_account_id, 'payout', 'paid', 'USD',
                   -pr.amount_minor, 0, pr.id, 'perf:payout:' || pr.id, pr.paid_at
            FROM payout_requests pr
            WHERE pr.status = 'paid'
            SQL, $scale);

        /*
         * The running balance, computed once over the finished ledger.
         *
         * `finance:reconcile-sellers` checks that the newest entry's
         * `balance_after_minor` equals the sum of every entry for that
         * seller, and the payout debits above are written after the
         * earnings they draw on — so the column can only be correct if
         * it is filled in when the ledger is complete rather than as
         * each statement runs.
         */
        $this->step('Running ledger balances');

        $this->db->statement(<<<'SQL'
            UPDATE seller_ledger_entries e
            SET balance_after_minor = r.running
            FROM (
                SELECT id,
                       sum(amount_minor) OVER (PARTITION BY seller_account_id, currency
                                               ORDER BY id ROWS UNBOUNDED PRECEDING) AS running
                FROM seller_ledger_entries
            ) r
            WHERE r.id = e.id AND r.running <> e.balance_after_minor
            SQL);

        $this->run('platform_revenue_entries', <<<'SQL'
            INSERT INTO platform_revenue_entries (
                public_id, marketplace_order_id, seller_order_id, order_item_id, seller_account_id,
                type, currency, amount_minor, rate_percent_snapshot, source_key, created_at
            )
            SELECT :pid(oi.id), so.marketplace_order_id, so.id, oi.id, so.seller_account_id,
                   'commission', 'USD', oi.commission_amount_minor, oi.commission_rate_snapshot,
                   'perf:commission:' || oi.id, so.created_at
            FROM order_items oi
            JOIN seller_orders so ON so.id = oi.seller_order_id
            WHERE so.status NOT IN ('pending_payment', 'cancelled')
            SQL, $scale);
    }

    /**
     * Reviews, wishlists, popularity and the interaction stream.
     *
     * `interaction_events` is the largest table in the dataset by an
     * order of magnitude, which is the point: it is append-only, it is
     * written on every page view, and it is the one table where an
     * unindexed `created_at` range scan stops being a rounding error.
     */
    private function seedEngagement(PerformanceScale $scale): void
    {
        $this->step('Reviews, wishlists and interaction events');

        $this->run('product_reviews', <<<'SQL'
            WITH bought AS (
                -- One row per customer and product: a shopper who bought
                -- the same kettle twice still gets one live review, which
                -- is what the partial unique index says.
                SELECT DISTINCT ON (mo.user_id, oi.product_id)
                       oi.id AS order_item_id, oi.product_id, so.id AS seller_order_id,
                       mo.user_id, so.delivered_at
                FROM order_items oi
                JOIN seller_orders so ON so.id = oi.seller_order_id
                JOIN marketplace_orders mo ON mo.id = so.marketplace_order_id
                WHERE so.status IN ('delivered', 'completed') AND mo.user_id IS NOT NULL
                ORDER BY mo.user_id, oi.product_id, oi.id
            ),
            candidate AS (
                SELECT b.*, row_number() OVER (ORDER BY b.order_item_id) AS rn FROM bought b
            )
            INSERT INTO product_reviews (
                id, public_id, product_id, user_id, order_item_id, seller_order_id, rating, title,
                body, status, verified_purchase, published_at, hidden_at, rejected_at,
                created_at, updated_at
            )
            SELECT c.rn, :pid(c.rn), c.product_id, c.user_id, c.order_item_id, c.seller_order_id,
                   CASE
                       WHEN :h(c.rn) % 100 < 52 THEN 5
                       WHEN :h(c.rn) % 100 < 76 THEN 4
                       WHEN :h(c.rn) % 100 < 88 THEN 3
                       WHEN :h(c.rn) % 100 < 95 THEN 2
                       ELSE 1
                   END,
                   (ARRAY['Exactly as described','Better than expected','Does the job','Not for me','Would buy again'])[1 + (:h2(c.rn) % 5)],
                   'A generated review body with enough words in it that full-text search and the moderation queue both have something to work with. Written for scale testing only.',
                   CASE
                       WHEN :h2(c.rn) % 100 < 90 THEN 'published'
                       WHEN :h2(c.rn) % 100 < 96 THEN 'hidden'
                       WHEN :h2(c.rn) % 100 < 99 THEN 'rejected'
                       ELSE 'withdrawn'
                   END,
                   true,
                   CASE WHEN :h2(c.rn) % 100 < 90 THEN c.delivered_at + '3 days'::interval END,
                   CASE WHEN :h2(c.rn) % 100 >= 90 AND :h2(c.rn) % 100 < 96 THEN c.delivered_at + '5 days'::interval END,
                   CASE WHEN :h2(c.rn) % 100 >= 96 AND :h2(c.rn) % 100 < 99 THEN c.delivered_at + '4 days'::interval END,
                   c.delivered_at + '3 days'::interval,
                   c.delivered_at + '3 days'::interval
            FROM candidate c
            WHERE c.rn <= :reviews AND :h(c.rn) % 100 < 35
            SQL, $scale);

        $this->run('product_rating_summaries', <<<'SQL'
            INSERT INTO product_rating_summaries (
                product_id, published_review_count, verified_review_count, rating_sum, rating_average,
                count_1, count_2, count_3, count_4, count_5, recomputed_at, created_at, updated_at
            )
            SELECT r.product_id,
                   count(*),
                   count(*) FILTER (WHERE r.verified_purchase),
                   sum(r.rating),
                   round(avg(r.rating)::numeric, 2),
                   count(*) FILTER (WHERE r.rating = 1),
                   count(*) FILTER (WHERE r.rating = 2),
                   count(*) FILTER (WHERE r.rating = 3),
                   count(*) FILTER (WHERE r.rating = 4),
                   count(*) FILTER (WHERE r.rating = 5),
                   :anchor, :anchor, :anchor
            FROM product_reviews r
            WHERE r.status = 'published'
            GROUP BY r.product_id
            SQL);

        $this->run('wishlist_items', <<<'SQL'
            INSERT INTO wishlist_items (public_id, user_id, product_id, created_at)
            SELECT :pid((n * 31) + 7), 1 + (:h(n) % :users),
                   CASE
                       WHEN :h2(n) % 100 < 40 THEN 1 + (:h2(n) % :contested)
                       ELSE 1 + (:h2(n) % :products)
                   END,
                   :anchor - ((:h(n) % 300) || ' days')::interval
            FROM generate_series(1, :wishlists) n
            ON CONFLICT DO NOTHING
            SQL, $scale, [], ['wishlists' => max(10, intdiv($scale->users * 3, 2))]);

        /*
         * The interaction stream: views, search impressions and clicks,
         * cart adds and purchases, weighted the way a real funnel is —
         * views dominate, purchases are rare — and dated across a
         * hundred and twenty days so a "last thirty days" projection has
         * something to exclude.
         */
        $this->run('interaction_events', <<<'SQL'
            INSERT INTO interaction_events (
                event_id, user_id, anonymous_session_id, event_type, product_id, offer_id,
                seller_account_id, search_query, result_position, context, value_minor, created_at
            )
            SELECT :pid(n),
                   CASE WHEN :h(n) % 10 < 4 THEN 1 + (:h(n) % :users) END,
                   CASE WHEN :h(n) % 10 >= 4 THEN 'perf-session-' || (:h2(n) % 40000) END,
                   CASE
                       WHEN :h(n) % 1000 < 620 THEN 'product_view'
                       WHEN :h(n) % 1000 < 800 THEN 'search_impression'
                       WHEN :h(n) % 1000 < 890 THEN 'search_click'
                       WHEN :h(n) % 1000 < 940 THEN 'store_view'
                       WHEN :h(n) % 1000 < 975 THEN 'cart_add'
                       WHEN :h(n) % 1000 < 992 THEN 'wishlist_add'
                       ELSE 'purchase'
                   END,
                   CASE
                       WHEN :h2(n) % 100 < 45 THEN 1 + (:h2(n) % :contested)
                       ELSE 1 + (:h2(n) % :products)
                   END,
                   NULL,
                   1 + (:h(n) % :sellers),
                   CASE WHEN :h(n) % 1000 >= 620 AND :h(n) % 1000 < 890
                        THEN (ARRAY['kettle','walnut chair','titanium lantern','merino','backpack','ceramic grinder','headlamp'])[1 + (:h2(n) % 7)] END,
                   CASE WHEN :h(n) % 1000 >= 620 AND :h(n) % 1000 < 890 THEN 1 + (:h2(n) % 24) END,
                   (ARRAY['search','category','store','product','recommendation'])[1 + (:h2(n) % 5)],
                   CASE WHEN :h(n) % 1000 >= 992 THEN 999 + (:h2(n) % 40000) END,
                   :anchor - ((:h(n) % 120) || ' days')::interval - ((:h2(n) % 86400) || ' seconds')::interval
            FROM generate_series(1, :events) n
            SQL, $scale);

        $this->run('product_popularity_scores', <<<'SQL'
            INSERT INTO product_popularity_scores (
                product_id, window_days, score, view_count, search_click_count, wishlist_count,
                cart_count, purchase_count, computed_at
            )
            SELECT e.product_id, w.window_days,
                   count(*) FILTER (WHERE e.event_type = 'product_view')
                     + (5 * count(*) FILTER (WHERE e.event_type = 'cart_add'))
                     + (25 * count(*) FILTER (WHERE e.event_type = 'purchase')),
                   count(*) FILTER (WHERE e.event_type = 'product_view'),
                   count(*) FILTER (WHERE e.event_type = 'search_click'),
                   count(*) FILTER (WHERE e.event_type = 'wishlist_add'),
                   count(*) FILTER (WHERE e.event_type = 'cart_add'),
                   count(*) FILTER (WHERE e.event_type = 'purchase'),
                   :anchor
            FROM (VALUES (7), (30)) AS w(window_days)
            JOIN interaction_events e
              ON e.created_at >= now() - (w.window_days || ' days')::interval
            WHERE e.product_id IS NOT NULL
            GROUP BY e.product_id, w.window_days
            SQL);

        /*
         * Associations derived from the dataset's own behaviour rather
         * than invented. "Bought together" is a real self-join over the
         * items of one marketplace order; "viewed together" a real
         * self-join over one anonymous session's product views. Inventing
         * the pairs would have been faster and would have produced a
         * uniform graph — every product with the same number of
         * neighbours — which is the one shape the recommendation queries
         * will never meet.
         */
        $this->run('product_associations', <<<'SQL'
            WITH pair AS (
                SELECT a.product_id, b.product_id AS associated_product_id, count(*) AS support
                FROM order_items a
                JOIN seller_orders sa ON sa.id = a.seller_order_id
                JOIN seller_orders sb ON sb.marketplace_order_id = sa.marketplace_order_id
                JOIN order_items b ON b.seller_order_id = sb.id
                WHERE a.product_id <> b.product_id AND a.product_id IS NOT NULL AND b.product_id IS NOT NULL
                GROUP BY 1, 2
            ),
            ranked AS (
                SELECT p.*, row_number() OVER (ORDER BY p.support DESC, p.product_id, p.associated_product_id) AS rn
                FROM pair p
            )
            INSERT INTO product_associations (product_id, associated_product_id, kind, support, score, computed_at)
            SELECT r.product_id, r.associated_product_id, 'bought_together', r.support, r.support * 100, :anchor
            FROM ranked r
            WHERE r.rn <= 60000
            SQL, $scale);

        $this->run('product_associations (viewed)', <<<'SQL'
            WITH viewed AS (
                SELECT DISTINCT anonymous_session_id, product_id
                FROM interaction_events
                WHERE event_type = 'product_view' AND anonymous_session_id IS NOT NULL AND product_id IS NOT NULL
            ),
            pair AS (
                SELECT a.product_id, b.product_id AS associated_product_id, count(*) AS support
                FROM viewed a
                JOIN viewed b ON b.anonymous_session_id = a.anonymous_session_id AND b.product_id <> a.product_id
                GROUP BY 1, 2
            ),
            ranked AS (
                SELECT p.*, row_number() OVER (ORDER BY p.support DESC, p.product_id, p.associated_product_id) AS rn
                FROM pair p
            )
            INSERT INTO product_associations (product_id, associated_product_id, kind, support, score, computed_at)
            SELECT r.product_id, r.associated_product_id, 'viewed_together', r.support, r.support * 10, :anchor
            FROM ranked r
            WHERE r.rn <= 60000
            ON CONFLICT DO NOTHING
            SQL, $scale);

        $this->run('daily_product_metrics', <<<'SQL'
            WITH busiest AS (
                SELECT product_id, row_number() OVER (ORDER BY count(*) DESC, product_id) AS rn
                FROM interaction_events
                WHERE product_id IS NOT NULL
                GROUP BY product_id
            )
            INSERT INTO daily_product_metrics (
                day, product_id, views, search_impressions, search_clicks, wishlist_adds,
                cart_adds, purchases, units_sold, gross_minor, computed_at
            )
            SELECT (:anchor - (d || ' days')::interval)::date, b.product_id,
                   :h((b.product_id * 200) + d) % 500,
                   :h((b.product_id * 200) + d) % 1200,
                   :h((b.product_id * 200) + d) % 120,
                   :h2((b.product_id * 200) + d) % 30,
                   :h2((b.product_id * 200) + d) % 60,
                   :h2((b.product_id * 200) + d) % 12,
                   :h2((b.product_id * 200) + d) % 25,
                   (:h((b.product_id * 200) + d) % 90000)::bigint,
                   :anchor
            FROM busiest b, generate_series(1, 30) d
            WHERE b.rn <= 2000
            SQL, $scale);

        $this->run('daily_seller_metrics', <<<'SQL'
            INSERT INTO daily_seller_metrics (
                day, seller_account_id, store_views, offer_impressions, offer_clicks, orders,
                units_sold, delivered_orders, refunded_orders, gross_minor, refunds_minor,
                earnings_minor, computed_at
            )
            SELECT (:anchor - (d || ' days')::interval)::date, s,
                   :h((s * 500) + d) % 400,
                   :h((s * 500) + d) % 3000,
                   :h((s * 500) + d) % 300,
                   :h2((s * 500) + d) % 40,
                   :h2((s * 500) + d) % 90,
                   :h2((s * 500) + d) % 35,
                   :h2((s * 500) + d) % 4,
                   (:h((s * 500) + d) % 400000)::bigint,
                   (:h2((s * 500) + d) % 20000)::bigint,
                   (:h((s * 500) + d) % 350000)::bigint,
                   :anchor
            FROM generate_series(1, :sellers) s, generate_series(1, 90) d
            SQL, $scale);
    }

    /**
     * Point every identity sequence past the ids that were just written.
     *
     * The dataset assigns ids explicitly so the arithmetic linking one
     * table to the next stays readable, which leaves every sequence
     * sitting at 1. Discovered from the catalogue rather than listed, so
     * a table added later cannot be forgotten and hand the first real
     * insert a duplicate key.
     */
    private function resetSequences(): void
    {
        $this->step('Resetting identity sequences');

        /*
         * Found through `pg_depend` rather than by calling
         * `pg_get_serial_sequence` in the predicate. The obvious version
         * of this query did the latter and PostgreSQL was free to
         * evaluate the function before the schema filter, which it did —
         * asking for the sequence of `public.pg_statistic` and failing.
         * The dependency graph answers the same question without running
         * a function over every row in the catalogue.
         */
        /** @var array<int, object{table_name: string, sequence_name: string}> $sequences */
        $sequences = $this->db->select(<<<'SQL'
            SELECT t.relname AS table_name, s.oid::regclass::text AS sequence_name
            FROM pg_class s
            JOIN pg_depend d ON d.objid = s.oid AND d.classid = 'pg_class'::regclass AND d.deptype IN ('a', 'i')
            JOIN pg_class t ON t.oid = d.refobjid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE s.relkind = 'S' AND n.nspname = 'public' AND a.attname = 'id'
            ORDER BY t.relname
            SQL);

        foreach ($sequences as $sequence) {
            $this->db->select(sprintf(
                'SELECT setval(?, GREATEST((SELECT COALESCE(max(id), 0) FROM %s), 1))',
                '"'.$sequence->table_name.'"',
            ), [$sequence->sequence_name]);
        }
    }

    /** Point one identity sequence past the rows already written. */
    private function resyncSequence(string $table): void
    {
        $this->db->select(sprintf(
            'SELECT setval(pg_get_serial_sequence(?, %s), GREATEST((SELECT COALESCE(max(id), 0) FROM %s), 1))',
            "'id'",
            '"'.$table.'"',
        ), ['public.'.$table]);
    }

    /**
     * Give the planner the statistics, and drop the scaffolding.
     *
     * Without this every plan captured afterwards is a plan for a
     * database PostgreSQL believes is empty, which is a different and
     * much more confident set of choices than the one production will
     * make. `ANALYZE` is not optional decoration on a generated dataset;
     * it is the step that makes the dataset mean anything.
     */
    private function analyse(): void
    {
        $this->step('Analysing');

        foreach (['perf_seller', 'perf_offer', 'perf_payout_chunk'] as $temporary) {
            $this->db->statement('DROP TABLE IF EXISTS '.$temporary);
        }

        $this->db->statement('ANALYZE');
    }

    /**
     * Run one generated statement and record how many rows it wrote.
     *
     * @param  array<int, string>  $bindings
     * @param  array<string, int>  $extra
     */
    private function run(string $label, string $sql, ?PerformanceScale $scale = null, array $bindings = [], array $extra = []): void
    {
        $this->step($label);

        $written = $this->db->affectingStatement($this->expand($sql, $scale, $extra), $bindings);

        $this->counts[$label] = ($this->counts[$label] ?? 0) + $written;
    }

    private function step(string $message): void
    {
        ($this->progress)($message);
    }

    /**
     * Replace the dataset's placeholders with literal SQL.
     *
     * Every substituted value is an integer computed here from the scale
     * profile — never anything that reached the process from outside —
     * so this is code generation rather than query building, and the
     * `int` casts are what make that claim checkable.
     *
     * @param  array<string, int>  $extra
     */
    private function expand(string $sql, ?PerformanceScale $scale, array $extra = []): string
    {
        $scalars = $extra;

        if ($scale instanceof PerformanceScale) {
            $leaves = max(1, $scale->categories() - $this->rootCount($scale) - $this->midCount($scale));

            $scalars += [
                'users' => $scale->users,
                'sellers' => $scale->sellers,
                'products' => $scale->products,
                'orders' => $scale->orders,
                'events' => $scale->events,
                'categories' => $scale->categories(),
                'brands' => $scale->brands(),
                'reviews' => $scale->reviews(),
                'roots' => $this->rootCount($scale),
                'mids' => $this->midCount($scale),
                'leafBase' => $this->rootCount($scale) + $this->midCount($scale),
                'leaves' => $leaves,
                'leafBig' => min(5, $leaves),
                'leafMid' => min(20, $leaves),
                'contested' => $scale->hotProducts(),
                'contestedPerSeller' => $scale->hotOffersPerSeller(),
                'largeSellers' => $scale->largeSellers(),
                'mediumSellers' => $scale->mediumSellers(),
                'largeOffers' => $scale->largeSellerOffers(),
                'mediumOffers' => $scale->mediumSellerOffers(),
                'smallOffers' => $scale->smallSellerOffers(),
                'coldStep' => $scale->coldStep(),
            ];
        }

        // Longest first, so `:sellerCount` is never mangled by `:sellers`.
        uksort($scalars, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($scalars as $name => $value) {
            $sql = str_replace(':'.$name, (string) (int) $value, $sql);
        }

        $sql = str_replace(':anchor', "date_trunc('day', now())", $sql);

        // Two independent Lehmer streams, so a row can vary one attribute
        // without dragging every other attribute along with it.
        $sql = (string) preg_replace($this->call('h2'), '(($1::bigint * 16807) % 2147483647)', $sql);
        $sql = (string) preg_replace($this->call('h'), '(($1::bigint * 48271) % 2147483647)', $sql);

        return (string) preg_replace(
            $this->call('pid'),
            "('".self::MARKER."' || lpad($1::text, 21, '0'))",
            $sql,
        );
    }

    /**
     * Match `:name(...)` including the parentheses of the argument.
     *
     * Recursive, because the arguments are ordinary SQL expressions and
     * the first version of this matched only argument text with no
     * parentheses of its own. `:h((o.n * 4) + j)` slipped through
     * untouched and PostgreSQL was handed a literal colon — a failure
     * that at least announced itself, which is more than a silently
     * mismatched substitution would have done.
     */
    private function call(string $name): string
    {
        return '/:'.$name.'(\((?:[^()]++|(?1))*\))/';
    }

    private function rootCount(PerformanceScale $scale): int
    {
        return max(2, intdiv($scale->categories(), 20));
    }

    private function midCount(PerformanceScale $scale): int
    {
        return max(2, intdiv($scale->categories(), 4));
    }
}

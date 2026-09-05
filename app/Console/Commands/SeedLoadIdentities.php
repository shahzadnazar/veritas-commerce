<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Support\Diagnostics\DestructiveDatabaseGuard;
use App\Support\Performance\PerformanceDataset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sign-in-able identities and request pools for the load suite.
 *
 * The performance dataset deliberately gives every generated user a
 * bcrypt hash of thirty-two bytes it then throws away, so nobody can log
 * in as one. That is right for a dataset whose whole purpose is to sit
 * in a database — and useless for load testing, which has to exercise
 * authenticated pages through the real login form, the real session
 * cookie and the real authorisation policies.
 *
 * So this sets a password on a *pool* of existing rows, and writes the
 * pool to a JSON file the k6 scripts read. Three properties matter:
 *
 * **A pool, not one identity.** Every virtual user sharing one session
 * would serialise on that session's Redis key and on whatever row locks
 * its requests take, and the resulting numbers would describe a
 * contention artefact rather than the application.
 *
 * **The password is generated per run** and written only to the pool
 * file, which is not committed. Nothing in the repository is a credential.
 *
 * **It refuses anywhere real.** Same guard as the seeder: not production,
 * not a protected database, and — because the dataset marker is the only
 * thing that makes these rows identifiable — not a database without one.
 */
final class SeedLoadIdentities extends Command
{
    /**
     * How many of an identity's own order references to pool.
     *
     * Enough that virtual users sharing an identity rarely ask for the
     * same row twice, few enough that the pool file stays small.
     */
    private const ORDERS_PER_IDENTITY = 20;

    protected $signature = 'veritas:seed-load-identities
        {--customers=40 : Customers with order history to sign in as}
        {--sellers=20 : Seller members to sign in as}
        {--admins=4 : Platform admins to sign in as}
        {--out=ops/load/.run/pool.json : Where to write the pool}';

    protected $description = 'Prepare sign-in-able identities and request pools for the k6 load suite';

    public function handle(): int
    {
        $guard = DestructiveDatabaseGuard::forCurrentRequest();
        $database = $guard->targetDatabase();

        if ($guard->isProductionEnvironment() || $guard->isProtected($database)) {
            $this->error("This writes passwords onto existing rows; it does not run against \"{$database}\".");

            return self::FAILURE;
        }

        /*
         * The marker is the safety interlock. A database without generated
         * rows in it is not the performance database, whatever it is
         * called, and this command would be setting a known password on
         * somebody's real account.
         */
        if (PerformanceDataset::contamination(DB::connection(), PerformanceDataset::SENTINEL_TABLES) === []) {
            $this->error(
                "Database \"{$database}\" holds no generated performance rows, so it is not the performance "
                .'database. Run veritas:seed-performance first.',
            );

            return self::FAILURE;
        }

        $password = Str::random(32);
        $hash = Hash::make($password);

        $customers = $this->withOwnOrders($this->customers((int) $this->option('customers'), $hash));
        $sellers = $this->withOwnSellerOrders($this->sellers((int) $this->option('sellers'), $hash));
        $admins = $this->admins((int) $this->option('admins'), $hash);

        $pool = [
            'generated_at' => now()->toIso8601String(),
            'database' => $database,
            'password' => $password,
            'customers' => $customers,
            'sellers' => $sellers,
            'admins' => $admins,
            'products' => $this->productSlugs(),
            'categories' => $this->categorySlugs(),
            'stores' => $this->storeSlugs(),
            'searches' => $this->searchTerms(),
            'admin_orders' => $this->adminOrders(),
            'payouts' => $this->payoutReferences(),
        ];

        $out = (string) $this->option('out');
        $path = str_starts_with($out, '/') ? $out : base_path($out);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, (string) json_encode($pool, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf(
            '%d customers, %d sellers, %d admins and %d product slugs written to %s.',
            count($customers),
            count($sellers),
            count($admins),
            count($pool['products']),
            $out,
        ));

        $this->warn('The pool file holds a working password. It is gitignored; do not publish it.');

        return self::SUCCESS;
    }

    /**
     * Customers who have actually bought something.
     *
     * An account page for a customer with no orders measures an empty
     * query. The pool is drawn from the users with the most history, so
     * the order list and its pagination have something to paginate.
     *
     * @return array<int, array{id: int, email: string}>
     */
    private function customers(int $count, string $hash): array
    {
        /** @var array<int, int> $ids */
        $ids = DB::table('marketplace_orders')
            ->whereNotNull('user_id')
            ->select('user_id')
            ->groupBy('user_id')
            ->orderByRaw('count(*) desc')
            ->limit($count)
            ->pluck('user_id')
            ->map(intval(...))
            ->all();

        User::query()->whereIn('id', $ids)->update(['password' => $hash]);

        return User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'email'])
            ->map(static fn (User $user): array => ['id' => (int) $user->id, 'email' => (string) $user->email])
            ->all();
    }

    /**
     * Seller members, one per seller account, biggest sellers first.
     *
     * @return array<int, array{id: int, email: string, seller_account_id: int}>
     */
    private function sellers(int $count, string $hash): array
    {
        /** @var array<int, object{user_id: int, seller_account_id: int}> $rows */
        $rows = DB::table('seller_memberships as m')
            ->join('seller_accounts as s', 's.id', '=', 'm.seller_account_id')
            ->where('s.status', 'approved')
            ->orderByRaw('(select count(*) from seller_orders o where o.seller_account_id = m.seller_account_id) desc')
            ->limit($count)
            ->get(['m.user_id', 'm.seller_account_id'])
            ->all();

        $ids = array_map(static fn (object $row): int => (int) $row->user_id, $rows);

        User::query()->whereIn('id', $ids)->update(['password' => $hash]);

        $emails = User::query()->whereIn('id', $ids)->pluck('email', 'id');

        return array_values(array_map(static fn (object $row): array => [
            'id' => (int) $row->user_id,
            'email' => (string) $emails[$row->user_id],
            'seller_account_id' => (int) $row->seller_account_id,
        ], $rows));
    }

    /**
     * Admins, created rather than borrowed.
     *
     * The dataset has no admin rows, and inventing them here keeps the
     * seeder's job — generating a marketplace — separate from this one's.
     * They carry the dataset marker so the contamination check finds them.
     *
     * @return array<int, array{id: int, email: string, totp_secret: string}>
     */
    private function admins(int $count, string $hash): array
    {
        $admins = [];

        foreach (range(1, max(1, $count)) as $index) {
            $email = "perf-admin-{$index}@veritas.invalid";

            /** @var AdminUser $admin */
            $admin = AdminUser::query()->firstOrNew(['email' => $email]);

            // forceFill because `public_id` is guarded: the marker has to
            // be the one the contamination check looks for, not a fresh
            // ULID, or these rows would be invisible to it.
            /*
             * Enrolled in two-factor for real, with a secret the load
             * script knows.
             *
             * The admin area requires MFA and the load suite does not get
             * to skip it: a benchmark that disabled the second factor
             * would be measuring an application that does not exist, and
             * the whole point of §68 is that load tooling must not need
             * security turned off. So the secret is generated here, the
             * enrolment is completed the way a person's would be, and k6
             * computes a genuine TOTP code at login.
             */
            $secret = $this->totpSecret($index);

            $admin->forceFill([
                'public_id' => PerformanceDataset::MARKER.str_pad((string) $index, 21, '0', STR_PAD_LEFT),
                'name' => "Perf Admin {$index}",
                'password' => $hash,
                'role' => AdminRole::SuperAdmin->value,
                'two_factor_secret' => $secret,
                'two_factor_enrolled_at' => now(),
                'two_factor_confirmed_at' => now(),
            ])->save();

            $admins[] = ['id' => (int) $admin->id, 'email' => $email, 'totp_secret' => $secret];
        }

        return $admins;
    }

    /**
     * A deterministic base32 secret, valid for the application's TOTP.
     *
     * Derived from the index rather than random so a re-run of this
     * command does not invalidate a pool file somebody is mid-run with.
     */
    private function totpSecret(int $index): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';

        for ($position = 0; $position < 32; $position++) {
            $secret .= $alphabet[(($index * 7) + ($position * 13)) % 32];
        }

        return $secret;
    }

    /**
     * Published products, spread across the catalogue rather than
     * clustered at the start of it.
     *
     * A pool taken with `limit 200` would be the two hundred lowest ids,
     * which share a page range and a cache neighbourhood. Sampling across
     * the whole table is what makes the buffer-cache behaviour under load
     * mean anything.
     *
     * @return array<int, string>
     */
    private function productSlugs(): array
    {
        return DB::table('product_search_documents')
            ->where('is_public', true)
            ->whereNotNull('slug')
            ->orderByRaw('(product_id * 48271) % 2147483647')
            ->limit(200)
            ->pluck('slug')
            ->map(strval(...))
            ->all();
    }

    /** @return array<int, string> */
    private function categorySlugs(): array
    {
        return DB::table('categories as c')
            ->where('c.is_visible', true)
            ->whereExists(static fn ($query) => $query->from('products as p')
                ->whereColumn('p.category_id', 'c.id')
                ->where('p.status', 'published'))
            ->orderByRaw('(c.id * 48271) % 2147483647')
            ->limit(40)
            ->pluck('c.slug')
            ->map(strval(...))
            ->all();
    }

    /** @return array<int, string> */
    private function storeSlugs(): array
    {
        return DB::table('stores')
            ->where('is_open', true)
            ->orderByRaw('(id * 48271) % 2147483647')
            ->limit(30)
            ->pluck('slug')
            ->map(strval(...))
            ->all();
    }

    /**
     * A query mix that covers the classes the plan audit separated.
     *
     * Terms are taken from the generated vocabulary so they match
     * something; the zero-result and misspelled entries are there because
     * a search benchmark that never misses is not a search benchmark.
     *
     * @return array<string, array<int, string>>
     */
    private function searchTerms(): array
    {
        /** @var array<int, string> $titles */
        $titles = DB::table('product_search_documents')
            ->where('is_public', true)
            ->orderByRaw('(product_id * 16807) % 2147483647')
            ->limit(60)
            ->pluck('title')
            ->map(strval(...))
            ->all();

        $selective = [];

        foreach ($titles as $title) {
            $parts = explode(' ', $title);
            // The model-number suffix: near-unique, and the shape of most
            // real long-tail search traffic.
            $selective[] = end($parts);
        }

        return [
            'selective' => array_values(array_unique($selective)),
            'broad' => ['kettle', 'chair', 'lantern', 'backpack', 'speaker', 'notebook'],
            'fuzzy' => ['kettel', 'lanturn', 'bakcpack', 'speeker', 'notbook', 'chiar'],
            'empty' => ['zzzqqxx', 'nothing matches this', 'qwertyuiop asdf'],
        ];
    }

    /**
     * Give every pooled customer the references of their own orders.
     *
     * A flat list of references shared by the whole pool would send most
     * virtual users at orders belonging to someone else, and tenant
     * isolation answers those with a 404 — a load test that would have
     * measured the error page and called it an order page.
     *
     * @param  array<int, array{id: int, email: string}>  $customers
     * @return array<int, array{id: int, email: string, orders: array<int, string>}>
     */
    private function withOwnOrders(array $customers): array
    {
        $ids = array_map(static fn (array $customer): int => $customer['id'], $customers);

        /** @var array<int, array<int, string>> $byCustomer */
        $byCustomer = DB::table('marketplace_orders')
            ->whereIn('user_id', $ids)
            ->orderByDesc('id')
            ->get(['user_id', 'reference'])
            ->groupBy('user_id')
            ->map(static fn ($rows): array => $rows
                ->pluck('reference')
                ->map(strval(...))
                ->take(self::ORDERS_PER_IDENTITY)
                ->values()
                ->all())
            ->all();

        return array_values(array_filter(array_map(
            static fn (array $customer): array => $customer + [
                'orders' => $byCustomer[$customer['id']] ?? [],
            ],
            $customers,
        ), static fn (array $customer): bool => $customer['orders'] !== []));
    }

    /**
     * The same for sellers, scoped to the seller account they belong to.
     *
     * @param  array<int, array{id: int, email: string, seller_account_id: int}>  $sellers
     * @return array<int, array{id: int, email: string, seller_account_id: int, orders: array<int, string>}>
     */
    private function withOwnSellerOrders(array $sellers): array
    {
        $ids = array_map(static fn (array $seller): int => $seller['seller_account_id'], $sellers);

        /** @var array<int, array<int, string>> $byAccount */
        $byAccount = DB::table('seller_orders')
            ->whereIn('seller_account_id', $ids)
            ->orderByDesc('id')
            ->get(['seller_account_id', 'reference'])
            ->groupBy('seller_account_id')
            ->map(static fn ($rows): array => $rows
                ->pluck('reference')
                ->map(strval(...))
                ->take(self::ORDERS_PER_IDENTITY)
                ->values()
                ->all())
            ->all();

        return array_values(array_filter(array_map(
            static fn (array $seller): array => $seller + [
                'orders' => $byAccount[$seller['seller_account_id']] ?? [],
            ],
            $sellers,
        ), static fn (array $seller): bool => $seller['orders'] !== []));
    }

    /** @return array<int, string> */
    private function adminOrders(): array
    {
        return DB::table('marketplace_orders')
            ->orderByRaw('(id * 16807) % 2147483647')
            ->limit(100)
            ->pluck('reference')
            ->map(strval(...))
            ->all();
    }

    /** @return array<int, string> */
    private function payoutReferences(): array
    {
        return DB::table('payout_requests')
            ->orderByDesc('id')
            ->limit(60)
            ->pluck('reference')
            ->map(strval(...))
            ->all();
    }
}

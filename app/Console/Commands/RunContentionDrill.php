<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Offers\Models\Offer;
use App\Support\Performance\PerformanceDataset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sets up and then checks the write-path contention drill.
 *
 * `--prepare` puts a known, small quantity of one offer on the shelf and
 * writes down what the books said beforehand. A k6 script then sends far
 * more simultaneous checkouts at that offer than there is stock for, each
 * from a different customer so the checkout rate limit is not what is
 * being measured. `--verify` reads the books back.
 *
 * The question is not whether the database can be made to hold a negative
 * balance — a CHECK constraint already forbids that, and a drill that
 * only proved the constraint exists would be proving the wrong thing. It
 * is whether the application loses or invents units when the requests
 * collide: whether reservations equal the orders that hold them, whether
 * the movement ledger still adds up to the balance, and whether losing
 * the race is a clean refusal rather than a five-hundred.
 */
final class RunContentionDrill extends Command
{
    protected $signature = 'veritas:contention-drill
        {--prepare : Put a known quantity on the shelf and record the opening books}
        {--verify : Read the books back and report what the burst did}
        {--units=5 : How many units to leave available}
        {--state=ops/load/.run/contention.json : Where the drill state lives}';

    protected $description = 'Prepare and verify the inventory contention drill.';

    public function handle(AdjustInventory $adjust): int
    {
        if (! $this->guard()) {
            return self::FAILURE;
        }

        return match (true) {
            (bool) $this->option('prepare') => $this->prepare($adjust),
            (bool) $this->option('verify') => $this->verify(),
            default => $this->fail('Pass --prepare or --verify.'),
        };
    }

    /**
     * The same refusal the other performance commands make.
     *
     * Setting a seller's stock to five units is a destructive act
     * anywhere it is not generated data.
     */
    private function guard(): bool
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (app()->environment('production')) {
            $this->error('This drill does not run in production.');

            return false;
        }

        // The same interlock the other performance commands use: a
        // database with no generated rows in it is not the performance
        // database, whatever it is called, and this would be setting a
        // real seller's stock to five units.
        if (PerformanceDataset::contamination(DB::connection(), PerformanceDataset::SENTINEL_TABLES) === []) {
            $this->error(
                "Database \"{$database}\" holds no generated performance rows, so it is not the performance "
                .'database. Run veritas:seed-performance first.',
            );

            return false;
        }

        return true;
    }

    private function prepare(AdjustInventory $adjust): int
    {
        $units = max(1, (int) $this->option('units'));

        /*
         * An offer nobody else in the load pools is touching, with a
         * seller order history — a product page that renders and a
         * balance row that already exists.
         */
        $offerId = DB::table('inventory_balances as b')
            ->join('offers as o', 'o.id', '=', 'b.offer_id')
            ->where('o.status', 'published')
            ->where('b.on_hand', '>', $units)
            ->orderByRaw('(b.offer_id * 16807) % 2147483647')
            ->value('b.offer_id');

        if ($offerId === null) {
            $this->error('No active offer with stock to run the drill against.');

            return self::FAILURE;
        }

        $offer = Offer::query()->where('id', $offerId)->firstOrFail();
        $balance = $this->balance((int) $offerId);

        // Through the real action, so the movement ledger stays a
        // truthful account of how the shelf got to five.
        $target = $units + $balance['reserved'];
        $change = $target - $balance['on_hand'];

        if ($change !== 0) {
            $adjust(
                offer: $offer,
                change: $change,
                reason: InventoryMovementReason::CountCorrection,
                actorType: 'system',
                actorId: 0,
                note: 'Contention drill setup.',
            );
        }

        /*
         * Baskets left behind by an earlier run would enter this one with
         * units already in them, so a burst of forty single-unit adds
         * would not be forty single-unit orders. Clearing them keeps the
         * arithmetic in the verdict honest.
         */
        DB::table('cart_items')->where('offer_id', $offerId)->delete();

        $balance = $this->balance((int) $offerId);

        $state = [
            'offer_id' => (int) $offerId,
            'offer_public_id' => (string) $offer->public_id,
            'units' => $balance['available'],
            'before' => $balance + [
                'movement_sum' => $this->movementSum((int) $offerId),
                'movements' => $this->movementCount((int) $offerId),
                'order_lines' => $this->orderLineCount((int) $offerId),
                /*
                 * Recorded so the check below compares what the burst did
                 * rather than what the generated dataset already looked
                 * like: the generator writes balances directly, so some
                 * offers open with reserved units no order accounts for.
                 */
                'reserved_by_orders' => $this->reservedByOrders((int) $offerId),
                'orders' => $this->orderCount((int) $offerId),
            ],
        ];

        $this->write($state);

        $this->info(sprintf(
            'Offer %s is on %d available (%d on hand, %d reserved). Send the burst, then --verify.',
            $offer->public_id,
            $balance['available'],
            $balance['on_hand'],
            $balance['reserved'],
        ));

        return self::SUCCESS;
    }

    private function verify(): int
    {
        $state = $this->read();
        $offerId = (int) $state['offer_id'];
        $before = $state['before'];

        $balance = $this->balance($offerId);
        $sold = $this->orderLineCount($offerId) - (int) $before['order_lines'];

        $findings = [];

        // The constraint guarantees these; asserting them anyway is how a
        // drill notices the constraint was dropped.
        if ($balance['available'] < 0) {
            $findings[] = "available is {$balance['available']}";
        }

        if ($balance['reserved'] > $balance['on_hand']) {
            $findings[] = "reserved {$balance['reserved']} exceeds on hand {$balance['on_hand']}";
        }

        // The real question: no more units left the shelf than were on
        // it, however many checkouts arrived at once.
        if ($sold > (int) $state['units']) {
            $findings[] = sprintf('%d units sold from a shelf holding %d', $sold, $state['units']);
        }

        // Double entry: the movement ledger is the account of the
        // balance, so it has to add up to it.
        $sum = $this->movementSum($offerId);

        if ($sum !== $balance['on_hand']) {
            $findings[] = sprintf('movements sum to %d but on hand is %d', $sum, $balance['on_hand']);
        }

        /*
         * Every unit the burst reserved has to be held by an order line
         * that wants it. A reservation with nothing behind it is stock
         * nobody can buy and nothing will ever release, because release
         * is driven from the order. Compared as a delta, because the
         * generated dataset opens with reservations of its own.
         */
        $reservedByBurst = $balance['reserved'] - (int) $before['reserved'];
        $heldByBurst = $this->reservedByOrders($offerId) - (int) $before['reserved_by_orders'];

        if ($reservedByBurst !== $heldByBurst) {
            $findings[] = sprintf(
                'the burst reserved %d units but its orders account for %d — %d leaked',
                $reservedByBurst,
                $heldByBurst,
                $reservedByBurst - $heldByBurst,
            );
        }

        $this->table(['', 'before', 'after'], [
            ['on hand', $before['on_hand'], $balance['on_hand']],
            ['reserved', $before['reserved'], $balance['reserved']],
            ['available', $before['available'], $balance['available']],
            ['reserved by orders', $before['reserved_by_orders'], $this->reservedByOrders($offerId)],
            ['movements', $before['movements'], $this->movementCount($offerId)],
            ['order lines', $before['order_lines'], $this->orderLineCount($offerId)],
            ['orders', $before['orders'], $this->orderCount($offerId)],
        ]);

        $this->line(sprintf(
            'Units that left the shelf: %d, from an opening %d, across %d new orders.',
            $sold,
            $state['units'],
            $this->orderCount($offerId) - (int) $before['orders'],
        ));

        if ($findings !== []) {
            foreach ($findings as $finding) {
                $this->error('  '.$finding);
            }

            return self::FAILURE;
        }

        $this->info('The books balance: no unit was sold twice, invented or lost.');

        return self::SUCCESS;
    }

    /** @return array{on_hand: int, reserved: int, available: int} */
    private function balance(int $offerId): array
    {
        $row = DB::table('inventory_balances')->where('offer_id', $offerId)->first();

        if ($row === null) {
            throw new RuntimeException("Offer {$offerId} has no inventory balance.");
        }

        return [
            'on_hand' => (int) $row->on_hand,
            'reserved' => (int) $row->reserved,
            'available' => (int) $row->available,
        ];
    }

    private function movementSum(int $offerId): int
    {
        return (int) DB::table('inventory_movements')->where('offer_id', $offerId)->sum('on_hand_change');
    }

    private function movementCount(int $offerId): int
    {
        return (int) DB::table('inventory_movements')->where('offer_id', $offerId)->count();
    }

    /** Units on order lines that still hold a reservation. */
    private function reservedByOrders(int $offerId): int
    {
        /*
         * A reservation is held while an order is placed but not yet
         * committed to stock. Once it is paid the units leave on_hand
         * instead, so only the pre-payment states hold reserved units.
         */
        return (int) DB::table('order_items as i')
            ->join('seller_orders as s', 's.id', '=', 'i.seller_order_id')
            ->where('i.offer_id', $offerId)
            ->where('s.status', 'pending_payment')
            ->sum('i.quantity');
    }

    /** Orders holding at least one line for this offer. */
    private function orderCount(int $offerId): int
    {
        return (int) DB::table('order_items')->where('offer_id', $offerId)->distinct()->count('seller_order_id');
    }

    private function orderLineCount(int $offerId): int
    {
        return (int) DB::table('order_items')->where('offer_id', $offerId)->sum('quantity');
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        $path = $this->path();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, (string) json_encode($state, JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            throw new RuntimeException("No drill state at {$path}. Run --prepare first.");
        }

        /** @var array<string, mixed> $state */
        $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $state;
    }

    private function path(): string
    {
        $out = (string) $this->option('state');

        return str_starts_with($out, '/') ? $out : base_path($out);
    }
}

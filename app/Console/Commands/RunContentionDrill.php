<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Offers\Models\Offer;
use App\Modules\Payments\Adapters\FakePaymentProvider;
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
        {--prepare-webhook : Sign one payment event and record the opening financial position}
        {--event-type=payment_intent.succeeded : The provider event type to sign}
        {--verify-webhook : Check that delivering it many times over paid the order once}
        {--prepare-shipment : Leave one unfulfilled unit on a seller order and record the opening state}
        {--verify-shipment : Check that a burst of allocations shipped it exactly once}
        {--prepare-payout : Put a known withdrawable balance on one seller}
        {--verify-payout : Check that a burst of payout requests reserved it once}
        {--prepare-settlement : Approve one payout ready to settle}
        {--verify-settlement : Check that a burst of settlements debited it once}
        {--prepare-clearing : Make a batch of ledger entries genuinely due for release}
        {--verify-clearing : Check that overlapping sweeps released each entry once}
        {--entries=400 : How many entries to make due}
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
            (bool) $this->option('prepare-webhook') => $this->prepareWebhook(),
            (bool) $this->option('verify-webhook') => $this->verifyWebhook(),
            (bool) $this->option('prepare-shipment') => $this->prepareShipment(),
            (bool) $this->option('verify-shipment') => $this->verifyShipment(),
            (bool) $this->option('prepare-payout') => $this->preparePayout(),
            (bool) $this->option('verify-payout') => $this->verifyPayout(),
            (bool) $this->option('prepare-settlement') => $this->prepareSettlement(),
            (bool) $this->option('verify-settlement') => $this->verifySettlement(),
            (bool) $this->option('prepare-clearing') => $this->prepareClearing(),
            (bool) $this->option('verify-clearing') => $this->verifyClearing(),
            default => $this->fail('Pass --prepare, --verify, --prepare-webhook or --verify-webhook.'),
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

    /**
     * Sign one `payment_intent.succeeded` and write down the books.
     *
     * The event is signed with the configured provider's own secret, so
     * the burst goes through real signature verification. Nothing here
     * marks anything paid: the drill posts the event and the application
     * decides, which is the whole point of the exercise.
     */
    private function prepareWebhook(): int
    {
        if (config('veritas.payments.provider') !== 'fake') {
            $this->error('This drill signs its own events, so it needs the fake provider. Set PAYMENT_GATEWAY=fake.');

            return self::FAILURE;
        }

        // The container's own instance, so the secret this signs with is
        // the secret the endpoint verifies against.
        $provider = app(FakePaymentProvider::class);

        $attempt = DB::table('payment_attempts as a')
            ->join('marketplace_orders as o', 'o.id', '=', 'a.marketplace_order_id')
            ->where('o.status', 'pending_payment')
            ->whereNotNull('a.provider_reference')
            ->orderByDesc('a.id')
            ->first([
                'a.id', 'a.provider_reference', 'a.marketplace_order_id',
                'a.amount_minor', 'a.currency', 'o.reference',
            ]);

        if ($attempt === null) {
            $this->error('No pending payment attempt to replay. Run the checkout burst first.');

            return self::FAILURE;
        }

        $orderId = (int) $attempt->marketplace_order_id;

        /*
         * The object is built here rather than asked of the provider: the
         * fake one keeps its payments in memory, and the attempt being
         * replayed was created by the web process, not this one. These are
         * the fields the adapter reads back out of the payload.
         */
        /*
         * The type is a parameter because the two duplicate-storm cases
         * behave differently and both need measuring. A payment event
         * re-reads the provider and, against the in-memory fake, fails —
         * which is the pathological case where every duplicate carries its
         * own retry ladder. An event type the platform does not handle
         * reaches a terminal `ignored` on the first pass, which is the
         * shape of a real provider retrying something already dealt with.
         */
        $signed = $provider->signedEvent((string) $this->option('event-type'), [
            'id' => (string) $attempt->provider_reference,
            'status' => 'succeeded',
            'amount' => (int) $attempt->amount_minor,
            'amount_received' => (int) $attempt->amount_minor,
            'currency' => strtolower((string) $attempt->currency),
            'metadata' => ['order_reference' => (string) $attempt->reference],
        ]);

        $this->write([
            'event_id' => (string) $signed['event']['id'],
            'payload' => $signed['payload'],
            'signature' => $signed['signature'],
            'order_id' => $orderId,
            'amount_minor' => (int) $attempt->amount_minor,
            'before' => $this->financialPosition($orderId),
        ], $this->webhookPath());

        $this->info(sprintf(
            'Event %s is signed for order %d (%d minor units). Send the burst, then --verify-webhook.',
            $signed['event']['id'],
            $orderId,
            $attempt->amount_minor,
        ));

        return self::SUCCESS;
    }

    private function verifyWebhook(): int
    {
        $state = $this->read($this->webhookPath());
        $orderId = (int) $state['order_id'];
        $before = $state['before'];
        $after = $this->financialPosition($orderId);

        $findings = [];

        // One delivery or fifty, the customer paid once.
        if ($after['payments'] > 1) {
            $findings[] = sprintf('%d payment rows for one order', $after['payments']);
        }

        if ($after['paid_minor'] > (int) $state['amount_minor']) {
            $findings[] = sprintf(
                'the order is recorded as paid %d minor units against an authorised %d',
                $after['paid_minor'],
                $state['amount_minor'],
            );
        }

        // The event is stored once whatever the delivery count, which is
        // what makes the retry safe rather than merely tolerated.
        if ($after['events'] - (int) $before['events'] > 1) {
            $findings[] = sprintf('the same event was stored %d times', $after['events'] - (int) $before['events']);
        }

        // Sellers are credited from the payment, so a payment counted
        // twice would show up here as money the platform does not have.
        if ($after['ledger_minor'] > (int) $state['amount_minor']) {
            $findings[] = sprintf(
                'sellers were credited %d minor units from a %d payment',
                $after['ledger_minor'],
                $state['amount_minor'],
            );
        }

        $this->table(['', 'before', 'after'], [
            ['order status', $before['status'], $after['status']],
            ['payment rows', $before['payments'], $after['payments']],
            ['paid (minor)', $before['paid_minor'], $after['paid_minor']],
            ['stored events', $before['events'], $after['events']],
            ['seller ledger (minor)', $before['ledger_minor'], $after['ledger_minor']],
        ]);

        if ($findings !== []) {
            foreach ($findings as $finding) {
                $this->error('  '.$finding);
            }

            return self::FAILURE;
        }

        $this->info('One payment, credited once, from however many deliveries arrived at once.');

        return self::SUCCESS;
    }

    /**
     * @return array{status: string, payments: int, paid_minor: int, events: int, ledger_minor: int}
     */
    private function financialPosition(int $orderId): array
    {
        $sellerOrderIds = DB::table('seller_orders')->where('marketplace_order_id', $orderId)->pluck('id');

        return [
            'status' => (string) DB::table('marketplace_orders')->where('id', $orderId)->value('status'),
            'payments' => (int) DB::table('payments')->where('marketplace_order_id', $orderId)->count(),
            'paid_minor' => (int) DB::table('payments')
                ->where('marketplace_order_id', $orderId)
                ->where('status', 'succeeded')
                ->sum('amount_minor'),
            'events' => (int) DB::table('provider_webhook_events')->count(),
            'ledger_minor' => (int) DB::table('seller_ledger_entries')
                ->whereIn('seller_order_id', $sellerOrderIds)
                ->sum('amount_minor'),
        ];
    }

    /**
     * One seller order with exactly one unfulfilled unit left on it.
     *
     * The interesting number is not how fast a shipment is created; it is
     * whether twenty sellers' tabs clicking "ship" at once can allocate
     * the same unit twice.
     */
    private function prepareShipment(): int
    {
        $pooled = $this->pooledSellerEmails();

        if ($pooled === []) {
            $this->error('No identity pool. Run veritas:seed-load-identities first.');

            return self::FAILURE;
        }

        $row = DB::table('order_items as i')
            ->join('seller_orders as s', 's.id', '=', 'i.seller_order_id')
            ->join('seller_memberships as m', 'm.seller_account_id', '=', 's.seller_account_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            /*
             * Only sellers the generator can sign in as. A seller outside
             * the pool has no known password, so every attempt would fail
             * at the login form and be counted as a refusal — twenty
             * failed logins looking exactly like twenty correct refusals.
             */
            ->whereIn('u.email', $pooled)
            /*
             * `processing` and not merely `paid`: the domain refuses to
             * build a shipment for an order the seller has not confirmed,
             * so a paid-but-unconfirmed order would have every attempt
             * refused for the wrong reason and prove nothing.
             */
            ->where('s.status', 'processing')
            ->where('i.quantity', '=', 1)
            ->whereRaw('not exists (select 1 from shipment_items si where si.order_item_id = i.id)')
            ->orderByRaw('(i.id * 48271) % 2147483647')
            ->first([
                'i.id as order_item_id', 'i.quantity', 's.id as seller_order_id',
                's.reference', 's.seller_account_id', 'u.email',
            ]);

        if ($row === null) {
            $this->error('No unfulfilled paid seller order line to run the drill against.');

            return self::FAILURE;
        }

        /*
         * Everything but the last unit is allocated up front, through a
         * shipment the domain made, so the burst competes for a remainder
         * of exactly one rather than for a whole line.
         */
        $email = (string) $row->email;

        $this->write([
            'seller_order_id' => (int) $row->seller_order_id,
            'reference' => (string) $row->reference,
            'order_item_id' => (int) $row->order_item_id,
            'seller_email' => $email,
            'remaining' => 1,
            'quantity' => (int) $row->quantity,
            'before' => $this->shipmentPosition((int) $row->seller_order_id, (int) $row->order_item_id),
        ], $this->scenarioPath('shipment'));

        $this->info(sprintf(
            'Seller order %s item %d has %d unit(s) unallocated; seller is %s.',
            $row->reference,
            $row->order_item_id,
            $row->quantity,
            $email,
        ));

        return self::SUCCESS;
    }

    private function verifyShipment(): int
    {
        $state = $this->read($this->scenarioPath('shipment'));
        $before = $state['before'];
        $after = $this->shipmentPosition((int) $state['seller_order_id'], (int) $state['order_item_id']);

        $findings = [];

        // The invariant: allocated units can never exceed ordered units.
        if ($after['allocated'] > (int) $state['quantity']) {
            $findings[] = sprintf(
                'overship — %d units allocated against %d ordered',
                $after['allocated'],
                $state['quantity'],
            );
        }

        $this->table(['', 'before', 'after'], [
            ['shipments on the order', $before['shipments'], $after['shipments']],
            ['units allocated', $before['allocated'], $after['allocated']],
            ['units ordered', $state['quantity'], $state['quantity']],
        ]);

        if ($findings !== []) {
            foreach ($findings as $finding) {
                $this->error('  '.$finding);
            }

            return self::FAILURE;
        }

        $this->info('No unit was allocated to two shipments.');

        return self::SUCCESS;
    }

    /** @return array{shipments: int, allocated: int} */
    private function shipmentPosition(int $sellerOrderId, int $orderItemId): array
    {
        return [
            'shipments' => (int) DB::table('shipments')->where('seller_order_id', $sellerOrderId)->count(),
            'allocated' => (int) DB::table('shipment_items')->where('order_item_id', $orderItemId)->sum('quantity'),
        ];
    }

    /**
     * One seller, one known withdrawable balance, no request outstanding.
     */
    private function preparePayout(): int
    {
        $pooled = $this->pooledSellerEmails();

        $sellerId = DB::table('seller_ledger_entries as l')
            ->join('seller_memberships as m', 'm.seller_account_id', '=', 'l.seller_account_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('u.email', $pooled)
            ->select('l.seller_account_id')
            ->groupBy('l.seller_account_id')
            ->havingRaw('sum(l.amount_minor) > 0')
            ->orderByRaw('sum(l.amount_minor) desc')
            ->value('l.seller_account_id');

        if ($sellerId === null) {
            $this->error('No seller with a positive ledger balance.');

            return self::FAILURE;
        }

        $sellerId = (int) $sellerId;

        $member = DB::table('seller_memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.seller_account_id', $sellerId)
            ->whereIn('u.email', $this->pooledSellerEmails())
            ->first(['u.email']);

        if ($member === null) {
            $this->error("Seller {$sellerId} has no member to sign in as.");

            return self::FAILURE;
        }

        $this->write([
            'seller_account_id' => $sellerId,
            'seller_email' => (string) $member->email,
            'before' => $this->payoutPosition($sellerId),
        ], $this->scenarioPath('payout'));

        $position = $this->payoutPosition($sellerId);

        $this->info(sprintf(
            'Seller %d holds %d minor units across %d ledger entries, with %d open request(s). Member: %s.',
            $sellerId,
            $position['ledger_minor'],
            $position['entries'],
            $position['open_requests'],
            $member->email,
        ));

        return self::SUCCESS;
    }

    private function verifyPayout(): int
    {
        $state = $this->read($this->scenarioPath('payout'));
        $sellerId = (int) $state['seller_account_id'];
        $before = $state['before'];
        $after = $this->payoutPosition($sellerId);

        $findings = [];

        // M7 policy: one open request at a time, so a burst may add one.
        if ($after['open_requests'] > 1) {
            $findings[] = sprintf('%d payout requests are open at once', $after['open_requests']);
        }

        $requested = $after['requested_minor'] - (int) $before['requested_minor'];

        // Nothing may be reserved that the ledger does not hold.
        if ($requested > (int) $before['ledger_minor']) {
            $findings[] = sprintf(
                'the burst reserved %d minor units against a balance of %d',
                $requested,
                $before['ledger_minor'],
            );
        }

        $this->table(['', 'before', 'after'], [
            ['ledger (minor)', $before['ledger_minor'], $after['ledger_minor']],
            ['open requests', $before['open_requests'], $after['open_requests']],
            ['requested (minor)', $before['requested_minor'], $after['requested_minor']],
        ]);

        if ($findings !== []) {
            foreach ($findings as $finding) {
                $this->error('  '.$finding);
            }

            return self::FAILURE;
        }

        $this->info('One open request, reserving no more than the balance held.');

        return self::SUCCESS;
    }

    /** @return array{ledger_minor: int, entries: int, open_requests: int, requested_minor: int} */
    private function payoutPosition(int $sellerId): array
    {
        $open = DB::table('payout_requests')
            ->where('seller_account_id', $sellerId)
            ->whereIn('status', ['requested', 'in_review', 'approved', 'processing']);

        return [
            'ledger_minor' => (int) DB::table('seller_ledger_entries')->where('seller_account_id', $sellerId)->sum('amount_minor'),
            'entries' => (int) DB::table('seller_ledger_entries')->where('seller_account_id', $sellerId)->count(),
            'open_requests' => (int) (clone $open)->count(),
            'requested_minor' => (int) (clone $open)->sum('amount_minor'),
        ];
    }

    /**
     * One approved payout, sitting where a settlement can be applied.
     */
    private function prepareSettlement(): int
    {
        $payout = DB::table('payout_requests')
            ->whereIn('status', ['approved', 'processing'])
            ->orderByDesc('id')
            ->first(['id', 'reference', 'seller_account_id', 'amount_minor', 'status']);

        if ($payout === null) {
            $this->error('No approved payout to settle. Approve one first.');

            return self::FAILURE;
        }

        $this->write([
            'payout_id' => (int) $payout->id,
            'reference' => (string) $payout->reference,
            'seller_account_id' => (int) $payout->seller_account_id,
            'amount_minor' => (int) $payout->amount_minor,
            'before' => $this->settlementPosition((int) $payout->id),
        ], $this->scenarioPath('settlement'));

        $this->info(sprintf(
            'Payout %s (%d minor units, %s) is ready to settle.',
            $payout->reference,
            $payout->amount_minor,
            $payout->status,
        ));

        return self::SUCCESS;
    }

    private function verifySettlement(): int
    {
        $state = $this->read($this->scenarioPath('settlement'));
        $payoutId = (int) $state['payout_id'];
        $before = $state['before'];
        $after = $this->settlementPosition($payoutId);

        $findings = [];

        // The debit is the money leaving. Exactly one, ever.
        if ($after['debits'] > 1) {
            $findings[] = sprintf('%d payout debits for one payout', $after['debits']);
        }

        if ($after['debit_minor'] < -(int) $state['amount_minor']) {
            $findings[] = sprintf(
                'debited %d minor units for a payout of %d',
                $after['debit_minor'],
                $state['amount_minor'],
            );
        }

        $this->table(['', 'before', 'after'], [
            ['status', $before['status'], $after['status']],
            ['payout debits', $before['debits'], $after['debits']],
            ['debited (minor)', $before['debit_minor'], $after['debit_minor']],
            ['paid_at set', $before['paid'] ? 'yes' : 'no', $after['paid'] ? 'yes' : 'no'],
        ]);

        if ($findings !== []) {
            foreach ($findings as $finding) {
                $this->error('  '.$finding);
            }

            return self::FAILURE;
        }

        $this->info('One debit, one transition to paid, however many settlements arrived.');

        return self::SUCCESS;
    }

    /** @return array{status: string, debits: int, debit_minor: int, paid: bool} */
    private function settlementPosition(int $payoutId): array
    {
        $debits = DB::table('seller_ledger_entries')->where('payout_request_id', $payoutId);

        return [
            'status' => (string) DB::table('payout_requests')->where('id', $payoutId)->value('status'),
            'debits' => (int) (clone $debits)->count(),
            'debit_minor' => (int) (clone $debits)->sum('amount_minor'),
            'paid' => DB::table('payout_requests')->where('id', $payoutId)->whereNotNull('paid_at')->exists(),
        ];
    }

    /**
     * The seller identities the load generator can actually sign in as.
     *
     * @return array<int, string>
     */
    private function pooledSellerEmails(): array
    {
        $path = dirname($this->path()).'/pool.json';

        if (! is_file($path)) {
            return [];
        }

        /** @var array{sellers?: array<int, array{email?: string}>} $pool */
        $pool = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter(array_map(
            static fn (array $seller): string => (string) ($seller['email'] ?? ''),
            $pool['sellers'] ?? [],
        )));
    }

    /**
     * A batch of ledger entries genuinely due for release.
     *
     * The generated dataset carries entries that are `pending` or already
     * `available`; the sweep only looks at `clearing` rows whose clearing
     * period has elapsed. Moving a controlled batch into that state is
     * what gives overlapping sweeps something real to race over — the
     * alternative is several processes agreeing there is nothing to do.
     */
    private function prepareClearing(): int
    {
        $count = max(1, (int) $this->option('entries'));

        /*
         * The sweep is driven by seller orders, not by ledger entries: it
         * selects delivered orders whose clearing date has passed and
         * releases what those orders hold. Marking arbitrary entries as
         * due therefore proves nothing — the first version of this drill
         * did exactly that and every sweep correctly reported releasing
         * nothing.
         */
        $orderIds = DB::table('seller_orders')
            ->whereIn('status', ['delivered', 'partially_refunded'])
            ->whereNull('completed_at')
            ->whereNotNull('earnings_clear_at')
            ->where('earnings_clear_at', '<=', now())
            ->orderByRaw('(id * 48271) % 2147483647')
            ->limit($count)
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            $this->error('No delivered seller orders are due for clearing.');

            return self::FAILURE;
        }

        /*
         * Made the oldest thing due, so they are the window the sweep
         * actually takes. `due()` orders by clearing date and stops at
         * its limit, and the dataset has thousands of orders already
         * past theirs — a batch picked at random sits behind them and
         * the sweeps spend their pass on other people's money.
         */
        DB::table('seller_orders')->whereIn('id', $orderIds)->update([
            'earnings_clear_at' => now()->subYears(10),
        ]);

        /*
         * Their earnings, put back where the release action will find
         * them. The generator writes delivered orders' entries as already
         * available — a post-clearing world — so exercising the release
         * path at all means recreating the state it runs against.
         */
        DB::table('seller_ledger_entries')
            ->whereIn('seller_order_id', $orderIds)
            ->whereIn('status', ['pending', 'available'])
            ->update(['status' => 'clearing', 'available_at' => now()->subDay()]);

        $ids = DB::table('seller_ledger_entries')
            ->whereIn('seller_order_id', $orderIds)
            ->where('status', 'clearing')
            ->pluck('id');

        $this->write([
            'order_ids' => $orderIds->map(static fn (mixed $id): int => (int) $id)->all(),
            'entry_ids' => $ids->map(static fn (mixed $id): int => (int) $id)->all(),
            'before' => $this->clearingPosition($ids->all()) + [
                'orders_open' => $orderIds->count(),
            ],
        ], $this->scenarioPath('clearing'));

        $this->info(sprintf(
            '%d seller orders are due, holding %d ledger entries ready for release.',
            $orderIds->count(),
            $ids->count(),
        ));

        return self::SUCCESS;
    }

    private function verifyClearing(): int
    {
        $state = $this->read($this->scenarioPath('clearing'));
        /** @var array<int, int> $ids */
        $ids = $state['entry_ids'];
        $before = $state['before'];
        $after = $this->clearingPosition($ids);

        $findings = [];

        $ordersOpen = DB::table('seller_orders')
            ->whereIn('id', $state['order_ids'])
            ->whereNull('completed_at')
            ->count();

        if ($ordersOpen > 0) {
            $findings[] = sprintf('%d seller orders were left uncompleted', $ordersOpen);
        }

        if ($after['still_clearing'] > 0) {
            $findings[] = sprintf('%d entries were left behind in clearing', $after['still_clearing']);
        }

        if ($after['available'] !== count($ids)) {
            $findings[] = sprintf('%d of %d entries became available', $after['available'], count($ids));
        }

        /*
         * A release is a status change, not a transfer. If overlapping
         * sweeps had double-applied anything, the money or the row count
         * would have moved with it.
         */
        if ($after['sum_minor'] !== (int) $before['sum_minor']) {
            $findings[] = sprintf(
                'the batch was worth %d minor units and is now worth %d',
                $before['sum_minor'],
                $after['sum_minor'],
            );
        }

        if ($after['rows'] !== (int) $before['rows']) {
            $findings[] = sprintf('the batch had %d rows and now has %d', $before['rows'], $after['rows']);
        }

        $this->table(['', 'before', 'after'], [
            ['rows in the batch', $before['rows'], $after['rows']],
            ['still clearing', $before['still_clearing'], $after['still_clearing']],
            ['available', $before['available'], $after['available']],
            ['worth (minor)', $before['sum_minor'], $after['sum_minor']],
            ['orders still open', $before['orders_open'], $ordersOpen],
        ]);

        if ($findings !== []) {
            foreach ($findings as $finding) {
                $this->error('  '.$finding);
            }

            return self::FAILURE;
        }

        $this->info('Every entry was released exactly once, and nothing was created or lost.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array{rows: int, still_clearing: int, available: int, sum_minor: int}
     */
    private function clearingPosition(array $ids): array
    {
        $rows = DB::table('seller_ledger_entries')->whereIn('id', $ids);

        return [
            'rows' => (int) (clone $rows)->count(),
            'still_clearing' => (int) (clone $rows)->where('status', 'clearing')->count(),
            'available' => (int) (clone $rows)->where('status', 'available')->count(),
            'sum_minor' => (int) (clone $rows)->sum('amount_minor'),
        ];
    }

    private function scenarioPath(string $name): string
    {
        return dirname($this->path()).'/'.$name.'.json';
    }

    private function webhookPath(): string
    {
        return dirname($this->path()).'/webhook.json';
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
    private function write(array $state, ?string $path = null): void
    {
        $path ??= $this->path();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, (string) json_encode($state, JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed> */
    private function read(?string $path = null): array
    {
        $path ??= $this->path();

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

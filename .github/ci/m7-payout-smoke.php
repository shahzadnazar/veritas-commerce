<?php

declare(strict_types=1);

/*
 * The payout lifecycle, end to end, inside the built image.
 *
 * Runs against the container's real PostgreSQL and Redis, through the same
 * domain actions the seller and admin portals call — nothing here inserts
 * an allocation, posts a ledger row or moves a status by hand (§84),
 * because a smoke that did could pass while the real path produced
 * something different.
 *
 * Three things are exercised:
 *
 *   1. One seller, all the way: pay, deliver, clear, request, reserve,
 *      review, approve, settle, and the ledger debit that proves money
 *      left. Then a refund lands behind it and the balance goes negative
 *      without either historical record being touched.
 *   2. Two sellers on one order: A withdraws and is refunded; B is
 *      untouched throughout.
 *   3. The reconciliation, which must report clean after all of it.
 *
 * Time is moved with the framework's test clock rather than by waiting a
 * week, and the clearing sweep is the real scheduled command.
 *
 * Printed as key=value lines the workflow greps, so a failure here fails
 * this step rather than surfacing as a confusing assertion later.
 */

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Models\Cart;
use App\Modules\Checkout\Actions\PlaceOrder;
use App\Modules\Checkout\Actions\StartCheckout;
use App\Modules\Checkout\Data\ShippingAddress;
use App\Modules\Identity\Models\User;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Offers\Models\Offer;
use App\Modules\Orders\Actions\AcknowledgeSellerOrder;
use App\Modules\Orders\Actions\CreateShipment;
use App\Modules\Orders\Actions\MarkShipmentDelivered;
use App\Modules\Orders\Actions\MarkShipmentShipped;
use App\Modules\Orders\Data\ShipmentTracking;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Actions\PreparePayment;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Adapters\FakePaymentProvider;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Http\Controllers\ProviderWebhookController;
use App\Modules\Payouts\Actions\ApprovePayout;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Actions\RequestPayout;
use App\Modules\Payouts\Actions\StartPayoutReview;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutAccountType;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Payouts\Queries\ReconcileSellerFinance;
use App\Modules\Sellers\Models\SellerAccount;
use App\Support\Queues;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @var FakePaymentProvider $provider */
$provider = app(PaymentProvider::class);

$customer = User::query()->where('email', 'm4-customer@veritas.test')->firstOrFail();
$finance = PayoutActor::admin(null, 'CI finance');

$offerFor = static fn (string $title): Offer => Offer::query()->whereIn(
    'product_id',
    DB::table('products')->where('title', $title)->select('id'),
)->firstOrFail();

$position = static fn (int $sellerAccountId) => app(GetSellerFinancialPosition::class)($sellerAccountId);

/** @param array<int, array{0: Offer, 1: int}> $lines */
$place = static function (array $lines) use ($customer): MarketplaceOrder {
    $cart = Cart::query()->create([
        'user_id' => $customer->id,
        'session_token' => null,
        'status' => 'active',
        'last_activity_at' => now(),
    ]);

    foreach ($lines as [$offer, $quantity]) {
        app(AddOfferToCart::class)($cart, $offer->public_id, $quantity);
    }

    return app(PlaceOrder::class)(app(StartCheckout::class)(
        $cart,
        'm7smoke'.Str::lower((string) Str::ulid()),
        new ShippingAddress(
            name: 'M7 Customer',
            line1: '1 Finance Row',
            line2: null,
            city: 'London',
            state: null,
            postcode: 'EC1A 1BB',
            country: 'GB',
        ),
        $customer->id,
        'm4-customer@veritas.test',
    ));
};

/** Pay through the real M5 boundary: signed event, real controller, real queue. */
$pay = static function (MarketplaceOrder $order) use ($provider): void {
    ['attempt' => $attempt] = app(PreparePayment::class)($order);
    $reference = (string) $attempt->provider_reference;

    $provider->settle($reference, PaymentAttemptStatus::Succeeded);

    $signed = $provider->signedEvent('payment_intent.succeeded', $provider->paymentObject($reference));

    app(ProviderWebhookController::class)(Request::create(
        '/webhooks/payments',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
        content: $signed['payload'],
    ));

    Artisan::call('queue:work', [
        '--queue' => Queues::PAYMENTS,
        '--stop-when-empty' => true,
        '--tries' => 1,
        '--max-time' => 30,
    ]);
};

/** Confirm, pack everything owed, hand it over, deliver it. */
$deliverAll = static function (SellerOrder $sellerOrder): void {
    app(AcknowledgeSellerOrder::class)->confirm($sellerOrder);

    $lines = OrderItem::query()
        ->where('seller_order_id', $sellerOrder->id)
        ->get()
        ->map(static fn (OrderItem $item): array => [
            'order_item_id' => (int) $item->id,
            'quantity' => $item->quantity,
        ])
        ->all();

    $shipment = app(CreateShipment::class)(
        $sellerOrder->refresh(),
        $lines,
        ShipmentTracking::of('ups', '1Z999AA1'.random_int(1_000_000_000, 9_999_999_999)),
    );

    app(MarkShipmentShipped::class)($shipment);
    app(MarkShipmentDelivered::class)($shipment->refresh());
};

/** A destination, as a seller would add one. No credential exists to store. */
$destinationFor = static function (int $sellerAccountId): PayoutAccount {
    $existing = PayoutAccount::query()->withoutGlobalScopes()
        ->where('seller_account_id', $sellerAccountId)
        ->where('status', PayoutAccount::STATUS_ACTIVE)
        ->first();

    if ($existing !== null) {
        return $existing;
    }

    return PayoutAccount::query()->create([
        'seller_account_id' => $sellerAccountId,
        'type' => PayoutAccountType::Manual->value,
        'provider' => 'manual',
        'display_label' => 'CI settlement account',
        'last4' => '4242',
        'country' => 'US',
        'currency' => 'USD',
        'status' => PayoutAccount::STATUS_ACTIVE,
    ]);
};

/* ---- One order, two sellers, one of them paid and then refunded ------- */

/*
 * Deliberately one order rather than two.
 *
 * §84 and §85 ask for the payout lifecycle and for multi-seller
 * isolation, and running them as one story is stronger than running
 * them apart: everything that happens to seller A below happens while
 * seller B is sitting in the same marketplace order, so "B was
 * untouched" is a claim about a store that was genuinely in the way.
 */
$order = $place([
    [$offerFor('M7 Smoke Blender'), 4],
    [$offerFor('M7 Smoke Scale'), 1],
]);

$pay($order);

$sellerOrders = SellerOrder::query()->withoutGlobalScopes()
    ->where('marketplace_order_id', $order->id)
    ->orderBy('position')
    ->get();

$a = $sellerOrders->firstOrFail();
$b = $sellerOrders->skip(1)->firstOrFail();

$aId = (int) $a->seller_account_id;
$bId = (int) $b->seller_account_id;

printf("order=%s sellers=%d a=%s b=%s\n",
    $order->reference, $sellerOrders->count(), $a->reference, $b->reference);

foreach ($sellerOrders as $each) {
    $deliverAll($each);
}

// The clock, and the real scheduled sweep.
Carbon::setTestNow(Carbon::now()->addDays(8));
Artisan::call('earnings:clear');

$cleared = $position($aId);
$bBefore = $position($bId);

printf("cleared a_available=%d a_withdrawable=%d a_reserved=%d b_withdrawable=%d\n",
    $cleared->availableMinor,
    $cleared->withdrawableMinor(),
    $cleared->reservedMinor,
    $bBefore->withdrawableMinor());

$destinationFor($aId);

// Half of it, so the arithmetic after settlement is not zero either way.
$requested = intdiv($cleared->withdrawableMinor(), 2);

$payout = app(RequestPayout::class)(
    seller: SellerAccount::query()->findOrFail($aId),
    amountMinor: $requested,
    actor: PayoutActor::seller(null, 'CI seller A'),
);

$held = $position($aId);

printf("seller_order_reference=%s\n", $a->reference);
printf("payout_reference=%s\n", $payout->reference);

printf("requested payout=%s amount=%d reserved=%d withdrawable=%d available=%d\n",
    $payout->reference, $payout->amount_minor,
    $held->reservedMinor, $held->withdrawableMinor(), $held->availableMinor);

app(StartPayoutReview::class)($payout, $finance);
app(ApprovePayout::class)($payout->refresh(), $finance);

$payoutDebits = static fn (int $id): int => SellerLedgerEntry::query()->withoutGlobalScopes()
    ->where('seller_account_id', $id)
    ->where('type', LedgerEntryType::Payout->value)
    ->count();

$approved = $position($aId);

printf("approved status=%s reserved=%d debits=%d\n",
    $payout->refresh()->status->value, $approved->reservedMinor, $payoutDebits($aId));

app(RecordPayoutSettlement::class)($payout->refresh(), $finance, 'wire', 'CI-FT-0001');
// Twice more: a retried job and a second click on the same button.
app(RecordPayoutSettlement::class)($payout->refresh(), $finance, 'wire', 'CI-FT-0001');

$settled = $position($aId);

printf("settled status=%s reserved=%d withdrawable=%d paid_out=%d debits=%d ref=%s\n",
    $payout->refresh()->status->value,
    $settled->reservedMinor,
    $settled->withdrawableMinor(),
    $settled->paidOutMinor,
    $payoutDebits($aId),
    (string) $payout->settlement_ref);

$bAfterPayout = $position($bId);

printf("isolation_after_payout b_withdrawable=%d b_paid_out=%d b_payouts=%d\n",
    $bAfterPayout->withdrawableMinor(),
    $bAfterPayout->paidOutMinor,
    DB::table('payout_requests')->where('seller_account_id', $bId)->count());

/* ---- A refund lands behind money that has already left ---------------- */

$item = OrderItem::query()->where('seller_order_id', $a->id)->firstOrFail();

// Three of the four, which is more than is left after the payout — so the
// store ends up owing the platform rather than the money going missing.
app(RequestRefund::class)(
    order: $order->refresh(),
    lines: [[
        'order_item_id' => (int) $item->id,
        'amount_minor' => 3 * (int) $item->unit_price_snapshot_minor,
        'quantity' => 3,
    ]],
    reason: 'CI: returned after the payout.',
);

$afterRefund = $position($aId);
$bAfterRefund = $position($bId);

printf("post_refund a_net=%d a_capacity=%d a_withdrawable=%d a_paid_out=%d payout_status=%s payout_amount=%d\n",
    $afterRefund->netBalanceMinor(),
    // Signed: how far short the store is. Clamped: what may be asked for,
    // which is nothing rather than a negative amount.
    $afterRefund->rawPayoutCapacityMinor(),
    $afterRefund->withdrawableMinor(),
    $afterRefund->paidOutMinor,
    $payout->refresh()->status->value,
    $payout->amount_minor);

printf("isolation_after_refund b_net=%d b_withdrawable=%d\n",
    $bAfterRefund->netBalanceMinor(),
    $bAfterRefund->withdrawableMinor());

$blocked = 'allowed';

try {
    app(RequestPayout::class)(
        seller: SellerAccount::query()->findOrFail($aId),
        amountMinor: 1_000,
        actor: PayoutActor::seller(null, 'CI seller A'),
    );
} catch (PayoutNotPermitted $refused) {
    $blocked = $refused->reason;
}

printf("blocked reason=%s\n", $blocked);

Carbon::setTestNow();

/* ---- 4. Does it all add up? ------------------------------------------- */

$problems = app(ReconcileSellerFinance::class)();

printf("reconcile problems=%d\n", count($problems));

foreach ($problems as $problem) {
    printf("reconcile_problem %s %s: %s\n", $problem['check'], $problem['subject'], $problem['detail']);
}

Artisan::call('finance:reconcile-sellers');
printf("reconcile_command %s\n", str_replace("\n", ' ', trim(Artisan::output())));

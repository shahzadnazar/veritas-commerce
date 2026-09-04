<?php

declare(strict_types=1);

/*
 * The payment lifecycle, end to end, inside the built image.
 *
 * Runs against the real PostgreSQL and the real Redis the container is
 * wired to, through the same actions and the same webhook pipeline the
 * application uses — nothing here reaches into a table to arrange a state
 * the system could not produce.
 *
 * It drives the provider in-process rather than over HTTP for one honest
 * reason: the fake provider holds its payments in memory, so a payment
 * prepared by a php-fpm worker is not visible to a different worker. The
 * HTTP surface is smoke-tested separately in the workflow — the payment
 * page's states, the prepare endpoint's amount authority, and a forged
 * signature's refusal all go over the wire. What this script proves is the
 * financial machinery: verification, exactly-once posting, replay, failure,
 * retry, reconciliation and reversal.
 *
 * Printed as key=value lines the workflow greps, so a failure here fails
 * this step rather than surfacing as a confusing assertion later.
 */

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Models\Cart;
use App\Modules\Checkout\Actions\PlaceOrder;
use App\Modules\Checkout\Actions\StartCheckout;
use App\Modules\Checkout\Data\ShippingAddress;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Offers\Models\Offer;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Actions\PreparePayment;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Adapters\FakePaymentProvider;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Http\Controllers\ProviderWebhookController;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PlatformRevenueEntry;
use App\Modules\Payments\Models\ProviderWebhookEvent;
use App\Support\Queues;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @var FakePaymentProvider $provider */
$provider = app(PaymentProvider::class);

/*
 * Its own order, placed through the real cart, quote and reservation path.
 *
 * Not the one the HTTP smoke left behind: that one is deliberately expired
 * by an earlier step, and a smoke that depended on the order of two other
 * steps would fail for reasons that have nothing to do with payments.
 */
$customer = User::query()->where('email', 'm4-customer@veritas.test')->firstOrFail();
$offer = Offer::query()->whereIn(
    'product_id',
    DB::table('products')->where('title', 'M4 Smoke Kettle')->select('id'),
)->firstOrFail();

$cart = Cart::query()->create([
    'user_id' => $customer->id,
    'session_token' => null,
    'status' => 'active',
    'last_activity_at' => now(),
]);

app(AddOfferToCart::class)($cart, $offer->public_id, 2);

$attemptRow = app(StartCheckout::class)(
    $cart,
    'm5smoke'.Str::lower((string) Str::ulid()),
    new ShippingAddress(
        name: 'M5 Customer',
        line1: '1 Payment Way',
        line2: null,
        city: 'London',
        state: null,
        postcode: 'EC1A 1BB',
        country: 'GB',
    ),
    $customer->id,
    'm4-customer@veritas.test',
);

$order = app(PlaceOrder::class)($attemptRow);

// A commission rule must exist for the platform to take a share; the demo
// seed provides one, and this fails loudly rather than silently taking 0%.
if (CommissionRule::query()->count() === 0) {
    throw new RuntimeException('No commission rule: the platform would record nothing.');
}

/**
 * Deliver a signed event through the real controller, then drain the queue.
 *
 * The controller verifies, persists and dispatches; the work happens in a
 * worker. Draining here with the real `queue:work` against the container's
 * Redis is the point — it proves the whole path rather than the half of it
 * that runs inside the request, and it does so in this process, where the
 * fake provider's payments live.
 */
$deliver = static function (string $type, array $object, ?string $eventId = null) use ($provider): int {
    $signed = $provider->signedEvent($type, $object, $eventId);

    $request = Request::create(
        '/webhooks/payments',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
        content: $signed['payload'],
    );

    $status = app(ProviderWebhookController::class)($request)->getStatusCode();

    Artisan::call('queue:work', [
        '--queue' => Queues::PAYMENTS,
        '--stop-when-empty' => true,
        '--tries' => 1,
        '--max-time' => 30,
    ]);

    return $status;
};

/* ---- 1. Preparation is server-authoritative ---------------------------- */

['attempt' => $attempt] = app(PreparePayment::class)($order);
$reference = (string) $attempt->provider_reference;

printf("prepared amount=%d order_total=%d currency=%s\n",
    $attempt->amount_minor, $order->grand_total_minor, $attempt->currency);

// Preparing again re-joins the same provider payment rather than holding a
// second authorisation against the customer's card.
['attempt' => $again] = app(PreparePayment::class)($order->refresh());
printf("idempotent attempts=%d same=%s\n",
    PaymentAttempt::query()->where('marketplace_order_id', $order->id)->count(),
    $again->provider_reference === $reference ? 'yes' : 'no');

/* ---- 2. A forged signature is refused --------------------------------- */

$forged = (string) json_encode([
    'id' => 'evt_forged',
    'type' => 'payment_intent.succeeded',
    'data' => ['object' => ['id' => $reference, 'status' => 'succeeded']],
], JSON_THROW_ON_ERROR);

$forgedStatus = app(ProviderWebhookController::class)(Request::create(
    '/webhooks/payments',
    'POST',
    server: ['HTTP_STRIPE_SIGNATURE' => 'v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
    content: $forged,
))->getStatusCode();

printf("forged status=%d stored=%d\n", $forgedStatus, ProviderWebhookEvent::query()->count());

/* ---- 3. A decline leaves the order payable ---------------------------- */

$provider->settle($reference, PaymentAttemptStatus::Failed);
$deliver('payment_intent.payment_failed', $provider->paymentObject($reference), 'evt_m5_failed');

printf("declined order_status=%s attempt_status=%s\n",
    $order->refresh()->status->value,
    PaymentAttempt::query()->whereKey($attempt->id)->firstOrFail()->status->value);

/* ---- 4. The retry succeeds and is verified ---------------------------- */

['attempt' => $retry] = app(PreparePayment::class)($order->refresh());
$good = (string) $retry->provider_reference;

$provider->settle($good, PaymentAttemptStatus::Succeeded);

$first = $deliver('payment_intent.succeeded', $provider->paymentObject($good), 'evt_m5_paid');

// Replayed twice more, exactly as a provider retry would.
$deliver('payment_intent.succeeded', $provider->paymentObject($good), 'evt_m5_paid');
$deliver('payment_intent.succeeded', $provider->paymentObject($good), 'evt_m5_paid_again');

$order->refresh();

printf("paid status=%d order=%s payments=%d transactions=%d\n",
    $first, $order->status->value, Payment::query()->count(), PaymentTransaction::query()->count());

printf("seller_orders=%s\n", SellerOrder::query()->withoutGlobalScopes()
    ->where('marketplace_order_id', $order->id)
    ->get()
    ->map(static fn (SellerOrder $sellerOrder): string => $sellerOrder->status->value)
    ->unique()
    ->implode(','));

/* ---- 5. The money reconciles ------------------------------------------ */

$itemIds = OrderItem::query()->whereIn(
    'seller_order_id',
    SellerOrder::query()->withoutGlobalScopes()->where('marketplace_order_id', $order->id)->select('id'),
)->pluck('id');

$earnings = (int) SellerLedgerEntry::query()->withoutGlobalScopes()
    ->whereIn('order_item_id', $itemIds)->sum('amount_minor');
$commission = (int) PlatformRevenueEntry::query()->whereIn('order_item_id', $itemIds)->sum('amount_minor');

printf("reconciled total=%d earnings=%d commission=%d exact=%s\n",
    $order->grand_total_minor, $earnings, $commission,
    $earnings + $commission === $order->grand_total_minor ? 'yes' : 'no');

// §33 — the earning exists and is NOT withdrawable at payment time.
$earning = SellerLedgerEntry::query()->withoutGlobalScopes()
    ->whereIn('order_item_id', $itemIds)->where('amount_minor', '>', 0)->firstOrFail();

printf("earning status=%s available_at=%s\n",
    $earning->status->value, $earning->available_at === null ? 'null' : 'set');

/* ---- 6. A refund reverses exactly what the sale recorded --------------- */

$item = OrderItem::query()->whereIn('id', $itemIds)->firstOrFail();

$refund = app(RequestRefund::class)(
    order: $order,
    lines: [['order_item_id' => $item->id, 'amount_minor' => $item->line_total_minor, 'quantity' => $item->quantity]],
    reason: 'CI smoke: the customer returned the kettle.',
);

// The provider tells us again, twice, as providers do.
$provider->settleRefund((string) $refund->provider_refund_reference, RefundStatus::Succeeded);
$deliver('refund.updated', $provider->refundObject((string) $refund->provider_refund_reference), 'evt_m5_refund');
$deliver('refund.updated', $provider->refundObject((string) $refund->provider_refund_reference), 'evt_m5_refund_again');

$reversals = SellerLedgerEntry::query()->withoutGlobalScopes()
    ->whereIn('order_item_id', $itemIds)->where('amount_minor', '<', 0)->get();

printf("refund status=%s reversals=%d earning_reversed=%d commission_reversed=%d\n",
    $refund->refresh()->status->value,
    $reversals->count(),
    (int) $reversals->sum('amount_minor'),
    (int) PlatformRevenueEntry::query()->whereIn('order_item_id', $itemIds)
        ->where('amount_minor', '<', 0)->sum('amount_minor'),
);

printf("net earnings=%d commission=%d payment_refunded=%d\n",
    (int) SellerLedgerEntry::query()->withoutGlobalScopes()->whereIn('order_item_id', $itemIds)->sum('amount_minor'),
    (int) PlatformRevenueEntry::query()->whereIn('order_item_id', $itemIds)->sum('amount_minor'),
    (int) Payment::query()->firstOrFail()->refunded_amount_minor,
);

echo 'order_reference='.$order->reference, PHP_EOL;

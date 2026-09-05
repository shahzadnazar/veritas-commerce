<?php

declare(strict_types=1);

/*
 * The fulfilment and clearing lifecycle, end to end, inside the built
 * image.
 *
 * Runs against the container's real PostgreSQL and Redis, through the same
 * domain actions the seller portal calls — nothing here inserts a shipment
 * row or moves a status by hand, because a smoke that did could pass while
 * the real path produced something different.
 *
 * Two orders are exercised. A single-seller order walks the whole way:
 * confirm, pack, ship, deliver, clear, complete. A two-seller order proves
 * the part that only a marketplace has to get right — delivering one
 * seller leaves the other untouched, and each clears on its own date.
 *
 * Time is moved with the framework's test clock rather than by waiting
 * seven days, and the clearing sweep is the real scheduled command.
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
use App\Modules\Ledger\Queries\SellerBalance;
use App\Modules\Offers\Models\Offer;
use App\Modules\Orders\Actions\AcknowledgeSellerOrder;
use App\Modules\Orders\Actions\CreateShipment;
use App\Modules\Orders\Actions\MarkShipmentDelivered;
use App\Modules\Orders\Actions\MarkShipmentShipped;
use App\Modules\Orders\Data\ShipmentTracking;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Queries\SummariseOrderFulfilment;
use App\Modules\Payments\Actions\PreparePayment;
use App\Modules\Payments\Adapters\FakePaymentProvider;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Http\Controllers\ProviderWebhookController;
use App\Support\Queues;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @var FakePaymentProvider $provider */
$provider = app(PaymentProvider::class);

$customer = User::query()->where('email', 'm4-customer@veritas.test')->firstOrFail();

$offerFor = static fn (string $title): Offer => Offer::query()->whereIn(
    'product_id',
    DB::table('products')->where('title', $title)->select('id'),
)->firstOrFail();

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
        'm6smoke'.Str::lower((string) Str::ulid()),
        new ShippingAddress(
            name: 'M6 Customer',
            line1: '1 Fulfilment Way',
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

/** Confirm, pack everything still owed, hand it over. */
$shipAll = static function (SellerOrder $sellerOrder): \App\Modules\Orders\Models\Shipment {
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

    return $shipment->refresh();
};

/* ---- 1. One seller, all the way through ------------------------------- */

$order = $place([[$offerFor('M4 Smoke Kettle'), 2]]);
$pay($order);

$sellerOrder = SellerOrder::query()->withoutGlobalScopes()
    ->where('marketplace_order_id', $order->id)->firstOrFail();

printf("paid seller_order=%s status=%s\n", $sellerOrder->reference, $sellerOrder->status->value);

$onHandAfterPayment = (int) DB::table('inventory_balances')
    ->where('offer_id', OrderItem::query()->where('seller_order_id', $sellerOrder->id)->value('offer_id'))
    ->value('on_hand');

$shipment = $shipAll($sellerOrder);

printf("shipped shipment=%s status=%s order=%s carrier=%s\n",
    $shipment->reference,
    $shipment->status->value,
    $sellerOrder->refresh()->status->value,
    (string) $shipment->carrier_name);

$onHandAfterShipping = (int) DB::table('inventory_balances')
    ->where('offer_id', OrderItem::query()->where('seller_order_id', $sellerOrder->id)->value('offer_id'))
    ->value('on_hand');

printf("inventory after_payment=%d after_shipping=%d\n", $onHandAfterPayment, $onHandAfterShipping);

app(MarkShipmentDelivered::class)($shipment);
// Twice more: a retried job and a second click.
app(MarkShipmentDelivered::class)($shipment->refresh());
app(MarkShipmentDelivered::class)($shipment->refresh());

$sellerOrder->refresh();
$sellerAccountId = (int) $sellerOrder->seller_account_id;

printf("delivered status=%s clear_at=%s\n",
    $sellerOrder->status->value,
    $sellerOrder->earnings_clear_at?->toDateString() ?? 'null');

$balance = app(SellerBalance::class)($sellerAccountId);

printf("clearing pending=%d clearing=%d available=%d\n",
    $balance['pending']->minor, $balance['clearing']->minor, $balance['available']->minor);

/* ---- 2. The clock, and the real scheduled sweep ----------------------- */

Artisan::call('earnings:clear');
$early = trim(Artisan::output());

printf("early_sweep %s\n", str_replace("\n", ' ', $early));

Carbon::setTestNow(Carbon::now()->addDays(8));

Artisan::call('earnings:clear');
$released = trim(Artisan::output());

printf("sweep %s\n", str_replace("\n", ' ', $released));

// Again, to prove re-running settles nothing twice.
Artisan::call('earnings:clear');
printf("resweep %s\n", str_replace("\n", ' ', trim(Artisan::output())));

$balance = app(SellerBalance::class)($sellerAccountId);

printf("cleared available=%d clearing=%d order_status=%s\n",
    $balance['available']->minor,
    $balance['clearing']->minor,
    $sellerOrder->refresh()->status->value);

Carbon::setTestNow();

/* ---- 3. Two sellers, delivered one at a time -------------------------- */

$multi = $place([
    [$offerFor('M4 Smoke Kettle'), 1],
    [$offerFor('M6 Smoke Grinder'), 1],
]);

$pay($multi);

$sellerOrders = SellerOrder::query()->withoutGlobalScopes()
    ->where('marketplace_order_id', $multi->id)
    ->orderBy('position')
    ->get();

printf("multi seller_orders=%d\n", $sellerOrders->count());

$first = $sellerOrders->firstOrFail();
$second = $sellerOrders->skip(1)->firstOrFail();

app(MarkShipmentDelivered::class)($shipAll($first));

$summary = app(SummariseOrderFulfilment::class)->forOrder($multi->refresh());

printf("first_delivered a=%s b=%s parent=%s\n",
    $first->refresh()->status->value,
    $second->refresh()->status->value,
    $summary['state']);

printf("independent_clocks a=%s b=%s\n",
    $first->earnings_clear_at?->toDateString() ?? 'null',
    $second->earnings_clear_at?->toDateString() ?? 'null');

app(MarkShipmentDelivered::class)($shipAll($second->refresh()));

$summary = app(SummariseOrderFulfilment::class)->forOrder($multi->refresh());

printf("both_delivered a=%s b=%s parent=%s\n",
    $first->refresh()->status->value,
    $second->refresh()->status->value,
    $summary['state']);

echo 'order_reference='.$order->reference, PHP_EOL;
echo 'multi_reference='.$multi->reference, PHP_EOL;
echo 'seller_order_reference='.$sellerOrder->reference, PHP_EOL;

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Cart\Events\CartLineAdded;
use App\Modules\Cart\Events\CartLineQuantityChanged;
use App\Modules\Cart\Events\CartLineRemoved;
use App\Modules\Cart\Listeners\AdoptCartOnLogin;
use App\Modules\Catalog\Events\ProductApproved;
use App\Modules\Catalog\Events\ProductChangesRequested;
use App\Modules\Catalog\Events\ProductEdited;
use App\Modules\Catalog\Events\ProductPublished;
use App\Modules\Catalog\Events\ProductRejected;
use App\Modules\Catalog\Events\ProductSuspended;
use App\Modules\Catalog\Listeners\KeepSearchIndexCurrent;
use App\Modules\Catalog\Listeners\NotifyProposingSeller;
use App\Modules\Catalog\Queries\BuildIndexableProduct;
use App\Modules\Events\Listeners\RecordCartActivity;
use App\Modules\Events\Listeners\RecordFulfilmentActivity;
use App\Modules\Events\Listeners\RecordPaymentActivity;
use App\Modules\Inventory\Events\InventoryAdjusted;
use App\Modules\Inventory\Events\InventoryDepleted;
use App\Modules\Inventory\Events\InventoryLow;
use App\Modules\Inventory\Events\InventoryRestored;
use App\Modules\Inventory\Listeners\NotifySellerOfStockLevel;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Stores\FilesystemObjectStore;
use App\Modules\Offers\Events\OfferActivated;
use App\Modules\Offers\Events\OfferSuspended;
use App\Modules\Offers\Events\OfferUpdated;
use App\Modules\Orders\Events\SellerOrderConfirmed;
use App\Modules\Orders\Events\SellerOrderDelivered;
use App\Modules\Orders\Events\ShipmentCreated;
use App\Modules\Orders\Events\ShipmentDelivered;
use App\Modules\Orders\Events\ShipmentShipped;
use App\Modules\Orders\Listeners\AnnounceFulfilment;
use App\Modules\Payments\Adapters\FakePaymentProvider;
use App\Modules\Payments\Adapters\StripePaymentProvider;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Listeners\AnnouncePaidOrder;
use App\Modules\Payouts\Contracts\PayoutProvider;
use App\Modules\Payouts\Events\PayoutApproved;
use App\Modules\Payouts\Events\PayoutFailed;
use App\Modules\Payouts\Events\PayoutPaid;
use App\Modules\Payouts\Events\PayoutRejected;
use App\Modules\Payouts\Events\PayoutRequested;
use App\Modules\Payouts\Listeners\AnnouncePayout;
use App\Modules\Payouts\Providers\ManualPayoutProvider;
use App\Modules\Search\Adapters\PostgresSearchIndex;
use App\Modules\Search\Contracts\IndexableProductSource;
use App\Modules\Search\Contracts\SearchIndex;
use App\Modules\Sellers\Events\SellerApproved;
use App\Modules\Sellers\Listeners\NotifyApprovedSeller;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Stripe\StripeClient;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The domain depends on the port, never on a vendor SDK. M0 binds
        // the fake driver; the Stripe driver is registered here in M3
        // without a single change inside the modules.
        // Storage is a port. The filesystem implementation covers both the
        // local disk and S3-compatible object storage, chosen by
        // configuration — swapping providers changes an env file, not code.
        $this->app->bind(ObjectStore::class, FilesystemObjectStore::class);

        // Search is a port too. PostgreSQL's own full-text index backs it
        // for now; M3 swaps in a dedicated engine by changing this line.
        $this->app->bind(SearchIndex::class, PostgresSearchIndex::class);

        // The catalogue describes its own products for the index. Search
        // sees the interface and the flat document, never a Catalog model.
        $this->app->bind(IndexableProductSource::class, BuildIndexableProduct::class);

        /*
         * The payment port.
         *
         * A singleton because the fake driver holds its payments in memory
         * and a test that prepared one has to be able to settle it — with a
         * fresh instance per resolve, the second half of every payment test
         * would be talking to a provider that had never heard of the first.
         *
         * The Stripe client is built here and nowhere else, so the secret
         * key exists in exactly one place in the application.
         */
        $this->app->singleton(PaymentProvider::class, function (): PaymentProvider {
            if (config('veritas.payments.provider') !== 'stripe') {
                return new FakePaymentProvider;
            }

            return new StripePaymentProvider(
                new StripeClient([
                    'api_key' => (string) config('veritas.payments.stripe.secret'),
                    'stripe_version' => (string) config('veritas.payments.stripe.api_version'),
                ]),
                (string) config('veritas.payments.stripe.webhook_secret'),
            );
        });

        /*
         * The payout port.
         *
         * One implementation, and it refuses to send money. M7 records
         * settlements that people performed outside the platform; there is
         * no rail, and an adapter that quietly returned "succeeded" would
         * make the code read as though there were. Binding it here means a
         * future Stripe Connect adapter is one line and no change to the
         * payout domain.
         */
        $this->app->bind(PayoutProvider::class, ManualPayoutProvider::class);
    }

    public function boot(): void
    {
        // Domain events are wired here rather than discovered, so the set
        // of listeners is readable in one place.
        Event::listen(SellerApproved::class, NotifyApprovedSeller::class);

        /*
         * A shopper who filled a basket before signing in keeps it. Bound
         * to the framework's own Login event so every sign-in path —
         * registration, password reset, a remembered session — behaves the
         * same way.
         */
        Event::listen(Login::class, [AdoptCartOnLogin::class, 'handle']);

        // Cart intent, into the behavioural stream. The cart actions
        // announce; only this listener knows analytics exist.
        Event::listen(CartLineAdded::class, [RecordCartActivity::class, 'added']);
        Event::listen(CartLineRemoved::class, [RecordCartActivity::class, 'removed']);
        Event::listen(CartLineQuantityChanged::class, [RecordCartActivity::class, 'quantityChanged']);

        /*
         * Stock thresholds. The listener holds the durable de-duplication,
         * not the dispatcher: stock crosses the same line repeatedly as
         * holds are taken and released.
         */
        Event::listen([
            InventoryLow::class,
            InventoryDepleted::class,
            InventoryRestored::class,
        ], [NotifySellerOfStockLevel::class, 'handle']);

        // Catalogue side effects. Both are queued at the job level rather
        // than the listener, so a failure to index or to mail never rolls
        // back the decision that caused it.
        Event::listen([
            ProductApproved::class,
            ProductEdited::class,
            ProductPublished::class,
            ProductSuspended::class,
        ], [KeepSearchIndexCurrent::class, 'productChanged']);

        Event::listen([
            OfferActivated::class,
            OfferSuspended::class,
            OfferUpdated::class,
        ], [KeepSearchIndexCurrent::class, 'offerChanged']);

        // Availability is denormalised into the search document, so a
        // stock change has to rebuild it or the storefront will keep
        // offering something nobody can buy.
        Event::listen(InventoryAdjusted::class, [KeepSearchIndexCurrent::class, 'stockChanged']);

        Event::listen([
            ProductApproved::class,
            ProductPublished::class,
            ProductRejected::class,
            ProductChangesRequested::class,
            ProductSuspended::class,
        ], NotifyProposingSeller::class);

        /*
         * The only announcement that money arrived.
         *
         * PaymentSucceeded is dispatched after the payment transaction
         * commits, and once per payment however many times the provider
         * redelivers the event, so this is where a customer receipt and a
         * seller's "new order" both come from — and from nowhere else.
         */
        Event::listen(PaymentSucceeded::class, [AnnouncePaidOrder::class, 'handle']);

        // The same two events, into the behavioural stream. A purchase is
        // recorded per seller, because that is the question the table is
        // asked. The payment module announces; only this listener knows
        // analytics exist.
        Event::listen(PaymentSucceeded::class, [RecordPaymentActivity::class, 'succeeded']);
        Event::listen(PaymentFailed::class, [RecordPaymentActivity::class, 'failed']);

        /*
         * Fulfilment. The customer is told about each parcel, once, and
         * the operational stream records how long each step took.
         *
         * "Once" is not enforced here: the actions refuse a parcel that has
         * already moved, under a row lock, so a retried job or a
         * double-clicked button dispatches nothing the second time.
         */
        Event::listen(ShipmentShipped::class, [AnnounceFulfilment::class, 'shipped']);
        Event::listen(ShipmentDelivered::class, [AnnounceFulfilment::class, 'delivered']);

        /*
         * Payout announcements, to the store's owners only. Approval and
         * settlement are two separate messages on purpose: the first says
         * authorised, the second says sent, and collapsing them is how a
         * seller is told money arrived that has not.
         */
        Event::listen(PayoutRequested::class, [AnnouncePayout::class, 'requested']);
        Event::listen(PayoutApproved::class, [AnnouncePayout::class, 'approved']);
        Event::listen(PayoutRejected::class, [AnnouncePayout::class, 'rejected']);
        Event::listen(PayoutPaid::class, [AnnouncePayout::class, 'paid']);
        Event::listen(PayoutFailed::class, [AnnouncePayout::class, 'failed']);

        Event::listen(SellerOrderConfirmed::class, [RecordFulfilmentActivity::class, 'confirmed']);
        Event::listen(ShipmentCreated::class, [RecordFulfilmentActivity::class, 'shipmentCreated']);
        Event::listen(ShipmentShipped::class, [RecordFulfilmentActivity::class, 'shipped']);
        Event::listen(ShipmentDelivered::class, [RecordFulfilmentActivity::class, 'shipmentDelivered']);
        Event::listen(SellerOrderDelivered::class, [RecordFulfilmentActivity::class, 'orderDelivered']);

        // Models live in module namespaces (App\Modules\Orders\Models\...),
        // so the default factory guess does not apply. Factories stay in one
        // flat namespace keyed by class basename.
        Factory::guessFactoryNamesUsing(static function (string $modelName): string {
            $factory = 'Database\\Factories\\'.class_basename($modelName).'Factory';

            if (! class_exists($factory) || ! is_subclass_of($factory, Factory::class)) {
                throw new RuntimeException("No factory for {$modelName}: expected {$factory}.");
            }

            return $factory;
        });

        Factory::guessModelNamesUsing(static function (Factory $factory): string {
            throw new RuntimeException(
                'Declare $model explicitly on '.$factory::class.'; module namespaces cannot be guessed.'
            );
        });

        // One password policy for the whole application, so registration,
        // reset and profile change cannot drift apart.
        //
        // The breach check queries the Pwned Passwords range API using
        // k-anonymity, so the password itself never leaves this server —
        // but it is still a network call, and a test suite must not make
        // one. It is enabled everywhere except testing.
        Password::defaults(function (): Password {
            $rule = Password::min(8);

            return $this->app->runningUnitTests() ? $rule : $rule->uncompromised();
        });

        // A lazy load is a performance bug that reaches production as an N+1.
        // Failing loudly outside production is how it gets caught in review.
        Model::preventLazyLoading(! $this->app->environment('production'));
        Model::preventSilentlyDiscardingAttributes(! $this->app->environment('production'));
    }
}

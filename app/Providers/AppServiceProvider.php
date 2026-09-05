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
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Events\SellerApproved;
use App\Modules\Sellers\Listeners\NotifyApprovedSeller;
use App\Modules\Sellers\Models\SellerMembership;
use App\Support\Database\ConfigurePostgresSession;
use App\Support\Diagnostics\DestructiveDatabaseGuard;
use App\Support\Diagnostics\DestructiveDatabaseRefused;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
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
        /*
         * Every PostgreSQL session is told what the search adapter means
         * by "close enough" before it is used, because the fuzzy
         * predicates are written as `pg_trgm` operators — the only form
         * the trigram index can serve — and an operator reads its cutoff
         * from a session setting.
         */
        Event::listen(ConnectionEstablished::class, [ConfigurePostgresSession::class, 'handle']);

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

        /*
         * A membership write invalidates the request-scoped membership
         * cache in CurrentSeller. Accepting an invitation, changing a
         * role or removing a member all change the answer to "what may
         * this actor do", and the rest of that same request must not go
         * on answering from the membership it read beforehand.
         */
        SellerMembership::saved(static function (): void {
            CurrentSeller::flushCache();
        });

        SellerMembership::deleted(static function (): void {
            CurrentSeller::flushCache();
        });

        $this->guardDestructiveDatabaseCommands();
    }

    /**
     * Nothing drops a database without naming it first.
     *
     * From a real M9 mistake: `migrate:fresh --seed --env=testing` dropped
     * the development database, because the repository had no
     * `.env.testing` and so `--env=testing` resolved to whatever `.env`
     * said. PHPUnit gets its database from `phpunit.xml`; an artisan
     * command does not inherit that, and the flag proves nothing.
     *
     * The repository now ships `.env.testing` so the flag means what
     * people assume. This is the belt: every destructive command
     * announces the database, host and environment it is about to act on
     * before it acts, and refuses outright on production or on a database
     * the environment has declared protected.
     *
     * Announcing is most of the value. Nobody misreads a database name
     * they were shown.
     */
    private function guardDestructiveDatabaseCommands(): void
    {
        $this->ensureCommandEventsAreDispatched();

        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            $guard = DestructiveDatabaseGuard::forCurrentRequest();

            if (! $guard->isDestructive((string) $event->command)) {
                return;
            }

            $event->output->writeln('<comment>'.$guard->announcement().'</comment>');

            $refusal = $guard->refusalReason();

            if ($refusal === null) {
                return;
            }

            $event->output->writeln('<error>'.$refusal.'</error>');
            $event->output->writeln(
                '<comment>Set '.DestructiveDatabaseGuard::OVERRIDE.'=1 only if you are certain.</comment>'
            );

            // Thrown rather than exited, so a test can observe the refusal
            // and a caller sees a non-zero status either way.
            throw new DestructiveDatabaseRefused($refusal);
        });
    }

    /**
     * Make `CommandStarting` fire on the CLI even when APP_ENV is testing.
     *
     * This is a framework behaviour worth stating plainly, because it
     * turned the guard above into decoration and the tests that "proved"
     * it into theatre. `Illuminate\Foundation\Console\Kernel` only
     * re-routes Symfony's console events to Laravel's when
     * `runningUnitTests()` is false — and `runningUnitTests()` is nothing
     * more than `environment('testing')`. So on any CLI run with
     * `APP_ENV=testing`, including every `php artisan --env=testing`,
     * `CommandStarting` is never dispatched and every listener on it is
     * silently inert.
     *
     * Which is to say: the guard against the `--env=testing` accident was
     * itself disabled by `--env=testing`. That is worse than having no
     * guard, because it looks like one.
     *
     * The framework's intent is to leave PHPUnit's `$this->artisan()`
     * alone, so that is what is preserved here — the reroute is restored
     * for CLI runs, and only for CLI runs. Inside PHPUnit nothing changes.
     * The call is idempotent; the kernel already ignores a second one.
     */
    private function ensureCommandEventsAreDispatched(): void
    {
        if (! $this->app->runningInConsole() || ! $this->app->runningUnitTests()) {
            return;
        }

        // `runningUnitTests()` means "APP_ENV is testing", which is not the
        // same question. This one is: has PHPUnit actually loaded?
        // A literal, and no autoload: PHPUnit is a dev dependency, and
        // application code should not hold a reference to it.
        if (class_exists('PHPUnit\\Framework\\TestCase', false)) {
            return;
        }

        $this->app->booted(function (): void {
            // The contract, not the concrete class: the concrete class is
            // not what the container has bound, so resolving it would
            // build a second kernel and re-route events on the one that
            // is not running. The CLI test below caught exactly that.
            $this->app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
        });
    }
}

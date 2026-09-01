<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Catalog\Events\ProductApproved;
use App\Modules\Catalog\Events\ProductChangesRequested;
use App\Modules\Catalog\Events\ProductEdited;
use App\Modules\Catalog\Events\ProductPublished;
use App\Modules\Catalog\Events\ProductRejected;
use App\Modules\Catalog\Events\ProductSuspended;
use App\Modules\Catalog\Listeners\KeepSearchIndexCurrent;
use App\Modules\Catalog\Listeners\NotifyProposingSeller;
use App\Modules\Catalog\Queries\BuildIndexableProduct;
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
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\FakePaymentGateway;
use App\Modules\Search\Adapters\PostgresSearchIndex;
use App\Modules\Search\Contracts\IndexableProductSource;
use App\Modules\Search\Contracts\SearchIndex;
use App\Modules\Sellers\Events\SellerApproved;
use App\Modules\Sellers\Listeners\NotifyApprovedSeller;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

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

        $this->app->bind(PaymentGateway::class, function (): PaymentGateway {
            return match (config('veritas.providers.payment')) {
                default => new FakePaymentGateway,
            };
        });
    }

    public function boot(): void
    {
        // Domain events are wired here rather than discovered, so the set
        // of listeners is readable in one place.
        Event::listen(SellerApproved::class, NotifyApprovedSeller::class);

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

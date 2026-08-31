<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\FakePaymentGateway;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The domain depends on the port, never on a vendor SDK. M0 binds
        // the fake driver; the Stripe driver is registered here in M3
        // without a single change inside the modules.
        $this->app->bind(PaymentGateway::class, function (): PaymentGateway {
            return match (config('veritas.providers.payment')) {
                default => new FakePaymentGateway,
            };
        });
    }

    public function boot(): void
    {
        // Models live in module namespaces (App\Modules\Orders\Models\...),
        // so the default factory guess does not apply. Factories stay in one
        // flat namespace keyed by class basename.
        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        Factory::guessModelNamesUsing(static function (Factory $factory): string {
            throw new RuntimeException(
                'Declare $model explicitly on '.$factory::class.'; module namespaces cannot be guessed.'
            );
        });

        // A lazy load is a performance bug that reaches production as an N+1.
        // Failing loudly outside production is how it gets caught in review.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\FakePaymentGateway;
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

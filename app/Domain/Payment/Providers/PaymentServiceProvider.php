<?php

declare(strict_types=1);

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Actions\CreateSubscriptionAction;
use App\Domain\Payment\Actions\ProcessCheckoutSessionAction;
use App\Domain\Payment\Services\ContractPaymentService;
use App\Domain\Payment\Services\CreatorBalanceService;
use App\Domain\Payment\Services\PaymentMethodService;
use App\Domain\Payment\Services\StripeCustomerService;
use App\Domain\Payment\Services\SubscriptionService;
use App\Wrappers\StripeWrapper;
use Illuminate\Support\ServiceProvider;

/**
 * PaymentServiceProvider registers payment-related services and actions.
 */
class PaymentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerServices();
        $this->registerActions();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {}

    /**
     * Register payment services.
     */
    private function registerServices(): void
    {
        // Register StripeCustomerService
        $this->app->singleton(StripeCustomerService::class, fn ($app) => new StripeCustomerService(
            $app->make(StripeWrapper::class)
        ));

        // Register PaymentMethodService
        $this->app->singleton(PaymentMethodService::class, fn ($app) => new PaymentMethodService(
            $app->make(StripeWrapper::class),
            $app->make(StripeCustomerService::class)
        ));

        // Register SubscriptionService
        $this->app->singleton(SubscriptionService::class, fn ($app) => new SubscriptionService(
            $app->make(StripeWrapper::class),
            $app->make(StripeCustomerService::class)
        ));

        // Register ContractPaymentService
        $this->app->singleton(ContractPaymentService::class, fn ($app) => new ContractPaymentService(
            $app->make(StripeWrapper::class),
            $app->make(StripeCustomerService::class)
        ));

        // Register CreatorBalanceService
        $this->app->singleton(CreatorBalanceService::class, fn ($app) => new CreatorBalanceService(
            $app->make(StripeWrapper::class)
        ));
    }

    /**
     * Register payment actions.
     */
    private function registerActions(): void
    {
        // Register CreateSubscriptionAction
        $this->app->bind(CreateSubscriptionAction::class, fn ($app) => new CreateSubscriptionAction(
            $app->make(StripeWrapper::class),
            $app->make(StripeCustomerService::class)
        ));

        // Register ProcessCheckoutSessionAction
        $this->app->bind(ProcessCheckoutSessionAction::class, fn ($app) => new ProcessCheckoutSessionAction(
            $app->make(StripeWrapper::class)
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Contract\Actions\CompleteContractAction;
use App\Domain\Contract\Repositories\ContractRepository;
use App\Domain\Payment\Actions\CreateWithdrawalAction;
use App\Domain\Payment\Actions\ProcessWithdrawalAction;
use App\Domain\Payment\Repositories\TransactionRepository;
use App\Domain\Payment\Repositories\WithdrawalRepository;
use Illuminate\Support\ServiceProvider;

/**
 * DomainServiceProvider registers all Domain-layer services.
 *
 * This provider centralizes the registration of:
 * - Repositories
 * - Actions
 * - Domain Services
 *
 * By using this provider, we ensure proper dependency injection
 * and make testing easier with mock implementations.
 */
class DomainServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register Repositories as singletons
        $this->app->singleton(WithdrawalRepository::class);
        $this->app->singleton(ContractRepository::class);
        $this->app->singleton(TransactionRepository::class);

        // Register Actions (not singletons - stateless per request)
        $this->app->bind(CreateWithdrawalAction::class);
        $this->app->bind(ProcessWithdrawalAction::class);
        $this->app->bind(CompleteContractAction::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Contract\Providers;

use App\Domain\Contract\Services\ContractWorkflowService;
use Illuminate\Support\ServiceProvider;

/**
 * ContractServiceProvider registers contract-related services.
 */
class ContractServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ContractWorkflowService::class, fn () => new ContractWorkflowService());
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Providers;

use App\Domain\Campaign\Services\CampaignApplicationService;
use App\Domain\Campaign\Services\CampaignFileService;
use App\Domain\Campaign\Services\CampaignRefundService;
use App\Domain\Campaign\Services\CampaignSearchService;
use Illuminate\Support\ServiceProvider;

/**
 * CampaignServiceProvider registers campaign-related services.
 */
class CampaignServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(CampaignFileService::class, fn () => new CampaignFileService());
        $this->app->singleton(CampaignRefundService::class, fn () => new CampaignRefundService());
        $this->app->singleton(CampaignSearchService::class, fn () => new CampaignSearchService());
        $this->app->singleton(CampaignApplicationService::class, fn () => new CampaignApplicationService());
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {}
}

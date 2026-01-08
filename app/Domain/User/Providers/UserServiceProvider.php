<?php

declare(strict_types=1);

namespace App\Domain\User\Providers;

use App\Domain\User\Services\PortfolioService;
use App\Domain\User\Services\UserProfileService;
use Illuminate\Support\ServiceProvider;

/**
 * UserServiceProvider registers user-related services.
 */
class UserServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(UserProfileService::class, fn () => new UserProfileService());

        $this->app->singleton(PortfolioService::class, fn () => new PortfolioService());
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Chat\Providers;

use App\Domain\Chat\Services\ChatService;
use Illuminate\Support\ServiceProvider;

/**
 * ChatServiceProvider registers chat-related services.
 */
class ChatServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ChatService::class, fn () => new ChatService());
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {}
}

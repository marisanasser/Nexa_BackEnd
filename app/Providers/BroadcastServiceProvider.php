<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Common\BroadcastController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::group(['prefix' => 'api', 'middleware' => ['auth:sanctum']], function (): void {
            Route::match(['get', 'post'], '/broadcasting/auth', [BroadcastController::class, 'authenticate']);
            Route::match(['get', 'post'], '/broadcasting/user-auth', [BroadcastController::class, 'authenticateUser']);
        });

        require base_path('routes/channels.php');
    }
}

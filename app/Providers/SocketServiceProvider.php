<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class SocketServiceProvider extends ServiceProvider
{
    
    public function register(): void
    {
        
        $this->app->singleton('socket.server', function ($app) {
            
            
            if (isset($GLOBALS['socket_server'])) {
                return $GLOBALS['socket_server'];
            }
            
            
            return null;
        });
    }

    
    public function boot(): void
    {
        
    }
} 
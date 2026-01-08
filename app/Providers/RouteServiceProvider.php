<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Log;

class RouteServiceProvider extends ServiceProvider
{
    public const string HOME = '/dashboard';

    public function boot(): void
    {
        RateLimiter::for('new-user-flow', function (Request $request) {
            return Limit::perMinute(25)->by($request->ip())->response(function () use ($request) {
                Log::info('New user flow rate limited', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'attempts_allowed' => 25,
                    'lockout_minutes' => 5,
                ]);

                return response()->json([
                    'message' => 'Muitas tentativas de criação de conta. Tente novamente em alguns instantes.',
                    'retry_after' => 300,
                    'error_type' => 'new_user_flow_rate_limited',
                ], 429);
            });
        });

        RateLimiter::for('auth', function (Request $request) {
            $config = config('rate_limiting.auth.login');

            return Limit::perMinute($config['attempts'])
                ->by($request->ip())
                ->response(function () use ($config, $request) {
                    Log::info('Route-level auth rate limited', [
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'attempts_allowed' => $config['attempts'],
                        'lockout_minutes' => $config['lockout_minutes'],
                    ]);

                    return response()->json([
                        'message' => config('rate_limiting.messages.auth.login'),
                        'retry_after' => $config['lockout_minutes'] * 60,
                        'error_type' => 'auth_rate_limited',
                    ], 429);
                })
            ;
        });

        RateLimiter::for('registration', function (Request $request) {
            $config = config('rate_limiting.auth.registration');

            return Limit::perMinute($config['attempts'])
                ->by($request->ip())
                ->response(function () use ($config, $request) {
                    Log::info('Route-level registration rate limited', [
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'attempts_allowed' => $config['attempts'],
                        'lockout_minutes' => $config['lockout_minutes'],
                    ]);

                    return response()->json([
                        'message' => config('rate_limiting.messages.auth.registration'),
                        'retry_after' => $config['lockout_minutes'] * 60,
                        'error_type' => 'registration_rate_limited',
                    ], 429);
                })
            ;
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $config = config('rate_limiting.auth.password_reset');

            return Limit::perMinute($config['attempts'])
                ->by($request->ip())
                ->response(fn () => response()->json([
                    'message' => config('rate_limiting.messages.auth.password_reset'),
                    'retry_after' => $config['lockout_minutes'] * 60,
                    'error_type' => 'password_reset_rate_limited',
                ], 429))
            ;
        });

        RateLimiter::for('api', function (Request $request) {
            $config = config('rate_limiting.api.general');

            return Limit::perMinute($config['attempts'])
                ->by($request->user()?->id ?: $request->ip())
            ;
        });

        RateLimiter::for('dashboard', fn (Request $request) => Limit::perMinute(200)
            ->by($request->user()?->id ?: $request->ip())
            ->response(fn () => response()->json([
                'message' => 'Dashboard rate limit exceeded. Please wait a moment.',
                'retry_after' => 60,
                'error_type' => 'dashboard_rate_limited',
            ], 429)));

        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->id ?: $request->ip())
            ->response(fn () => response()->json([
                'message' => 'Chat rate limit exceeded. Please wait a moment.',
                'retry_after' => 60,
                'error_type' => 'chat_rate_limited',
            ], 429)));

        RateLimiter::for('notifications', function (Request $request) {
            $config = config('rate_limiting.api.notifications');

            return Limit::perMinute($config['attempts'])
                ->by($request->user()?->id ?: $request->ip())
            ;
        });

        RateLimiter::for('user-status', function (Request $request) {
            $config = config('rate_limiting.api.user_status');

            return Limit::perMinute($config['attempts'])
                ->by($request->user()?->id ?: $request->ip())
            ;
        });

        RateLimiter::for('payment', function (Request $request) {
            $config = config('rate_limiting.api.payment');

            return Limit::perMinute($config['attempts'])
                ->by($request->user()?->id ?: $request->ip())
            ;
        });

        $this->routes(function (): void {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'))
            ;

            Route::middleware('web')
                ->group(base_path('routes/web.php'))
            ;
        });
    }
}

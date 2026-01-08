<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('rate_limiting.include_headers', true)) {
            $this->addRateLimitHeaders($request, $response);
        }

        return $response;
    }

    private function addRateLimitHeaders(Request $request, Response $response): void
    {
        $route = $request->route();
        if (!$route) {
            return;
        }

        $middleware = $route->gatherMiddleware();
        $throttleMiddleware = collect($middleware)->first(fn($middleware) => str_starts_with($middleware, 'throttle:'));

        if (!$throttleMiddleware) {
            return;
        }

        $throttleName = str_replace('throttle:', '', $throttleMiddleware);

        $key = $this->getThrottleKey($request, $throttleName);
        if ($key) {
            $remaining = RateLimiter::remaining($key, $this->getMaxAttempts($throttleName));
            $retryAfter = RateLimiter::availableIn($key);

            if (null !== $remaining) {
                $response->headers->set('X-RateLimit-Limit', (string) $this->getMaxAttempts($throttleName));
                $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining));

                if ($retryAfter > 0) {
                    $response->headers->set('X-RateLimit-Reset', (string) (time() + $retryAfter));
                    $response->headers->set('Retry-After', (string) $retryAfter);
                }
            }
        }
    }

    private function getThrottleKey(Request $request, string $throttleName): ?string
    {
        switch ($throttleName) {
            case 'auth':
            case 'registration':
            case 'password-reset':
                return 'throttle:' . $throttleName . ':' . $request->ip();

            case 'api':
                return 'throttle:api:' . ($request->user()?->id ?: $request->ip());

            case 'notifications':
                return 'throttle:notifications:' . ($request->user()?->id ?: $request->ip());

            case 'user-status':
                return 'throttle:user-status:' . ($request->user()?->id ?: $request->ip());

            case 'payment':
                return 'throttle:payment:' . ($request->user()?->id ?: $request->ip());

            default:
                return null;
        }
    }

    private function getMaxAttempts(string $throttleName): int
    {
        switch ($throttleName) {
            case 'auth':
                return config('rate_limiting.auth.login.attempts', 20);

            case 'registration':
                return config('rate_limiting.auth.registration.attempts', 10);

            case 'password-reset':
                return config('rate_limiting.auth.password_reset.attempts', 5);

            case 'api':
                return config('rate_limiting.api.general.attempts', 1000);

            case 'notifications':
                return config('rate_limiting.api.notifications.attempts', 300);

            case 'user-status':
                return config('rate_limiting.api.user_status.attempts', 600);

            case 'payment':
                return config('rate_limiting.api.payment.attempts', 100);

            default:
                return 60;
        }
    }
}

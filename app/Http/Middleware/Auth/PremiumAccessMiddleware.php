<?php

declare(strict_types=1);

namespace App\Http\Middleware\Auth;

use App\Domain\Shared\Traits\HasAuthenticatedUser;
use Closure;
use Illuminate\Http\Request;
use Log;
use Symfony\Component\HttpFoundation\Response;

class PremiumAccessMiddleware
{
    use HasAuthenticatedUser;
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->getAuthenticatedUser();

        if (!$user) {
            Log::warning('PremiumAccessMiddleware: No authenticated user found');

            return $next($request);
        }

        Log::info('PremiumAccessMiddleware Debug', [
            'path' => $request->path(),
            'method' => $request->method(),
            'hasUser' => (bool) $user,
            'userId' => $user?->id,
            'userRole' => $user?->role,
            'hasPremium' => $user?->has_premium,
            'premiumExpiresAt' => $user?->premium_expires_at,
            'hasPremiumAccess' => $user?->hasPremiumAccess(),
            'authorizationHeader' => $request->header('Authorization') ? 'present' : 'missing',
        ]);

        if (('admin' === $user->role) || $user->isAdmin()) {
            Log::info('PremiumAccessMiddleware: Admin user bypassing all premium checks', [
                'userId' => $user->id,
                'role' => $user->role,
                'roleDirectCheck' => ('admin' === $user->role),
                'isAdminMethodCheck' => $user->isAdmin(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return $next($request);
        }

        if (!$user->isCreator() && !$user->isStudent()) {
            Log::info('PremiumAccessMiddleware: User is not a creator or student, allowing access', [
                'role' => $user->role,
                'path' => $request->path(),
            ]);

            return $next($request);
        }

        $currentPath = $request->path();
        $method = $request->method();

        $premiumRequiredPaths = [
            'api/connections',
            'api/direct-chat',
        ];

        $requiresPremium = false;
        foreach ($premiumRequiredPaths as $premiumPath) {
            if (str_starts_with($currentPath, $premiumPath)) {
                if ('GET' === $method) {
                    Log::info('PremiumAccessMiddleware: Allowing GET request without premium', [
                        'path' => $currentPath,
                        'method' => $method,
                        'userId' => $user->id,
                    ]);

                    return $next($request);
                }
                $requiresPremium = true;

                break;
            }
        }

        if (str_starts_with($currentPath, 'api/campaigns')) {
            if ('GET' === $method) {
                Log::info('PremiumAccessMiddleware: Allowing campaign viewing without premium', [
                    'path' => $currentPath,
                    'method' => $method,
                    'userId' => $user->id,
                ]);

                return $next($request);
            }

            $premiumCampaignPaths = [
                'api/campaigns/{campaign}/applications',
                'api/campaigns/{campaign}/bids',
            ];

            $isPremiumAction = false;
            foreach ($premiumCampaignPaths as $premiumPath) {
                $pattern = str_replace('{campaign}', '[^/]+', $premiumPath);
                if (preg_match('#'.$pattern.'#', $currentPath)) {
                    $isPremiumAction = true;

                    break;
                }
            }

            if ($isPremiumAction) {
                $requiresPremium = true;
            }
        }

        if (str_starts_with($currentPath, 'api/applications')) {
            if ('GET' === $method) {
                Log::info('PremiumAccessMiddleware: Allowing application viewing without premium', [
                    'path' => $currentPath,
                    'method' => $method,
                    'userId' => $user->id,
                ]);

                return $next($request);
            }

            $requiresPremium = true;
        }

        if (str_starts_with($currentPath, 'api/bids')) {
            if ('GET' === $method) {
                Log::info('PremiumAccessMiddleware: Allowing bid viewing without premium', [
                    'path' => $currentPath,
                    'method' => $method,
                    'userId' => $user->id,
                ]);

                return $next($request);
            }

            $requiresPremium = true;
        }

        if (!$requiresPremium) {
            Log::info('PremiumAccessMiddleware: Path does not require premium, allowing access', [
                'path' => $currentPath,
                'userId' => $user->id,
            ]);

            return $next($request);
        }

        if (!$user->hasPremiumAccess()) {
            Log::warning('PremiumAccessMiddleware: Creator without premium access blocked from premium feature', [
                'userId' => $user->id,
                'path' => $currentPath,
                'hasPremium' => $user->has_premium,
                'premiumExpiresAt' => $user->premium_expires_at,
                'hasPremiumAccess' => $user->hasPremiumAccess(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Premium subscription required for this feature',
                'error' => 'premium_required',
                'redirect_to' => '/subscription',
                'user' => [
                    'has_premium' => $user->has_premium,
                    'premium_expires_at' => $user->premium_expires_at?->format('Y-m-d H:i:s'),
                ],
            ], 403);
        }

        Log::info('PremiumAccessMiddleware: Creator with premium access allowed', [
            'userId' => $user->id,
            'path' => $currentPath,
        ]);

        return $next($request);
    }
}

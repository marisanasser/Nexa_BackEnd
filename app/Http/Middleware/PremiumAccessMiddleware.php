<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class PremiumAccessMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        
        // If not authenticated, let the auth middleware handle it
        if (!$user) {
            Log::warning('PremiumAccessMiddleware: No authenticated user found');
            return $next($request);
        }

        // Debug logging
        Log::info('PremiumAccessMiddleware Debug', [
            'path' => $request->path(),
            'method' => $request->method(),
            'hasUser' => !!$user,
            'userId' => $user?->id,
            'userRole' => $user?->role,
            'hasPremium' => $user?->has_premium,
            'premiumExpiresAt' => $user?->premium_expires_at,
            'hasPremiumAccess' => $user?->hasPremiumAccess(),
            'authorizationHeader' => $request->header('Authorization') ? 'present' : 'missing'
        ]);

        // Admins bypass all premium checks - CRITICAL: Must be first check
        if (($user->role === 'admin') || $user->isAdmin()) {
            Log::info('PremiumAccessMiddleware: Admin user bypassing all premium checks', [
                'userId' => $user->id,
                'role' => $user->role,
                'roleDirectCheck' => ($user->role === 'admin'),
                'isAdminMethodCheck' => $user->isAdmin(),
                'path' => $request->path(),
                'method' => $request->method()
            ]);
            return $next($request);
        }

        // Only apply to creators and students
        if (!$user->isCreator() && !$user->isStudent()) {
            Log::info('PremiumAccessMiddleware: User is not a creator or student, allowing access', [
                'role' => $user->role,
                'path' => $request->path()
            ]);
            return $next($request);
        }

        // For creators and students, check if they have premium access for restricted features
        // Get the current path
        $currentPath = $request->path();
        $method = $request->method();
        
        // Define paths that require premium for creators (only for POST/PATCH operations)
        $premiumRequiredPaths = [
            'api/connections', // Connection requests
            'api/direct-chat', // Direct messaging
            // Note: Portfolio management is now allowed for all creators
        ];
        
        // Check if current path requires premium
        $requiresPremium = false;
        foreach ($premiumRequiredPaths as $premiumPath) {
            if (str_starts_with($currentPath, $premiumPath)) {
                $requiresPremium = true;
                break;
            }
        }
        
        // For campaigns, only require premium for premium actions (not viewing)
        if (str_starts_with($currentPath, 'api/campaigns')) {
            // Allow GET requests (viewing campaigns) for all users
            if ($method === 'GET') {
                Log::info('PremiumAccessMiddleware: Allowing campaign viewing without premium', [
                    'path' => $currentPath,
                    'method' => $method,
                    'userId' => $user->id
                ]);
                return $next($request);
            }
            
            // Check if this is a premium-required campaign action
            $premiumCampaignPaths = [
                'api/campaigns/{campaign}/applications', // Apply to campaign
                'api/campaigns/{campaign}/bids',        // Create bid
            ];
            
            $isPremiumAction = false;
            foreach ($premiumCampaignPaths as $premiumPath) {
                // Remove {campaign} pattern for matching
                $pattern = str_replace('{campaign}', '[^/]+', $premiumPath);
                if (preg_match('#' . $pattern . '#', $currentPath)) {
                    $isPremiumAction = true;
                    break;
                }
            }
            
            // If it's a premium action, check premium access
            if ($isPremiumAction) {
                $requiresPremium = true;
            }
        }
        
        // If path doesn't require premium, allow access
        if (!$requiresPremium) {
            Log::info('PremiumAccessMiddleware: Path does not require premium, allowing access', [
                'path' => $currentPath,
                'userId' => $user->id
            ]);
            return $next($request);
        }
        
        // Check if user has premium access for premium-required features
        if (!$user->hasPremiumAccess()) {
            Log::warning('PremiumAccessMiddleware: Creator without premium access blocked from premium feature', [
                'userId' => $user->id,
                'path' => $currentPath,
                'hasPremium' => $user->has_premium,
                'premiumExpiresAt' => $user->premium_expires_at,
                'hasPremiumAccess' => $user->hasPremiumAccess()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Premium subscription required for this feature',
                'error' => 'premium_required',
                'redirect_to' => '/subscription',
                'user' => [
                    'has_premium' => $user->has_premium,
                    'premium_expires_at' => $user->premium_expires_at?->format('Y-m-d H:i:s'),
                ]
            ], 403);
        }

        Log::info('PremiumAccessMiddleware: Creator with premium access allowed', [
            'userId' => $user->id,
            'path' => $currentPath
        ]);

        return $next($request);
    }
} 
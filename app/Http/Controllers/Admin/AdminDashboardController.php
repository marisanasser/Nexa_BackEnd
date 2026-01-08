<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Models\Campaign\Campaign;
use App\Models\User\User;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * AdminDashboardController handles admin dashboard metrics and overview data.
 *
 * Extracted from the monolithic AdminController for better separation of concerns.
 */
class AdminDashboardController extends Controller
{
    use HasAuthenticatedUser;

    /**
     * Get dashboard metrics for the admin panel.
     */
    public function getMetrics(): JsonResponse
    {
        try {
            $metrics = [
                'pendingCampaignsCount' => Campaign::where('status', 'pending')->count(),
                'allActiveCampaignCount' => Campaign::where('is_active', true)->count(),
                'allRejectCampaignCount' => Campaign::where('status', 'rejected')->count(),
                'allUserCount' => User::whereNotIn('role', ['admin'])->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $metrics,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard metrics: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get pending campaigns for dashboard widget.
     */
    public function getPendingCampaigns(): JsonResponse
    {
        try {
            $campaigns = Campaign::with('brand')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(fn ($campaign) => [
                    'id' => $campaign->id,
                    'title' => $campaign->title,
                    'brand' => $campaign->brand->company_name ?: $campaign->brand->name,
                    'type' => $campaign->campaign_type ?: 'Vídeo',
                    'value' => $campaign->budget ? (float) $campaign->budget : 0,
                ])
            ;

            return response()->json([
                'success' => true,
                'data' => $campaigns,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending campaigns: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get recent users for dashboard widget.
     */
    public function getRecentUsers(): JsonResponse
    {
        try {
            $users = User::whereNotIn('role', ['admin'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($user) {
                    $daysAgo = $user->created_at->diffInDays(now());

                    $roleDisplay = match ($user->role) {
                        'brand' => 'Marca',
                        'creator' => 'Criador',
                        default => 'Usuário'
                    };

                    $tag = match ($user->role) {
                        'brand' => 'Marca',
                        'creator' => 'Criador',
                        default => 'Usuário'
                    };

                    if ($user->has_premium) {
                        $tag = 'Pagante';
                    }

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'role' => $roleDisplay,
                        'registeredDaysAgo' => $daysAgo,
                        'tag' => $tag,
                    ];
                })
            ;

            return response()->json([
                'success' => true,
                'data' => $users,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent users: '.$e->getMessage(),
            ], 500);
        }
    }
}

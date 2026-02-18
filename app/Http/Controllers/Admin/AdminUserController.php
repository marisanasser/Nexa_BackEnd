<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Models\User\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminUserController handles admin user management operations.
 *
 * Extracted from the monolithic AdminController for better separation of concerns.
 */
class AdminUserController extends Controller
{
    use HasAuthenticatedUser;

    /**
     * Get paginated list of users with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'role' => 'nullable|in:creator,brand,admin',
            'status' => 'nullable|in:active,blocked,removed,pending,unverified',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $role = $request->input('role');
        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $query = User::query();

        if ($role) {
            $query->where('role', $role);
        }

        if ($status) {
            $this->applyStatusFilter($query, $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                ;
            });
        }

        $users = $query->withCount([
            'campaignApplications as applied_campaigns',
            'campaignApplications as approved_campaigns' => function ($q): void {
                $q->where('status', 'approved');
            },
            'campaigns as created_campaigns',
        ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page)
        ;

        $transformedUsers = collect($users->items())
            ->map(fn ($user) => $this->transformUserData($user))
            ->values();

        return response()->json([
            'success' => true,
            // Keep nested pagination for backward compatibility with existing admin frontend.
            'data' => [
                'data' => $transformedUsers,
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
            // Also expose top-level pagination for newer consumers.
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }

    /**
     * Get paginated list of creators only.
     */
    public function getCreators(Request $request): JsonResponse
    {
        $request->merge(['role' => 'creator']);

        return $this->index($request);
    }

    /**
     * Get paginated list of brands only.
     */
    public function getBrands(Request $request): JsonResponse
    {
        $request->merge(['role' => 'brand']);

        return $this->index($request);
    }

    /**
     * Get user statistics for admin dashboard.
     */
    public function getStatistics(): JsonResponse
    {
        $stats = [
            'total_users' => User::count(),
            'total_creators' => User::where('role', 'creator')->count(),
            'total_brands' => User::where('role', 'brand')->count(),
            'active_users' => User::whereNotNull('email_verified_at')->count(),
            'blocked_users' => User::whereNull('email_verified_at')->where('created_at', '<', now()->subDays(30))->count(), // Example logic for blocked
            'pending_verification' => User::whereNull('email_verified_at')->where('created_at', '>=', now()->subDays(30))->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Update user status (activate, block, remove).
     */
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:activate,block,remove',
        ]);

        $action = $request->input('action');

        try {
            $message = match ($action) {
                'activate' => $this->activateUser($user),
                'block' => $this->blockUser($user),
                'remove' => $this->removeUser($user),
                default => throw new Exception('Invalid action'),
            };

            return response()->json([
                'success' => true,
                'message' => $message,
                'user' => $this->transformUserData($user->fresh()),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Apply status filter to query.
     *
     * @param mixed $query
     */
    private function applyStatusFilter($query, string $status): void
    {
        match ($status) {
            'active' => $query->where('email_verified_at', '!=', null),
            'blocked' => $query->where('email_verified_at', '=', null),
            'removed' => $query->where('deleted_at', '!=', null),
            'pending' => $query->where('email_verified_at', '=', null),
            'unverified' => $query->where('email_verified_at', '=', null),
            default => null,
        };
    }

    /**
     * Activate a user.
     */
    private function activateUser(User $user): string
    {
        $user->update(['email_verified_at' => now()]);

        return 'User activated successfully';
    }

    /**
     * Block a user.
     */
    private function blockUser(User $user): string
    {
        $user->update(['email_verified_at' => null]);

        return 'User blocked successfully';
    }

    /**
     * Remove a user.
     */
    private function removeUser(User $user): string
    {
        $user->delete();

        return 'User removed successfully';
    }

    /**
     * Transform user data for API response.
     */
    private function transformUserData(User $user): array
    {
        $isCreator = 'creator' === $user->role;
        $accountStatus = $this->getAccountStatus($user);
        $isActive = null !== $user->email_verified_at && 'Removido' !== $accountStatus;
        $timeOnPlatform = $this->getUserTimeStatus($user);
        $displayName = $user->company_name ?: $user->name;
        $profileImage = $user->avatar ?: $user->avatar_url;

        if ($isCreator) {
            $status = 'Criador';
            $statusColor = 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-200';

            if ($user->has_premium) {
                $status = 'Pagante';
                $statusColor = 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-200';
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'email' => $user->email,
                'profile_image' => $profileImage,
                'is_active' => $isActive,
                'last_login_at' => null,
                'status' => $status,
                'statusColor' => $statusColor,
                'time' => $timeOnPlatform,
                'time_on_platform' => $timeOnPlatform,
                'campaigns' => ($user->applied_campaigns ?? 0).' aplicadas / '.($user->approved_campaigns ?? 0).' aprovadas',
                'accountStatus' => $accountStatus,
                'account_status' => $accountStatus,
                'created_at' => $user->created_at,
                'email_verified_at' => $user->email_verified_at,
                'total_campaigns' => (int) ($user->created_campaigns ?? 0),
                'total_applications' => (int) ($user->applied_campaigns ?? 0),
                'company_name' => null,
                'has_premium' => $user->has_premium,
                'student_verified' => $user->student_verified,
                'premium_expires_at' => $user->premium_expires_at,
                'free_trial_expires_at' => $user->free_trial_expires_at,
            ];
        }

        // Brand user
        $status = 'Marca';
        $statusColor = 'bg-purple-100 text-purple-600 dark:bg-purple-900 dark:text-purple-200';

        if ($user->has_premium) {
            $status = 'Pagante';
            $statusColor = 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-200';
        }

        return [
            'id' => $user->id,
            'name' => $displayName,
            'role' => $user->role,
            'company' => $user->company_name ?: $user->name,
            'brandName' => $user->company_name ?: $user->name,
            'company_name' => $user->company_name ?: $user->name,
            'email' => $user->email,
            'profile_image' => $profileImage,
            'is_active' => $isActive,
            'last_login_at' => null,
            'status' => $status,
            'statusColor' => $statusColor,
            'time' => $timeOnPlatform,
            'time_on_platform' => $timeOnPlatform,
            'campaigns' => $user->created_campaigns,
            'accountStatus' => $accountStatus,
            'account_status' => $accountStatus,
            'created_at' => $user->created_at,
            'email_verified_at' => $user->email_verified_at,
            'total_campaigns' => (int) ($user->created_campaigns ?? 0),
            'total_applications' => (int) ($user->applied_campaigns ?? 0),
            'has_premium' => $user->has_premium,
            'premium_expires_at' => $user->premium_expires_at,
            'free_trial_expires_at' => $user->free_trial_expires_at,
        ];
    }

    /**
     * Get user time status string.
     */
    private function getUserTimeStatus(User $user): string
    {
        if ($user->has_premium && null === $user->premium_expires_at) {
            return 'Ilimitado';
        }

        if ($user->has_premium && $user->premium_expires_at) {
            $premiumExpiresAt = $user->premium_expires_at instanceof Carbon
                ? $user->premium_expires_at
                : Carbon::parse($user->premium_expires_at);
            $months = $premiumExpiresAt->diffInMonths(now());

            return $months.' meses';
        }

        if ($user->free_trial_expires_at) {
            $trialExpiresAt = $user->free_trial_expires_at instanceof Carbon
                ? $user->free_trial_expires_at
                : Carbon::parse($user->free_trial_expires_at);
            $months = $trialExpiresAt->diffInMonths(now());

            return $months.' meses';
        }

        $months = $user->created_at->diffInMonths(now());

        return $months.' meses';
    }

    /**
     * Get account status string.
     */
    private function getAccountStatus(User $user): string
    {
        if ($user->deleted_at) {
            return 'Removido';
        }

        if ($user->email_verified_at) {
            return 'Ativo';
        }

        if ($user->created_at->diffInDays(now()) > 30) {
            return 'Bloqueado';
        }

        return 'Pendente';
    }
}

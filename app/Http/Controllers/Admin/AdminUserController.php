<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Services\UserNotificationService;
use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Models\User\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AdminUserController handles admin user management operations.
 *
 * Extracted from the monolithic AdminController for better separation of concerns.
 */
class AdminUserController extends Controller
{
    use HasAuthenticatedUser;

    private const ROLE_CONVERSION_CONFIRMATION = 'CONVERTER';
    private const ROLE_CONVERSION_ALLOWED_ROLES = ['creator', 'brand'];

    /**
     * Get paginated list of users with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'role' => 'nullable|in:creator,brand,admin,student',
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
            'subscriptions as active_subscriptions_count' => function ($subscriptionQuery): void {
                $this->applyActiveSubscriptionFilter($subscriptionQuery);
            },
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
        $wasActive = null !== $user->email_verified_at;

        try {
            $message = match ($action) {
                'activate' => $this->activateUser($user),
                'block' => $this->blockUser($user),
                'remove' => $this->removeUser($user),
                default => throw new Exception('Invalid action'),
            };

            if ('activate' === $action && !$wasActive) {
                UserNotificationService::notifyUserOfProfileApproval($user->fresh() ?? $user);
            }

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
     * Preview impact for a role conversion request.
     */
    public function getRoleConversionImpact(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'target_role' => 'required|in:creator,brand',
        ]);

        $targetRole = (string) $request->input('target_role');
        $impact = $this->buildRoleConversionImpact($user, $targetRole);

        return response()->json([
            'success' => true,
            'data' => $impact,
        ]);
    }

    /**
     * Convert account role with strict safety guards.
     */
    public function convertRole(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'target_role' => 'required|in:creator,brand',
            'confirmation' => 'required|string|max:32',
        ]);

        $targetRole = (string) $request->input('target_role');
        $confirmation = trim((string) $request->input('confirmation'));
        $impact = $this->buildRoleConversionImpact($user, $targetRole);

        if (self::ROLE_CONVERSION_CONFIRMATION !== $confirmation) {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation keyword is invalid.',
                'expected_confirmation' => self::ROLE_CONVERSION_CONFIRMATION,
            ], 422);
        }

        if (!$impact['can_convert']) {
            return response()->json([
                'success' => false,
                'message' => 'Role conversion is blocked due to linked data or policy restrictions.',
                'data' => $impact,
            ], 422);
        }

        $admin = $this->getAuthenticatedUser();
        $fromRole = (string) $user->role;

        $updateData = ['role' => $targetRole];

        // Avoid legacy student flags leaking into brand behavior checks.
        if ('brand' === $targetRole) {
            $updateData['student_verified'] = false;
            $updateData['student_expires_at'] = null;
            $updateData['free_trial_expires_at'] = null;
        }

        $user->update($updateData);

        Log::warning('Admin converted account role', [
            'admin_id' => $admin?->id,
            'admin_email' => $admin?->email,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'from_role' => $fromRole,
            'to_role' => $targetRole,
            'impact_snapshot' => $impact,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "User role converted from {$fromRole} to {$targetRole} successfully.",
            'user' => $this->transformUserData($user->fresh()),
        ]);
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
     * Build role conversion impact, blockers, and linked record snapshot.
     */
    private function buildRoleConversionImpact(User $user, string $targetRole): array
    {
        $sourceRole = (string) $user->role;
        $linkedRecords = $this->collectLinkedRecords($user);
        $nonZeroLinks = array_filter(
            $linkedRecords,
            static fn (int $count): bool => $count > 0
        );

        $blockers = [];

        if ($user->trashed()) {
            $blockers[] = [
                'code' => 'removed_account',
                'message' => 'Removed accounts cannot be converted.',
            ];
        }

        if ($user->isAdmin()) {
            $blockers[] = [
                'code' => 'admin_account_protected',
                'message' => 'Admin accounts cannot be converted.',
            ];
        }

        if (!in_array($sourceRole, self::ROLE_CONVERSION_ALLOWED_ROLES, true)) {
            $blockers[] = [
                'code' => 'unsupported_source_role',
                'message' => "Source role '{$sourceRole}' is not eligible for direct conversion.",
            ];
        }

        if (!in_array($targetRole, self::ROLE_CONVERSION_ALLOWED_ROLES, true)) {
            $blockers[] = [
                'code' => 'unsupported_target_role',
                'message' => "Target role '{$targetRole}' is not allowed.",
            ];
        }

        if ($sourceRole === $targetRole) {
            $blockers[] = [
                'code' => 'same_role',
                'message' => 'Source role and target role are the same.',
            ];
        }

        if (!empty($nonZeroLinks)) {
            $blockers[] = [
                'code' => 'linked_records_found',
                'message' => 'Account has linked operational records and cannot be converted automatically.',
                'details' => $nonZeroLinks,
            ];
        }

        $canConvert = 0 === count($blockers);
        $riskLevel = $canConvert ? 'low' : (!empty($nonZeroLinks) ? 'critical' : 'high');

        return [
            'user_id' => $user->id,
            'source_role' => $sourceRole,
            'target_role' => $targetRole,
            'can_convert' => $canConvert,
            'risk_level' => $riskLevel,
            'confirmation_keyword' => self::ROLE_CONVERSION_CONFIRMATION,
            'blockers' => $blockers,
            'linked_records' => $linkedRecords,
            'summary' => [
                'total_linked_records' => array_sum($linkedRecords),
                'non_zero_links' => $nonZeroLinks,
            ],
        ];
    }

    /**
     * Snapshot of linked records that can be impacted by role conversion.
     *
     * @return array<string, int>
     */
    private function collectLinkedRecords(User $user): array
    {
        return [
            // Brand side
            'brand_campaigns' => $user->campaigns()->count(),
            'brand_sent_offers' => $user->sentOffers()->count(),
            'brand_contracts' => $user->brandContracts()->count(),
            'brand_job_payments' => $user->brandPayments()->count(),
            'brand_chat_rooms' => $user->brandChatRooms()->count(),
            'brand_direct_chat_rooms' => $user->brandDirectChatRooms()->count(),
            'brand_payment_methods' => $user->brandPaymentMethods()->count(),
            'brand_balance_record' => $user->brandBalance()->exists() ? 1 : 0,

            // Creator side
            'creator_bids' => $user->bids()->count(),
            'creator_campaign_applications' => $user->campaignApplications()->count(),
            'creator_favorites' => $user->favorites()->count(),
            'creator_received_offers' => $user->receivedOffers()->count(),
            'creator_contracts' => $user->creatorContracts()->count(),
            'creator_job_payments' => $user->creatorPayments()->count(),
            'creator_withdrawals' => $user->withdrawals()->count(),
            'creator_balance_record' => $user->creatorBalance()->exists() ? 1 : 0,
            'creator_portfolio' => $user->portfolio()->exists() ? 1 : 0,
            'creator_chat_rooms' => $user->creatorChatRooms()->count(),
            'creator_direct_chat_rooms' => $user->creatorDirectChatRooms()->count(),

            // Shared financial/subscription history
            'active_subscriptions' => $user->subscriptions()->where('status', 'active')->count(),
        ];
    }

    /**
     * Transform user data for API response.
     */
    private function transformUserData(User $user): array
    {
        $isCreatorLike = in_array($user->role, ['creator', 'student'], true);
        $isBrand = 'brand' === $user->role;
        $isAdmin = 'admin' === $user->role;
        $isStudent = (bool) $user->student_verified || 'student' === $user->role;
        $accountStatus = $this->getAccountStatus($user);
        $isActive = null !== $user->email_verified_at && 'Removido' !== $accountStatus;
        $timeOnPlatform = $this->getUserTimeStatus($user);
        $displayName = $isBrand ? ($user->company_name ?: $user->name) : $user->name;
        $profileImage = $user->avatar ?: $user->avatar_url;
        $premiumExpiresAt = $user->premium_expires_at instanceof Carbon
            ? $user->premium_expires_at
            : ($user->premium_expires_at ? Carbon::parse($user->premium_expires_at) : null);
        $freeTrialExpiresAt = $user->free_trial_expires_at instanceof Carbon
            ? $user->free_trial_expires_at
            : ($user->free_trial_expires_at ? Carbon::parse($user->free_trial_expires_at) : null);
        $studentExpiresAt = $user->getStudentAccessExpiresAt();
        $hasActiveSubscription = (int) ($user->active_subscriptions_count ?? 0) > 0;

        $isPremiumActive = (bool) $user->has_premium
            && (null !== $premiumExpiresAt && $premiumExpiresAt->isFuture());
        $isPremiumActive = $isPremiumActive || $hasActiveSubscription;
        $isStudentAccessActive = $isStudent
            && (null === $studentExpiresAt || $studentExpiresAt->isFuture());
        $isFreeTrialActive = !$isPremiumActive
            && !$isStudent
            && null !== $freeTrialExpiresAt
            && $freeTrialExpiresAt->isFuture();

        $effectiveAccessSource = 'none';
        $effectiveAccessExpiresAt = null;

        if ($isPremiumActive) {
            $effectiveAccessSource = 'premium';
            $effectiveAccessExpiresAt = $premiumExpiresAt;
        } elseif ($isStudentAccessActive) {
            $effectiveAccessSource = 'student';
            $effectiveAccessExpiresAt = $studentExpiresAt;
        } elseif ($isFreeTrialActive) {
            $effectiveAccessSource = 'free_trial';
            $effectiveAccessExpiresAt = $freeTrialExpiresAt;
        } elseif ($user->has_premium && null !== $premiumExpiresAt && $premiumExpiresAt->isPast()) {
            $effectiveAccessSource = 'premium_expired';
            $effectiveAccessExpiresAt = $premiumExpiresAt;
        } elseif ($isStudent && null !== $studentExpiresAt && $studentExpiresAt->isPast()) {
            $effectiveAccessSource = 'student_expired';
            $effectiveAccessExpiresAt = $studentExpiresAt;
        } elseif (!$isStudent && null !== $freeTrialExpiresAt && $freeTrialExpiresAt->isPast()) {
            $effectiveAccessSource = 'free_trial_expired';
            $effectiveAccessExpiresAt = $freeTrialExpiresAt;
        }

        $status = 'Criador';
        $statusColor = 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-200';

        if ($isBrand) {
            $status = 'Marca';
            $statusColor = 'bg-purple-100 text-purple-600 dark:bg-purple-900 dark:text-purple-200';
        } elseif ($isAdmin) {
            $status = 'Admin';
            $statusColor = 'bg-amber-100 text-amber-600 dark:bg-amber-900 dark:text-amber-200';
        } elseif ($isStudent) {
            $status = 'Estudante';
            $statusColor = 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900 dark:text-indigo-200';
        }

        if ($isPremiumActive) {
            $status = 'Premium';
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
            'whatsapp' => $user->whatsapp ?? $user->whatsapp_number,
            'profile_image' => $profileImage,
            'is_active' => $isActive,
            'last_login_at' => null,
            'status' => $status,
            'statusColor' => $statusColor,
            'time' => $timeOnPlatform,
            'time_on_platform' => $timeOnPlatform,
            'campaigns' => $isCreatorLike
                ? ($user->applied_campaigns ?? 0).' aplicadas / '.($user->approved_campaigns ?? 0).' aprovadas'
                : ($user->created_campaigns ?? 0),
            'accountStatus' => $accountStatus,
            'account_status' => $accountStatus,
            'created_at' => $user->created_at,
            'email_verified_at' => $user->email_verified_at,
            'total_campaigns' => (int) ($user->created_campaigns ?? 0),
            'total_applications' => (int) ($user->applied_campaigns ?? 0),
            'has_premium' => (bool) $user->has_premium,
            'student_verified' => (bool) $user->student_verified,
            'is_premium_active' => $isPremiumActive,
            'is_student_active' => $isStudentAccessActive,
            'is_trial_active' => $isFreeTrialActive,
            'premium_expires_at' => $premiumExpiresAt,
            'free_trial_expires_at' => $freeTrialExpiresAt,
            'student_expires_at' => $studentExpiresAt,
            'effective_access_source' => $effectiveAccessSource,
            'effective_access_expires_at' => $effectiveAccessExpiresAt,
        ];
    }

    /**
     * Get user time status string.
     */
    private function getUserTimeStatus(User $user): string
    {
        $hasActiveSubscription = (int) ($user->active_subscriptions_count ?? 0) > 0;
        if ($user->has_premium && null === $user->premium_expires_at) {
            return $hasActiveSubscription ? 'Assinatura ativa' : 'Sem validade premium';
        }

        if ($user->has_premium && $user->premium_expires_at) {
            $premiumExpiresAt = $user->premium_expires_at instanceof Carbon
                ? $user->premium_expires_at
                : Carbon::parse($user->premium_expires_at);

            return $this->formatTimeDistance($premiumExpiresAt, true);
        }

        $studentExpiresAt = $user->getStudentAccessExpiresAt();
        if ($studentExpiresAt) {
            return $this->formatTimeDistance($studentExpiresAt, true);
        }

        if ($user->free_trial_expires_at) {
            $trialExpiresAt = $user->free_trial_expires_at instanceof Carbon
                ? $user->free_trial_expires_at
                : Carbon::parse($user->free_trial_expires_at);

            return $this->formatTimeDistance($trialExpiresAt, true);
        }

        return $this->formatTimeDistance($user->created_at, false);
    }

    /**
     * Filter active subscriptions for premium checks.
     *
     * @param mixed $subscriptionQuery
     */
    private function applyActiveSubscriptionFilter($subscriptionQuery): void
    {
        $subscriptionQuery
            ->where('status', 'active')
            ->where(function ($expiryQuery): void {
                $expiryQuery
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now())
                ;
            })
        ;
    }

    /**
     * Format time distance in a user-friendly way.
     */
    private function formatTimeDistance(Carbon $referenceDate, bool $isExpiryDate): string
    {
        $now = now();
        $isFuture = $referenceDate->isFuture();
        $days = $now->diffInDays($referenceDate);

        if (0 === $days) {
            return $isExpiryDate ? 'Hoje' : '0 dias';
        }

        if ($days < 30) {
            $suffix = 1 === $days ? 'dia' : 'dias';
            if ($isExpiryDate) {
                return $isFuture ? "{$days} {$suffix} restantes" : "Expirou ha {$days} {$suffix}";
            }

            return "{$days} {$suffix}";
        }

        $months = max(1, $now->diffInMonths($referenceDate));
        $suffix = 1 === $months ? 'mes' : 'meses';

        if ($isExpiryDate) {
            return $isFuture ? "{$months} {$suffix} restantes" : "Expirou ha {$months} {$suffix}";
        }

        return "{$months} {$suffix}";
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

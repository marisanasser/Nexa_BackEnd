<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Services\UserNotificationService;
use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Models\Payment\Subscription;
use App\Models\User\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Customer as StripeCustomer;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

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
            'role' => 'nullable|in:creator,brand,admin,student',
            'status' => 'nullable|in:active,blocked,removed,pending,unverified,premium,premium_expired,student_period',
            'access' => 'nullable|in:premium,premium_expired,student_period',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $role = $request->input('role');
        $status = $request->input('status');
        $access = $request->input('access');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $query = User::query();

        if ($role) {
            if ('student' === $role) {
                $query->where(function ($studentQuery): void {
                    $studentQuery
                        ->where('role', 'student')
                        ->orWhere('student_verified', true)
                    ;
                });
            } else {
                $query->where('role', $role);
            }
        }

        if ($status) {
            if (in_array($status, ['premium', 'premium_expired', 'student_period'], true)) {
                $this->applyAccessFilter($query, $status);
            } else {
                $this->applyStatusFilter($query, $status);
            }
        }

        if ($access) {
            $this->applyAccessFilter($query, $access);
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
            ->with([
                'subscriptions' => function ($q): void {
                    $q->select([
                        'id',
                        'user_id',
                        'status',
                        'stripe_status',
                        'expires_at',
                    ])->orderByDesc('expires_at');
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
     * Apply premium/student access filter to query.
     *
     * @param mixed $query
     */
    private function applyAccessFilter($query, string $access): void
    {
        $now = now();

        match ($access) {
            'premium' => $query->where(function ($premiumQuery) use ($now): void {
                $premiumQuery
                    ->where(function ($billingPremiumQuery) use ($now): void {
                        $billingPremiumQuery
                            ->where('has_premium', true)
                            ->where(function ($expirationQuery) use ($now): void {
                                $expirationQuery
                                    ->whereNull('premium_expires_at')
                                    ->orWhere('premium_expires_at', '>', $now)
                                ;
                            })
                        ;
                    })
                    ->orWhereHas('subscriptions', function ($subscriptionQuery) use ($now): void {
                        $subscriptionQuery
                            ->where('status', Subscription::STATUS_ACTIVE)
                            ->where(function ($expirationQuery) use ($now): void {
                                $expirationQuery
                                    ->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', $now)
                                ;
                            })
                        ;
                    })
                ;
            }),
            'student_period' => $query
                ->where('student_verified', true)
                ->where(function ($studentQuery) use ($now): void {
                    $studentQuery
                        ->whereNull('student_expires_at')
                        ->orWhere('student_expires_at', '>', $now)
                    ;
                }),
            'premium_expired' => $query
                ->where('has_premium', true)
                ->whereNotNull('premium_expires_at')
                ->where('premium_expires_at', '<=', $now)
                ->where(function ($studentWindowQuery) use ($now): void {
                    $studentWindowQuery
                        ->where('student_verified', false)
                        ->orWhere(function ($expiredStudentQuery) use ($now): void {
                            $expiredStudentQuery
                                ->where('student_verified', true)
                                ->whereNotNull('student_expires_at')
                                ->where('student_expires_at', '<=', $now)
                            ;
                        })
                    ;
                })
                ->whereDoesntHave('subscriptions', function ($subscriptionQuery) use ($now): void {
                    $subscriptionQuery
                        ->where('status', Subscription::STATUS_ACTIVE)
                        ->where(function ($expirationQuery) use ($now): void {
                            $expirationQuery
                                ->whereNull('expires_at')
                                ->orWhere('expires_at', '>', $now)
                            ;
                        })
                    ;
                }),
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
        $accessState = $this->resolveAccessState($user);
        $timeOnPlatform = $this->getUserTimeStatus($user, $accessState);
        $displayName = $user->company_name ?: $user->name;
        $profileImage = $user->avatar ?: $user->avatar_url;

        if ($isCreator) {
            $status = 'Criador';
            $statusColor = 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-200';

            if ($accessState['has_premium']) {
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
                'has_premium' => $accessState['has_premium'],
                'student_verified' => $accessState['student_verified'],
                'is_premium_active' => $accessState['is_premium_active'],
                'is_student_active' => $accessState['is_student_active'],
                'is_trial_active' => $accessState['is_trial_active'],
                'is_student_initial_active' => $accessState['is_student_initial_active'],
                'premium_expires_at' => $accessState['premium_expires_at'],
                'free_trial_expires_at' => $accessState['free_trial_expires_at'],
                'student_initial_expires_at' => $accessState['student_initial_expires_at'],
                'student_expires_at' => $accessState['student_expires_at'],
                'effective_access_source' => $accessState['effective_access_source'],
                'effective_access_source_normalized' => $accessState['effective_access_source_normalized'],
                'effective_access_expires_at' => $accessState['effective_access_expires_at'],
            ];
        }

        // Brand user
        $status = 'Marca';
        $statusColor = 'bg-purple-100 text-purple-600 dark:bg-purple-900 dark:text-purple-200';

        if ($accessState['has_premium']) {
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
            'has_premium' => $accessState['has_premium'],
            'student_verified' => $accessState['student_verified'],
            'is_premium_active' => $accessState['is_premium_active'],
            'is_student_active' => $accessState['is_student_active'],
            'is_trial_active' => $accessState['is_trial_active'],
            'is_student_initial_active' => $accessState['is_student_initial_active'],
            'premium_expires_at' => $accessState['premium_expires_at'],
            'free_trial_expires_at' => $accessState['free_trial_expires_at'],
            'student_initial_expires_at' => $accessState['student_initial_expires_at'],
            'student_expires_at' => $accessState['student_expires_at'],
            'effective_access_source' => $accessState['effective_access_source'],
            'effective_access_source_normalized' => $accessState['effective_access_source_normalized'],
            'effective_access_expires_at' => $accessState['effective_access_expires_at'],
        ];
    }

    /**
     * Get user time status string.
     */
    private function getUserTimeStatus(User $user, array $accessState): string
    {
        $effectiveAccessSource = $accessState['effective_access_source_normalized']
            ?? $accessState['effective_access_source']
            ?? 'none';
        $effectiveAccessExpiresAt = $accessState['effective_access_expires_at'] ?? null;

        if (
            in_array($effectiveAccessSource, ['premium', 'student', 'student_initial', 'free_trial'], true)
            && null === $effectiveAccessExpiresAt
        ) {
            return 'Ilimitado';
        }

        if ($effectiveAccessExpiresAt instanceof Carbon) {
            $months = max(0, now()->diffInMonths($effectiveAccessExpiresAt, false));
            return $months.' meses';
        }

        if ($user->free_trial_expires_at) {
            $trialExpiresAt = $user->free_trial_expires_at instanceof Carbon
                ? $user->free_trial_expires_at
                : Carbon::parse($user->free_trial_expires_at);
            $months = max(0, now()->diffInMonths($trialExpiresAt, false));

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

    /**
     * Build effective access state for admin payload and self-heal stale premium expiration.
     *
     * @return array{
     *     has_premium: bool,
     *     student_verified: bool,
     *     is_premium_active: bool,
     *     is_student_active: bool,
     *     is_trial_active: bool,
     *     is_student_initial_active: bool,
     *     premium_expires_at: ?Carbon,
     *     free_trial_expires_at: ?Carbon,
     *     student_initial_expires_at: ?Carbon,
     *     student_expires_at: ?Carbon,
     *     effective_access_source: string,
     *     effective_access_source_normalized: string,
     *     effective_access_expires_at: ?Carbon
     * }
     */
    private function resolveAccessState(User $user): array
    {
        $storedPremiumExpiresAt = $this->parseToCarbon($user->premium_expires_at);
        $latestSubscriptionExpiresAt = $this->getLatestSubscriptionExpiry($user);
        $latestStripeExpiry = $this->shouldFetchStripeExpiry($user, $storedPremiumExpiresAt, $latestSubscriptionExpiresAt)
            ? $this->getLatestStripeSubscriptionExpiry($user)
            : null;

        $premiumExpiresAt = $storedPremiumExpiresAt;
        if (
            $latestSubscriptionExpiresAt
            && (!$storedPremiumExpiresAt || $latestSubscriptionExpiresAt->gt($storedPremiumExpiresAt))
        ) {
            $premiumExpiresAt = $latestSubscriptionExpiresAt;
        }
        if (
            $latestStripeExpiry
            && (!$premiumExpiresAt || $latestStripeExpiry->gt($premiumExpiresAt))
        ) {
            $premiumExpiresAt = $latestStripeExpiry;
        }

        if (
            $premiumExpiresAt
            && (!$storedPremiumExpiresAt || $premiumExpiresAt->gt($storedPremiumExpiresAt))
        ) {
            // Keep users table in sync when subscription table has a fresher expiration.
            $user->forceFill([
                'has_premium' => true,
                'premium_expires_at' => $premiumExpiresAt,
            ])->saveQuietly();
        }

        $studentExpiresAt = $this->parseToCarbon($user->student_expires_at);
        $trialExpiresAt = $this->parseToCarbon($user->free_trial_expires_at);
        $studentVerified = (bool) $user->student_verified;

        $hasPremium = (bool) $user->has_premium || null !== $premiumExpiresAt;
        $isStudentActive = $studentVerified
            && (null === $studentExpiresAt || $studentExpiresAt->isFuture());
        $isBillingPremiumActive = $hasPremium && (null === $premiumExpiresAt || $premiumExpiresAt->isFuture());
        // Verified student window extends effective premium access.
        $isPremiumActive = $isBillingPremiumActive || $isStudentActive;
        $isTrialActive = !$isPremiumActive
            && !$studentVerified
            && null !== $trialExpiresAt
            && $trialExpiresAt->isFuture();

        $effectiveAccessSource = 'none';
        $effectiveAccessExpiresAt = null;

        if ($isPremiumActive) {
            $effectiveAccessSource = 'premium';
            $effectiveAccessExpiresAt = $this->resolvePremiumEffectiveExpiry(
                $isBillingPremiumActive,
                $premiumExpiresAt,
                $isStudentActive,
                $studentExpiresAt
            );
        } elseif ($isStudentActive) {
            $effectiveAccessSource = 'student';
            $effectiveAccessExpiresAt = $studentExpiresAt;
        } elseif ($isTrialActive) {
            $effectiveAccessSource = 'free_trial';
            $effectiveAccessExpiresAt = $trialExpiresAt;
        } elseif ($hasPremium && null !== $premiumExpiresAt) {
            $effectiveAccessSource = 'premium_expired';
            $effectiveAccessExpiresAt = $premiumExpiresAt;
        } elseif ($studentVerified || null !== $studentExpiresAt) {
            $effectiveAccessSource = 'student_expired';
            $effectiveAccessExpiresAt = $studentExpiresAt;
        } elseif (null !== $trialExpiresAt) {
            $effectiveAccessSource = 'free_trial_expired';
            $effectiveAccessExpiresAt = $trialExpiresAt;
        }

        $effectiveAccessSourceNormalized = $this->normalizeEffectiveAccessSource($effectiveAccessSource);

        return [
            'has_premium' => $hasPremium,
            'student_verified' => $studentVerified,
            'is_premium_active' => $isPremiumActive,
            'is_student_active' => $isStudentActive,
            'is_trial_active' => $isTrialActive,
            'is_student_initial_active' => $isTrialActive,
            'premium_expires_at' => $premiumExpiresAt,
            'free_trial_expires_at' => $trialExpiresAt,
            'student_initial_expires_at' => $trialExpiresAt,
            'student_expires_at' => $studentExpiresAt,
            'effective_access_source' => $effectiveAccessSource,
            'effective_access_source_normalized' => $effectiveAccessSourceNormalized,
            'effective_access_expires_at' => $effectiveAccessExpiresAt,
        ];
    }

    private function normalizeEffectiveAccessSource(string $source): string
    {
        return match ($source) {
            'free_trial' => 'student_initial',
            'free_trial_expired' => 'student_initial_expired',
            default => $source,
        };
    }

    private function resolvePremiumEffectiveExpiry(
        bool $isBillingPremiumActive,
        ?Carbon $premiumExpiresAt,
        bool $isStudentActive,
        ?Carbon $studentExpiresAt
    ): ?Carbon {
        if ($isBillingPremiumActive && null === $premiumExpiresAt) {
            // Premium without end date means unlimited access.
            return null;
        }

        if ($isStudentActive && null === $studentExpiresAt) {
            // Verified student access without end date means unlimited access.
            return null;
        }

        if ($isBillingPremiumActive && $isStudentActive) {
            if (!$premiumExpiresAt) {
                return $studentExpiresAt;
            }

            if (!$studentExpiresAt) {
                return $premiumExpiresAt;
            }

            return $studentExpiresAt->gt($premiumExpiresAt)
                ? $studentExpiresAt
                : $premiumExpiresAt;
        }

        if ($isBillingPremiumActive) {
            return $premiumExpiresAt;
        }

        if ($isStudentActive) {
            return $studentExpiresAt;
        }

        return null;
    }

    private function parseToCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_string($value) && '' !== trim($value)) {
            try {
                return Carbon::parse($value);
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }

    private function getLatestSubscriptionExpiry(User $user): ?Carbon
    {
        $subscriptions = $user->relationLoaded('subscriptions')
            ? $user->subscriptions
            : $user->subscriptions()
                ->select(['id', 'user_id', 'status', 'stripe_status', 'expires_at'])
                ->orderByDesc('expires_at')
                ->get()
        ;

        foreach ($subscriptions as $subscription) {
            $expiresAt = $this->parseToCarbon($subscription->expires_at);
            if (!$expiresAt) {
                continue;
            }

            return $expiresAt;
        }

        return null;
    }

    private function shouldFetchStripeExpiry(
        User $user,
        ?Carbon $storedPremiumExpiresAt,
        ?Carbon $latestSubscriptionExpiresAt
    ): bool {
        if ('creator' !== $user->role || !(bool) $user->has_premium) {
            return false;
        }

        if (null === $storedPremiumExpiresAt || $storedPremiumExpiresAt->isFuture()) {
            return false;
        }

        if ($latestSubscriptionExpiresAt && $latestSubscriptionExpiresAt->isFuture()) {
            return false;
        }

        return !empty($user->stripe_customer_id) || !empty($user->email);
    }

    private function getLatestStripeSubscriptionExpiry(User $user): ?Carbon
    {
        $stripeSecret = config('services.stripe.secret');
        if (!$stripeSecret) {
            return null;
        }

        try {
            Stripe::setApiKey($stripeSecret);
            $customerId = $user->stripe_customer_id ?: $this->findStripeCustomerIdByEmail($user->email);
            if (!$customerId) {
                return null;
            }

            if ($customerId !== $user->stripe_customer_id) {
                $user->forceFill(['stripe_customer_id' => $customerId])->saveQuietly();
            }

            $stripeSubscriptions = StripeSubscription::all([
                'customer' => $customerId,
                'status' => 'all',
                'limit' => 20,
            ]);

            $latestExpiry = null;
            foreach ($stripeSubscriptions->data as $stripeSubscription) {
                $stripeStatus = strtolower((string) ($stripeSubscription->status ?? ''));
                if (!in_array($stripeStatus, ['active', 'trialing', 'past_due', 'unpaid'], true)) {
                    continue;
                }

                $expiresAt = $this->extractStripePeriodEnd($stripeSubscription);
                if (!$expiresAt) {
                    continue;
                }

                if (!$latestExpiry || $expiresAt->gt($latestExpiry)) {
                    $latestExpiry = $expiresAt;
                }
            }

            return $latestExpiry;
        } catch (Exception $e) {
            Log::warning('Failed to read Stripe subscription expiry for admin access resolution', [
                'user_id' => $user->id,
                'stripe_customer_id' => $user->stripe_customer_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function findStripeCustomerIdByEmail(?string $email): ?string
    {
        if (!$email) {
            return null;
        }

        try {
            $customers = StripeCustomer::all([
                'email' => $email,
                'limit' => 10,
            ]);

            if (!empty($customers->data)) {
                return $customers->data[0]->id ?? null;
            }
        } catch (Exception $e) {
            Log::warning('Failed to find Stripe customer by email', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function extractStripePeriodEnd(object $stripeSubscription): ?Carbon
    {
        if (isset($stripeSubscription->current_period_end) && $stripeSubscription->current_period_end) {
            return Carbon::createFromTimestamp((int) $stripeSubscription->current_period_end);
        }

        $itemEnd = $stripeSubscription->items->data[0]->current_period_end ?? null;
        if ($itemEnd) {
            return Carbon::createFromTimestamp((int) $itemEnd);
        }

        return null;
    }
}

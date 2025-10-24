<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Campaign;
use App\Models\CampaignApplication;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminController extends Controller
{
    /**
     * Get dashboard metrics
     */
    public function getDashboardMetrics(): JsonResponse
    {
        try {
            $metrics = [
                'pendingCampaignsCount' => Campaign::where('status', 'pending')->count(),
                'allActiveCampaignCount' => Campaign::where('is_active', true)->count(),
                'allRejectCampaignCount' => Campaign::where('status', 'rejected')->count(),
                'allUserCount' => User::whereNotIn('role', ['admin'])->count(), // Exclude admin users
            ];

            return response()->json([
                'success' => true,
                'data' => $metrics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard metrics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending campaigns for dashboard
     */
    public function getPendingCampaigns(): JsonResponse
    {
        try {
            $campaigns = Campaign::with('brand')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($campaign) {
                    return [
                        'id' => $campaign->id,
                        'title' => $campaign->title,
                        'brand' => $campaign->brand->company_name ?: $campaign->brand->name,
                        'type' => $campaign->campaign_type ?: 'Vídeo',
                        'value' => $campaign->budget ? (float) $campaign->budget : 0,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $campaigns
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending campaigns: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent users for dashboard
     */
    public function getRecentUsers(): JsonResponse
    {
        try {
            $users = User::whereNotIn('role', ['admin']) // Exclude admin users
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($user) {
                    $daysAgo = $user->created_at->diffInDays(now());
                    
                    // Map role to display name
                    $roleDisplay = match($user->role) {
                        'brand' => 'Marca',
                        'creator' => 'Criador',
                        default => 'Usuário'
                    };
                    
                    // Determine tag based on user role and premium status
                    $tag = match($user->role) {
                        'brand' => 'Marca',
                        'creator' => 'Criador',
                        default => 'Usuário'
                    };
                    
                    // If user has premium, show as "Pagante" (Paying)
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
                });

            return response()->json([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get users by role with pagination and filtering
     */
    public function getUsers(Request $request): JsonResponse
    {
        $request->validate([
            'role' => 'nullable|in:creator,brand',
            'status' => 'nullable|in:active,blocked,removed,pending',
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

        // Filter by role
        if ($role) {
            $query->where('role', $role);
        }

        // Filter by status (account status)
        if ($status) {
            switch ($status) {
                case 'active':
                    $query->where('email_verified_at', '!=', null);
                    break;
                case 'blocked':
                    $query->where('email_verified_at', '=', null);
                    break;
                case 'removed':
                    $query->where('deleted_at', '!=', null);
                    break;
                case 'pending':
                    $query->where('email_verified_at', '=', null);
                    break;
            }
        }

        // Search functionality
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        // Get users with related data
        $users = $query->withCount([
            'campaignApplications as applied_campaigns',
            'campaignApplications as approved_campaigns' => function ($q) {
                $q->where('status', 'approved');
            },
            'campaigns as created_campaigns'
        ])
        ->orderBy('created_at', 'desc')
        ->paginate($perPage, ['*'], 'page', $page);

        // Transform the data to match frontend expectations
        $transformedUsers = $users->getCollection()->map(function ($user) {
            return $this->transformUserData($user);
        });

        return response()->json([
            'success' => true,
            'data' => $transformedUsers,
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ]
        ]);
    }

    /**
     * Get creators with enhanced data
     */
    public function getCreators(Request $request): JsonResponse
    {
        $request->merge(['role' => 'creator']);
        return $this->getUsers($request);
    }

    /**
     * Get brands with enhanced data
     */
    public function getBrands(Request $request): JsonResponse
    {
        $request->merge(['role' => 'brand']);
        return $this->getUsers($request);
    }

    /**
     * Get all campaigns with filtering and pagination
     */
    public function getCampaigns(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:pending,approved,rejected,active,inactive',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $query = Campaign::with(['brand', 'applications']);

        // Filter by status
        if ($status) {
            $query->where('status', $status);
        }

        // Search functionality
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $campaigns = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $transformedCampaigns = $campaigns->getCollection()->map(function ($campaign) {
            return [
                'id' => $campaign->id,
                'title' => $campaign->title,
                'description' => $campaign->description,
                'budget' => $campaign->budget,
                'status' => $campaign->status,
                'is_active' => $campaign->is_active,
                'created_at' => $campaign->created_at->format('Y-m-d H:i:s'),
                'brand' => [
                    'id' => $campaign->brand->id,
                    'name' => $campaign->brand->name,
                    'company_name' => $campaign->brand->company_name,
                    'email' => $campaign->brand->email,
                ],
                'applications_count' => $campaign->applications->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transformedCampaigns,
            'pagination' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
            ]
        ]);
    }

    /**
     * Get specific campaign details
     */
    public function getCampaign(int $id): JsonResponse
    {
        try {
            $campaign = Campaign::with(['brand', 'applications.creator'])
                ->findOrFail($id);

            $data = [
                'id' => $campaign->id,
                'title' => $campaign->title,
                'description' => $campaign->description,
                'budget' => $campaign->budget,
                'status' => $campaign->status,
                'is_active' => $campaign->is_active,
                'created_at' => $campaign->created_at->format('Y-m-d H:i:s'),
                'brand' => [
                    'id' => $campaign->brand->id,
                    'name' => $campaign->brand->name,
                    'company_name' => $campaign->brand->company_name,
                    'email' => $campaign->brand->email,
                ],
                'applications' => $campaign->applications->map(function ($application) {
                    return [
                        'id' => $application->id,
                        'status' => $application->status,
                        'proposal' => $application->proposal,
                        'created_at' => $application->created_at->format('Y-m-d H:i:s'),
                        'creator' => [
                            'id' => $application->creator->id,
                            'name' => $application->creator->name,
                            'email' => $application->creator->email,
                        ],
                    ];
                }),
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
    }

    /**
     * Approve a campaign
     */
    public function approveCampaign(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $campaign = Campaign::findOrFail($id);

            if (!$campaign->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending campaigns can be approved'
                ], 422);
            }

            // Use the model's approve method to ensure proper workflow
            $campaign->approve($user->id);

            // Notify admin of campaign approval
            \App\Services\NotificationService::notifyAdminOfSystemActivity('campaign_approved', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'brand_name' => $campaign->brand->name,
                'approved_by' => $user->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Campaign approved successfully',
                'data' => $campaign->load(['brand', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to approve campaign: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve campaign'
            ], 500);
        }
    }

    /**
     * Reject a campaign
     */
    public function rejectCampaign(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $campaign = Campaign::findOrFail($id);

            if (!$campaign->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending campaigns can be rejected'
                ], 422);
            }

            // Use the model's reject method to ensure proper workflow
            $campaign->reject($user->id, 'Rejected by admin');

            return response()->json([
                'success' => true,
                'message' => 'Campaign rejected successfully',
                'data' => $campaign->load(['brand', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to reject campaign: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject campaign'
            ], 500);
        }
    }

    /**
     * Delete a campaign
     */
    public function deleteCampaign(int $id): JsonResponse
    {
        try {
            $campaign = Campaign::findOrFail($id);
            $campaign->delete();

            return response()->json([
                'success' => true,
                'message' => 'Campaign deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete campaign'
            ], 500);
        }
    }

    /**
     * Get user statistics
     */
    public function getUserStatistics(): JsonResponse
    {
        $stats = [
            'total_users' => User::count(),
            'creators' => User::where('role', 'creator')->count(),
            'brands' => User::where('role', 'brand')->count(),
            'premium_users' => User::where('has_premium', true)->count(),
            'verified_students' => User::where('student_verified', true)->count(),
            'active_users' => User::where('email_verified_at', '!=', null)->count(),
            'pending_users' => User::where('email_verified_at', '=', null)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Update user status (activate, block, remove)
     */
    public function updateUserStatus(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:activate,block,remove',
        ]);

        $action = $request->input('action');

        try {
            switch ($action) {
                case 'activate':
                    $user->update([
                        'email_verified_at' => now(),
                    ]);
                    $message = 'User activated successfully';
                    break;

                case 'block':
                    $user->update([
                        'email_verified_at' => null,
                    ]);
                    $message = 'User blocked successfully';
                    break;

                case 'remove':
                    $user->delete();
                    $message = 'User removed successfully';
                    break;

                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid action'
                    ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'user' => $this->transformUserData($user->fresh())
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transform user data for admin interface
     */
    private function transformUserData(User $user): array
    {
        $isCreator = $user->role === 'creator';
        
        if ($isCreator) {
            // For creators, show their actual role from database
            $status = 'Criador'; // Default for creator role
            $statusColor = 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-200';
            
            // If they have premium, show as "Pagante" (Paying)
            if ($user->has_premium) {
                $status = 'Pagante';
                $statusColor = 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-200';
            }
            
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $status,
                'statusColor' => $statusColor,
                'time' => $this->getUserTimeStatus($user),
                'campaigns' => $user->applied_campaigns . ' aplicadas / ' . $user->approved_campaigns . ' aprovadas',
                'accountStatus' => $this->getAccountStatus($user),
                'created_at' => $user->created_at,
                'email_verified_at' => $user->email_verified_at,
                'has_premium' => $user->has_premium,
                'student_verified' => $user->student_verified,
                'premium_expires_at' => $user->premium_expires_at,
                'free_trial_expires_at' => $user->free_trial_expires_at,
            ];
        } else {
            // For brands, show their actual role from database
            $status = 'Marca'; // Default for brand role
            $statusColor = 'bg-purple-100 text-purple-600 dark:bg-purple-900 dark:text-purple-200';
            
            // If they have premium, show as "Pagante" (Paying)
            if ($user->has_premium) {
                $status = 'Pagante';
                $statusColor = 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-200';
            }
            
            return [
                'id' => $user->id,
                'company' => $user->company_name ?: $user->name,
                'brandName' => $user->company_name ?: $user->name,
                'email' => $user->email,
                'status' => $status,
                'statusColor' => $statusColor,
                'campaigns' => $user->created_campaigns,
                'accountStatus' => $this->getAccountStatus($user),
                'created_at' => $user->created_at,
                'email_verified_at' => $user->email_verified_at,
                'has_premium' => $user->has_premium,
                'premium_expires_at' => $user->premium_expires_at,
                'free_trial_expires_at' => $user->free_trial_expires_at,
            ];
        }
    }

    /**
     * Get user time status
     */
    private function getUserTimeStatus(User $user): string
    {
        if ($user->has_premium && $user->premium_expires_at === null) {
            return 'Ilimitado';
        }

        if ($user->has_premium && $user->premium_expires_at) {
            $months = $user->premium_expires_at->diffInMonths(now());
            return $months . ' meses';
        }

        if ($user->free_trial_expires_at) {
            $months = $user->free_trial_expires_at->diffInMonths(now());
            return $months . ' meses';
        }

        $months = $user->created_at->diffInMonths(now());
        return $months . ' meses';
    }

    /**
     * Get account status
     */
    private function getAccountStatus(User $user): string
    {
        if ($user->deleted_at) {
            return 'Removido';
        }

        if ($user->email_verified_at) {
            return 'Ativo';
        }

        // Check if user has been inactive for too long
        if ($user->created_at->diffInDays(now()) > 30) {
            return 'Bloqueado';
        }

        return 'Pendente';
    }

    /**
     * Get all students with trial information
     */
    public function getStudents(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:active,expired,premium',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $query = User::where('student_verified', true);

        // Filter by status
        if ($status) {
            switch ($status) {
                case 'active':
                    $query->where('free_trial_expires_at', '>', now())
                          ->where('has_premium', false);
                    break;
                case 'expired':
                    $query->where('free_trial_expires_at', '<=', now())
                          ->where('has_premium', false);
                    break;
                case 'premium':
                    $query->where('has_premium', true);
                    break;
            }
        }

        // Search functionality
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $students = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $transformedStudents = $students->getCollection()->map(function ($student) {
            return $this->transformStudentData($student);
        });

        return response()->json([
            'success' => true,
            'data' => $transformedStudents,
            'pagination' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
                'from' => $students->firstItem(),
                'to' => $students->lastItem(),
            ]
        ]);
    }

    /**
     * List student verification requests
     */
    public function getStudentVerificationRequests(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = \App\Models\StudentVerificationRequest::query()->with('user');
        if ($request->status) {
            $query->where('status', $request->status);
        }

        $requests = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 10);
        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    /**
     * Approve a student verification request
     */
    public function approveStudentVerification(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'duration_months' => 'nullable|integer|min:1|max:24',
        ]);
        $svr = \App\Models\StudentVerificationRequest::findOrFail($id);
        if ($svr->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Request not pending'], 422);
        }

        $duration = $request->input('duration_months', 12);
        $user = $svr->user;

        DB::beginTransaction();
        try {
            $svr->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $request->review_notes,
            ]);

            $user->update([
                'student_verified' => true,
                'student_expires_at' => now()->addMonths($duration),
                'role' => 'student',
                'free_trial_expires_at' => now()->addMonths($duration),
            ]);

            DB::commit();
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Approval failed'], 500);
        }
    }

    /**
     * Reject a student verification request
     */
    public function rejectStudentVerification(int $id, Request $request): JsonResponse
    {
        $svr = \App\Models\StudentVerificationRequest::findOrFail($id);
        if ($svr->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Request not pending'], 422);
        }

        $svr->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $request->review_notes,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Update student trial period
     */
    public function updateStudentTrial(Request $request, User $student): JsonResponse
    {
        $request->validate([
            'period' => 'required|in:1month,6months,1year',
        ]);

        if (!$student->student_verified) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a verified student'
            ], 422);
        }

        try {
            $period = $request->input('period');
            $expiresAt = match($period) {
                '1month' => now()->addMonth(),
                '6months' => now()->addMonths(6),
                '1year' => now()->addYear(),
                default => now()->addMonth(),
            };

            $student->update([
                'free_trial_expires_at' => $expiresAt,
            ]);

            // Log the trial update
            \Illuminate\Support\Facades\Log::info('Student trial period updated', [
                'student_id' => $student->id,
                'student_email' => $student->email,
                'period' => $period,
                'new_expires_at' => $expiresAt,
                'updated_by' => auth()->user()->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Student trial period updated successfully',
                'student' => $this->transformStudentData($student->fresh())
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to update student trial period', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update student trial period'
            ], 500);
        }
    }

    /**
     * Update student status (activate, block, remove)
     */
    public function updateStudentStatus(Request $request, User $student): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:activate,block,remove',
        ]);

        if (!$student->student_verified) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a verified student'
            ], 422);
        }

        $action = $request->input('action');

        try {
            switch ($action) {
                case 'activate':
                    $student->update([
                        'email_verified_at' => now(),
                    ]);
                    $message = 'Student activated successfully';
                    break;

                case 'block':
                    $student->update([
                        'email_verified_at' => null,
                    ]);
                    $message = 'Student blocked successfully';
                    break;

                case 'remove':
                    $student->delete();
                    $message = 'Student removed successfully';
                    break;

                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid action'
                    ], 400);
            }

            // Log the action
            \Illuminate\Support\Facades\Log::info('Student status updated', [
                'student_id' => $student->id,
                'student_email' => $student->email,
                'action' => $action,
                'updated_by' => auth()->user()->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'student' => $this->transformStudentData($student->fresh())
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to update student status', [
                'student_id' => $student->id,
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update student status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transform student data for admin interface
     */
    private function transformStudentData(User $student): array
    {
        $now = now();
        $trialExpiresAt = $student->free_trial_expires_at;
        
        // Determine status
        $status = 'active';
        if ($student->has_premium) {
            $status = 'premium';
        } elseif ($trialExpiresAt && $trialExpiresAt->isPast()) {
            $status = 'expired';
        }

        // Determine trial status
        $trialStatus = 'active';
        if ($student->has_premium) {
            $trialStatus = 'premium';
        } elseif ($trialExpiresAt && $trialExpiresAt->isPast()) {
            $trialStatus = 'expired';
        }

        // Calculate days remaining
        $daysRemaining = 0;
        if ($trialExpiresAt && $trialExpiresAt->isFuture()) {
            $daysRemaining = $now->diffInDays($trialExpiresAt, false);
        }

        return [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'academic_email' => $student->academic_email ?? null,
            'institution' => $student->institution ?? null,
            'course_name' => $student->course_name ?? null,
            'student_verified' => $student->student_verified,
            'student_expires_at' => $student->student_expires_at,
            'free_trial_expires_at' => $student->free_trial_expires_at,
            'has_premium' => $student->has_premium,
            'created_at' => $student->created_at,
            'email_verified_at' => $student->email_verified_at,
            'status' => $status,
            'trial_status' => $trialStatus,
            'days_remaining' => $daysRemaining,
        ];
    }

    /**
     * Get all guides for admin management
     */
    public function getGuides(): JsonResponse
    {
        try {
            $guides = \App\Models\Guide::with('steps')->latest()->get();
            
            return response()->json([
                'success' => true,
                'data' => $guides
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch guides: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific guide for admin management
     */
    public function getGuide($id): JsonResponse
    {
        try {
            $guide = \App\Models\Guide::with('steps')->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $guide
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch guide: ' . $e->getMessage()
            ], 500);
        }
    }
} 
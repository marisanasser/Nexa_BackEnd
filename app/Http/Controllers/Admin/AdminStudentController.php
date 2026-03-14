<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Services\UserNotificationService;
use Exception;
use Illuminate\Support\Facades\Log;

use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Models\User\StudentVerificationRequest;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AdminStudentController handles admin student verification and management operations.
 *
 * Extracted from the monolithic AdminController for better separation of concerns.
 */
class AdminStudentController extends Controller
{
    use HasAuthenticatedUser;

    /**
     * Get paginated list of verified students.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:active,expired,premium,blocked',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $query = User::where('student_verified', true);

        if ($status) {
            $this->applyStatusFilter($query, $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                ;
            });
        }

        $students = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page)
        ;

        $transformedStudents = collect($students->items())->map(fn ($student) => $this->transformStudentData($student));
        $pagination = [
            'current_page' => $students->currentPage(),
            'last_page' => $students->lastPage(),
            'per_page' => $students->perPage(),
            'total' => $students->total(),
            'from' => $students->firstItem(),
            'to' => $students->lastItem(),
        ];

        return response()->json([
            'success' => true,
            'data' => array_merge($pagination, [
                'data' => $transformedStudents->values(),
            ]),
            'pagination' => $pagination,
        ]);
    }

    /**
     * Get student verification requests.
     */
    public function getVerificationRequests(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = StudentVerificationRequest::query()
            ->with(['user' => function ($query): void {
                $query->withTrashed();
            }])
            ->whereHas('user')
        ;

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $requests = $query
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 10, ['*'], 'page', $request->input('page', 1))
        ;

        return response()->json([
            'success' => true,
            'data' => collect($requests->items())
                ->map(fn (StudentVerificationRequest $studentRequest): array => $this->transformVerificationRequestData($studentRequest))
                ->values(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'from' => $requests->firstItem(),
                'to' => $requests->lastItem(),
            ],
        ]);
    }

    /**
     * Approve a student verification request.
     */
    public function approveVerification(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'duration_months' => 'nullable|integer|min:1|max:24',
            'review_notes' => 'nullable|string|max:1000',
        ]);

        $svr = StudentVerificationRequest::findOrFail($id);

        if ('pending' !== $svr->status) {
            return response()->json(['success' => false, 'message' => 'Request not pending'], 422);
        }

        $duration = $request->input('duration_months', 12);
        $user = $svr->user;

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User associated with this request not found',
            ], 404);
        }

        if ($user->student_verified) {
            $svr->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $request->review_notes ?? 'Auto-approved (already verified)',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User already verified, request marked as approved',
            ]);
        }

        DB::beginTransaction();

        try {
            $svr->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $request->review_notes,
            ]);

            $expiresAt = now()->addMonths($duration);
            $updateData = [
                'student_verified' => true,
                'student_expires_at' => $expiresAt,
                'free_trial_expires_at' => $expiresAt,
            ];

            if (!$user->has_premium) {
                $updateData['role'] = 'student';
            }

            $user->update($updateData);

            DB::commit();

            UserNotificationService::notifyUserOfStudentVerificationApproval($user, [
                'duration_months' => $duration,
                'expires_at' => $expiresAt,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Student verification approved successfully',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Student verification approval failed', [
                'request_id' => $id,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Approval failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a student verification request.
     */
    public function rejectVerification(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'review_notes' => 'nullable|string|max:1000',
            'reason' => 'nullable|string|max:1000',
        ]);

        $svr = StudentVerificationRequest::findOrFail($id);
        if ('pending' !== $svr->status) {
            return response()->json(['success' => false, 'message' => 'Request not pending'], 422);
        }

        $user = $svr->user;
        $reviewNotes = $request->input('review_notes', $request->input('reason'));

        $svr->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $reviewNotes,
        ]);

        if ($user) {
            try {
                UserNotificationService::notifyUserOfStudentVerificationRejection($user, [
                    'rejection_reason' => $reviewNotes,
                    'rejected_at' => now()->toISOString(),
                ]);
            } catch (Exception $e) {
                Log::error('Failed to notify user of rejection', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Student verification request rejected successfully',
        ]);
    }

    /**
     * Update student trial period.
     */
    public function updateTrial(Request $request, User $student): JsonResponse
    {
        $request->validate([
            'period' => 'required|in:1month,6months,1year',
        ]);

        if (!$student->student_verified) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a verified student',
            ], 422);
        }

        if (!$student->isStudent() && !$student->has_premium) {
            return response()->json([
                'success' => false,
                'message' => 'User must be a student or have premium subscription',
            ], 422);
        }

        try {
            $period = $request->input('period');
            $expiresAt = match ($period) {
                '1month' => now()->addMonth(),
                '6months' => now()->addMonths(6),
                '1year' => now()->addYear(),
                default => now()->addMonth(),
            };

            $student->update([
                'free_trial_expires_at' => $expiresAt,
                'student_expires_at' => $expiresAt,
            ]);

            Log::info('Student trial period updated', [
                'student_id' => $student->id,
                'student_email' => $student->email,
                'period' => $period,
                'new_expires_at' => $expiresAt,
                'updated_by' => auth()->user()->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Student trial period updated successfully',
                'student' => $this->transformStudentData($student->fresh()),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to update student trial period', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update student trial period',
            ], 500);
        }
    }

    /**
     * Update student status (activate, block, remove).
     */
    public function updateStatus(Request $request, User $student): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:activate,block,remove',
        ]);

        if (!$student->student_verified) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a verified student',
            ], 422);
        }

        $action = $request->input('action');
        $wasActive = null !== $student->email_verified_at;

        try {
            $message = match ($action) {
                'activate' => $this->activateStudent($student),
                'block' => $this->blockStudent($student),
                'remove' => $this->removeStudent($student),
                default => throw new Exception('Invalid action'),
            };

            if ('activate' === $action && !$wasActive) {
                UserNotificationService::notifyUserOfProfileApproval($student->fresh() ?? $student, [
                    'role' => $student->role,
                ]);
            }

            Log::info('Student status updated', [
                'student_id' => $student->id,
                'student_email' => $student->email,
                'action' => $action,
                'updated_by' => auth()->user()->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'student' => $this->transformStudentData($student->fresh()),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to update student status', [
                'student_id' => $student->id,
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update student status: '.$e->getMessage(),
            ], 500);
        }
    }

    // ========================================
    // Private Helper Methods
    // ========================================

    private function applyStatusFilter($query, string $status): void
    {
        match ($status) {
            'active' => $query->where(function ($studentQuery): void {
                $studentQuery
                    ->where(function ($trialQuery): void {
                        $trialQuery
                            ->whereNotNull('student_expires_at')
                            ->where('student_expires_at', '>', now())
                        ;
                    })
                    ->orWhere(function ($trialQuery): void {
                        $trialQuery
                            ->whereNull('student_expires_at')
                            ->whereNotNull('free_trial_expires_at')
                            ->where('free_trial_expires_at', '>', now())
                        ;
                    })
                ;
            })->where('has_premium', false),
            'expired' => $query->where(function ($studentQuery): void {
                $studentQuery
                    ->where(function ($trialQuery): void {
                        $trialQuery
                            ->whereNotNull('student_expires_at')
                            ->where('student_expires_at', '<=', now())
                        ;
                    })
                    ->orWhere(function ($trialQuery): void {
                        $trialQuery
                            ->whereNull('student_expires_at')
                            ->whereNotNull('free_trial_expires_at')
                            ->where('free_trial_expires_at', '<=', now())
                        ;
                    })
                ;
            })->where('has_premium', false),
            'premium' => $query->where('has_premium', true),
            'blocked' => $query->whereNull('email_verified_at'),
            default => null,
        };
    }

    private function activateStudent(User $student): string
    {
        $student->update(['email_verified_at' => now()]);

        return 'Student activated successfully';
    }

    private function blockStudent(User $student): string
    {
        $student->update(['email_verified_at' => null]);

        return 'Student blocked successfully';
    }

    private function removeStudent(User $student): string
    {
        $student->delete();

        return 'Student removed successfully';
    }

    private function transformVerificationRequestData(StudentVerificationRequest $studentRequest): array
    {
        $user = $studentRequest->user;

        return [
            'id' => $studentRequest->id,
            'user_id' => $studentRequest->user_id,
            'user_name' => $user?->name ?? 'Usuário removido',
            'user_email' => $user?->email ?? $studentRequest->purchase_email,
            'purchase_email' => $studentRequest->purchase_email,
            'institution_name' => $user?->institution ?? null,
            'course_name' => $studentRequest->course_name ?? 'Build Creators',
            'document_url' => $this->resolveVerificationDocumentUrl($studentRequest->evidence),
            'status' => $studentRequest->status,
            'created_at' => $studentRequest->created_at,
            'reviewed_at' => $studentRequest->reviewed_at,
            'review_notes' => $studentRequest->review_notes,
        ];
    }

    private function resolveVerificationDocumentUrl(?array $evidence): ?string
    {
        if (!$evidence) {
            return null;
        }

        foreach ($evidence as $item) {
            if (is_string($item) && '' !== trim($item)) {
                return $item;
            }

            if (!is_array($item)) {
                continue;
            }

            foreach (['document_url', 'url', 'path', 'file_url'] as $key) {
                $value = $item[$key] ?? null;
                if (is_string($value) && '' !== trim($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function transformStudentData(User $student): array
    {
        $now = now();
        $trialExpiresAt = $student->getStudentAccessExpiresAt();

        $status = 'active';
        if ($student->has_premium) {
            $status = 'premium';
        } elseif ($trialExpiresAt && $trialExpiresAt->isPast()) {
            $status = 'expired';
        }

        $trialStatus = 'active';
        if ($student->has_premium) {
            $trialStatus = 'premium';
        } elseif ($trialExpiresAt && $trialExpiresAt->isPast()) {
            $trialStatus = 'expired';
        }

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
            'institution_name' => $student->institution ?? null,
            'course_name' => $student->course_name ?? null,
            'student_verified' => $student->student_verified,
            'student_verified_at' => $student->student_verified ? ($student->updated_at ?? $student->created_at) : null,
            'student_expires_at' => $student->student_expires_at,
            'free_trial_expires_at' => $student->free_trial_expires_at,
            'trial_ends_at' => $trialExpiresAt,
            'has_premium' => $student->has_premium,
            'created_at' => $student->created_at,
            'email_verified_at' => $student->email_verified_at,
            'is_active' => null !== $student->email_verified_at,
            'status' => $status,
            'trial_status' => $trialStatus,
            'days_remaining' => $daysRemaining,
        ];
    }
}

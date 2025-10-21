<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class StudentController extends Controller
{
    /**
     * Verify student status and grant free trial
     */
    public function verifyStudent(Request $request): JsonResponse
    {
        
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Check if user is already verified as student
            if ($user->student_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is already verified as a student'
                ], 422);
            }

            // Check if user already has a free trial (but allow student verification to extend it)
            // This check is removed to allow students to verify even if they have an active trial

            DB::beginTransaction();

            try {
                // Set student verification to true and update role
                $user->student_verified = true;
                $user->student_expires_at = now()->addYear(); // Student status valid for 1 year
                $user->role = 'student'; // Update role to student
                
                // Grant or extend free trial for 1 month (30 days)
                // If user already has a trial, extend it by 1 month from now
                // If user doesn't have a trial, give them 1 month from now
                $user->free_trial_expires_at = now()->addMonth();
                
                $user->save();

                // Log the student verification
                Log::info('Student verification completed', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'purchase_email' => $request->purchase_email,
                    'course_name' => $request->course_name,
                    'student_expires_at' => $user->student_expires_at,
                    'free_trial_expires_at' => $user->free_trial_expires_at,
                ]);

                // Notify admin of new student verification
                \App\Services\NotificationService::notifyAdminOfNewStudentVerification($user, [
                    'purchase_email' => $request->purchase_email,
                    'course_name' => $request->course_name,
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Student verification completed successfully! You now have verified student status and free access.',
                    'student_verified' => true,
                    'student_expires_at' => $user->student_expires_at->toISOString(),
                    'free_trial_expires_at' => $user->free_trial_expires_at->toISOString(),
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'student_verified' => $user->student_verified,
                        'student_expires_at' => $user->student_expires_at,
                        'free_trial_expires_at' => $user->free_trial_expires_at,
                        'has_premium' => $user->has_premium,
                    ]
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Student verification failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to complete student verification'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Student verification error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during student verification'
            ], 500);
        }
    }

    /**
     * Get student verification status
     */
    public function getStudentStatus(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'student_verified' => $user->student_verified,
                'student_expires_at' => $user->student_expires_at,
                'free_trial_expires_at' => $user->free_trial_expires_at,
                'has_premium' => $user->has_premium,
                'is_on_trial' => $user->isOnTrial(),
                'is_premium' => $user->isPremium(),
            ]);

        } catch (\Exception $e) {
            Log::error('Get student status error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get student status'
            ], 500);
        }
    }
}

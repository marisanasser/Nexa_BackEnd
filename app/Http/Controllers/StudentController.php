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

            
            if ($user->student_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is already verified as a student'
                ], 422);
            }

            $request->validate([
                'purchase_email' => 'required|email',
                'course_name' => 'nullable|string|max:255',
                'evidence' => 'nullable|array',
            ]);

            DB::beginTransaction();
            
            
            $user->refresh();
            if ($user->student_verified) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'User is already verified as a student'
                ], 422);
            }

            
            $existingRequest = \App\Models\StudentVerificationRequest::where('user_id', $user->id)
                ->where('status', 'pending')
                ->exists();
            
            if ($existingRequest) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a pending verification request. Please wait for admin approval.'
                ], 422);
            }

            try {
                
                $svr = \App\Models\StudentVerificationRequest::create([
                    'user_id' => $user->id,
                    'purchase_email' => $request->purchase_email,
                    'course_name' => $request->course_name ?? 'Build Creators',
                    'evidence' => $request->evidence ?? [],
                    'status' => 'pending',
                ]);

                
                \App\Services\NotificationService::notifyAdminOfNewStudentVerification($user, [
                    'purchase_email' => $request->purchase_email,
                    'course_name' => $request->course_name ?? 'Build Creators',
                    'request_id' => $svr->id,
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Solicitação registrada com sucesso! Aguarde a aprovação do administrador.',
                    'request_id' => $svr->id,
                    'student_verified' => false,
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Student verification request failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create student verification request'
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

            
            $user->refresh();

            
            $formatDate = function($date) {
                if (!$date) {
                    return null;
                }
                if (is_string($date)) {
                    try {
                        return \Carbon\Carbon::parse($date)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        return $date;
                    }
                }
                if ($date instanceof \Carbon\Carbon || $date instanceof \DateTime) {
                    return $date->format('Y-m-d H:i:s');
                }
                return null;
            };

            return response()->json([
                'success' => true,
                'student_verified' => $user->student_verified ?? false,
                'student_expires_at' => $formatDate($user->student_expires_at),
                'free_trial_expires_at' => $formatDate($user->free_trial_expires_at),
                'has_premium' => $user->has_premium ?? false,
                'is_on_trial' => $user->isOnTrial(),
                'is_premium' => $user->isPremium(),
            ]);

        } catch (\Exception $e) {
            Log::error('Get student status error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get student status: ' . $e->getMessage()
            ], 500);
        }
    }
}
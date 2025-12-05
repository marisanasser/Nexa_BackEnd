<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;

class GoogleController extends Controller
{

    
    public function redirectToGoogle(): JsonResponse
    {
        $url = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
        
        return response()->json([
            'success' => true,
            'redirect_url' => $url
        ]);
    }

    
    public function handleGoogleCallback(Request $request): JsonResponse
    {
        try {
            
            \Log::info('Google OAuth callback received', [
                'query_params' => $request->query(),
                'has_code' => $request->has('code'),
                'has_role' => $request->has('role'),
                'role' => $request->input('role'),
                'has_is_student' => $request->has('is_student'),
                'is_student' => $request->input('is_student'),
            ]);
            
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            
            $role = $request->input('role', 'creator');
            $isStudent = $request->boolean('is_student', false);
            
            
            if (!in_array($role, ['creator', 'brand', 'student'])) {
                $role = 'creator'; 
            }
            
            
            if ($isStudent) {
                $role = 'student';
            }
            
            
            $user = User::withTrashed()
                       ->where('google_id', $googleUser->getId())
                       ->orWhere('email', $googleUser->getEmail())
                       ->first();
            
            if ($user) {
                
                if ($user->trashed()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sua conta foi removida da plataforma. Entre em contato com o suporte para mais informações.',
                    ], 403);
                }

                
                if (!$user->email_verified_at) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sua conta foi bloqueada. Entre em contato com o suporte para mais informações.',
                    ], 403);
                }

                
                $updateData = [];
                
                
                if (!$user->google_id) {
                    $updateData = array_merge($updateData, [
                        'google_id' => $googleUser->getId(),
                        'google_token' => $googleUser->token,
                        'google_refresh_token' => $googleUser->refreshToken,
                        'avatar_url' => $googleUser->getAvatar() ?: $user->avatar_url,
                    ]);
                }
                
                
                if ($role && $user->role !== $role) {
                    $updateData['role'] = $role;
                }
                
                
                
                if ($isStudent && !$user->student_verified) {
                    $updateData['free_trial_expires_at'] = now()->addMonth(); 
                    
                }
                
                
                if (!empty($updateData)) {
                    $user->update($updateData);
                }
                
                $token = $user->createToken('auth_token')->plainTextToken;
                
                \Log::info('Google OAuth login successful', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                ]);
                
                return response()->json([
                    'success' => true,
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'avatar_url' => $user->avatar_url,
                        'student_verified' => $user->student_verified,
                        'has_premium' => $user->has_premium
                    ],
                    'message' => 'Login successful'
                ], 200);
            } else {
                
                $userData = [
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => Hash::make('12345678'), 
                    'role' => $role, 
                    'avatar_url' => $googleUser->getAvatar(),
                    'google_id' => $googleUser->getId(),
                    'google_token' => $googleUser->token,
                    'google_refresh_token' => $googleUser->refreshToken,
                    'email_verified_at' => now(), 
                    'birth_date' => '1990-01-01', 
                    'gender' => 'other', 
                ];
                
                
                
                if ($isStudent) {
                    $userData['free_trial_expires_at'] = now()->addMonth(); 
                    
                }
                
                
                $user = User::create($userData);
                
                
                \App\Services\NotificationService::notifyAdminOfNewRegistration($user);
                
                $token = $user->createToken('auth_token')->plainTextToken;
                
                \Log::info('Google OAuth registration successful', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                    'selected_role' => $role,
                ]);
                
                return response()->json([
                    'success' => true,
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'avatar_url' => $user->avatar_url,
                        'student_verified' => $user->student_verified,
                        'has_premium' => $user->has_premium
                    ],
                    'message' => 'Registration successful'
                ], 201);
            }
            
        } catch (\Exception $e) {
            \Log::error('Google OAuth callback failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_params' => $request->query(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Google authentication failed: ' . $e->getMessage()
            ], 422);
        }
    }

    
    public function handleGoogleWithRole(Request $request): JsonResponse
    {
        $request->validate([
            'role' => 'required|in:creator,brand'
        ]);

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            
            $user = User::where('google_id', $googleUser->getId())
                       ->orWhere('email', $googleUser->getEmail())
                       ->first();
            
            if ($user) {
                
                $updateData = ['role' => $request->role];
                
                if (!$user->google_id) {
                    $updateData = array_merge($updateData, [
                        'google_id' => $googleUser->getId(),
                        'google_token' => $googleUser->token,
                        'google_refresh_token' => $googleUser->refreshToken,
                        'avatar_url' => $googleUser->getAvatar() ?: $user->avatar_url,
                    ]);
                }
                
                $user->update($updateData);
                
                $token = $user->createToken('auth_token')->plainTextToken;
                
                return response()->json([
                    'success' => true,
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'avatar_url' => $user->avatar_url,
                        'student_verified' => $user->student_verified,
                        'has_premium' => $user->has_premium
                    ],
                    'message' => 'Login successful'
                ], 200);
            } else {
                
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => Hash::make('12345678'), 
                    'role' => $request->role,
                    'avatar_url' => $googleUser->getAvatar(),
                    'google_id' => $googleUser->getId(),
                    'google_token' => $googleUser->token,
                    'google_refresh_token' => $googleUser->refreshToken,
                    'email_verified_at' => now(),
                ]);
                
                
                \App\Services\NotificationService::notifyAdminOfNewRegistration($user);
                
                $token = $user->createToken('auth_token')->plainTextToken;
                
                return response()->json([
                    'success' => true,
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'avatar_url' => $user->avatar_url,
                        'student_verified' => $user->student_verified,
                        'has_premium' => $user->has_premium
                    ],
                    'message' => 'Registration successful'
                ], 201);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Google authentication failed: ' . $e->getMessage()
            ], 422);
        }
    }

} 
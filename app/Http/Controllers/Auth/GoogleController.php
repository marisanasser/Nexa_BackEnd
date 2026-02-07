<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Notification\Services\AdminNotificationService;
use Exception;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Base\Controller;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider as SocialiteAbstractProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Throwable;

class GoogleController extends Controller
{
    public function redirectToGoogle(): JsonResponse
    {
        $provider = $this->googleProvider()->stateless();
        $clientId = config('services.google.client_id') ?: env('GOOGLE_CLIENT_ID');
        $redirectUri = config('services.google.redirect') ?: env('GOOGLE_REDIRECT_URI');
        $url = $provider->redirect()->getTargetUrl();

        try {
            $parsedUrl = parse_url($url);
            $query = [];

            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $query);
            }

            if (!isset($query['redirect_uri']) && $redirectUri) {
                $query['redirect_uri'] = $redirectUri;

                $scheme = $parsedUrl['scheme'] ?? 'https';
                $host = $parsedUrl['host'] ?? '';
                $port = isset($parsedUrl['port']) ? ':'.$parsedUrl['port'] : '';
                $path = $parsedUrl['path'] ?? '';

                $base = $scheme.'://'.$host.$port.$path;
                $url = $base.'?'.http_build_query($query);
            }
        } catch (Throwable $e) {
            Log::error('Failed to enforce redirect_uri on Google OAuth URL', [
                'error' => $e->getMessage(),
                'original_url' => $url,
                'redirect_uri' => $redirectUri,
            ]);
        }

        return response()->json([
            'success' => true,
            'redirect_url' => $url,
            'debug_client_id' => $clientId,
            'debug_redirect_uri' => $redirectUri,
        ]);
    }

    public function handleGoogleCallback(Request $request): JsonResponse
    {
        try {
            Log::info('Google OAuth callback received', [
                'query_params' => $request->query(),
                'has_code' => $request->has('code'),
                'has_role' => $request->has('role'),
                'role' => $request->input('role'),
                'has_is_student' => $request->has('is_student'),
                'is_student' => $request->input('is_student'),
            ]);

            $googleUser = $this->googleProvider()->stateless()->user();

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
                        'google_token' => $googleUser->token ?? null,
                        'google_refresh_token' => $googleUser->refreshToken ?? null,
                        'avatar_url' => $googleUser->getAvatar() ?: $user->avatar_url,
                    ]);
                }

                $isStudent = $request->boolean('is_student', false);
                if ($isStudent && !$user->student_verified) {
                    $updateData['free_trial_expires_at'] = now()->addMonth();
                }

                if (!empty($updateData)) {
                    $user->update($updateData);
                }

                $token = $user->createToken('auth_token')->plainTextToken;

                Log::info('Google OAuth login successful', [
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
                        'has_premium' => $user->has_premium,
                    ],
                    'message' => 'Login successful',
                ], 200);
            }

            // --- NEW USER LOGIC ---
            $role = $request->input('role');
            $isStudent = $request->boolean('is_student', false);

            // If user doesn't exist AND no role is provided (and not student flow),
            // we return a special response to prompt the user to select a role.
            if (!$role && !$isStudent) {
                $registrationId = Str::random(40);
                $googleData = [
                    'id' => $googleUser->getId(),
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'avatar' => $googleUser->getAvatar(),
                    'token' => $googleUser->token,
                    'refresh_token' => $googleUser->refreshToken,
                ];
                // Store Google data in cache for 10 minutes
                Cache::put('google_reg_' . $registrationId, $googleData, 600);

                return response()->json([
                    'success' => true,
                    'action' => 'role_selection',
                    'registration_id' => $registrationId,
                    'google_user' => [
                        'name' => $googleUser->getName(),
                        'email' => $googleUser->getEmail(),
                        'avatar' => $googleUser->getAvatar(),
                    ]
                ], 200);
            }

            if (!in_array($role, ['creator', 'brand', 'student'])) {
                $role = 'creator';
            }

            if ($isStudent) {
                $role = 'student';
            }

            $userData = [
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'password' => Hash::make('12345678'),
                'role' => $role,
                'avatar_url' => $googleUser->getAvatar(),
                'google_id' => $googleUser->getId(),
                'google_token' => $googleUser->token ?? null,
                'google_refresh_token' => $googleUser->refreshToken ?? null,
                'email_verified_at' => now(),
                'birth_date' => '1990-01-01',
                'gender' => 'other',
            ];

            if ($isStudent) {
                $userData['free_trial_expires_at'] = now()->addMonth();
            }

            $user = User::create($userData);

            AdminNotificationService::notifyAdminOfNewRegistration($user);

            $token = $user->createToken('auth_token')->plainTextToken;

            Log::info('Google OAuth registration successful', [
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
                    'has_premium' => $user->has_premium,
                ],
                'message' => 'Registration successful',
            ], 201);
        } catch (Exception $e) {
            Log::error('Google OAuth callback failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_params' => $request->query(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Google authentication failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function completeRegistration(Request $request): JsonResponse
    {
        $request->validate([
            'registration_id' => 'required|string',
            'role' => 'required|in:creator,brand',
            'whatsapp' => 'required|string|max:20',
        ]);

        $googleData = Cache::get('google_reg_' . $request->registration_id);

        if (!$googleData) {
            return response()->json([
                'success' => false,
                'message' => 'Sessão de registro expirada. Por favor, tente novamente.',
            ], 422);
        }

        try {
            // Double check if user was created in the meantime
            $user = User::where('email', $googleData['email'])->first();

            if (!$user) {
                $userData = [
                    'name' => $googleData['name'],
                    'email' => $googleData['email'],
                    'password' => Hash::make('12345678'),
                    'role' => $request->role,
                    'whatsapp' => $request->whatsapp,
                    'avatar_url' => $googleData['avatar'],
                    'google_id' => $googleData['id'],
                    'google_token' => $googleData['token'] ?? null,
                    'google_refresh_token' => $googleData['refresh_token'] ?? null,
                    'email_verified_at' => now(),
                    'birth_date' => '1990-01-01',
                    'gender' => 'other',
                ];

                $user = User::create($userData);
                AdminNotificationService::notifyAdminOfNewRegistration($user);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            // Clear cache
            Cache::forget('google_reg_' . $request->registration_id);

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
                    'has_premium' => $user->has_premium,
                ],
                'message' => 'Registration successful',
            ], 201);
        } catch (Exception $e) {
            Log::error('Google OAuth completion failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function handleGoogleWithRole(Request $request): JsonResponse
    {
        $request->validate([
            'role' => 'required|in:creator,brand',
        ]);

        try {
            $googleUser = $this->googleProvider()->stateless()->user();

            $user = User::where('google_id', $googleUser->getId())
                ->orWhere('email', $googleUser->getEmail())
                ->first()
            ;

            if ($user) {
                $updateData = ['role' => $request->role];

                if (!$user->google_id) {
                    $updateData = array_merge($updateData, [
                        'google_id' => $googleUser->getId(),
                        'google_token' => $googleUser->token ?? null,
                        'google_refresh_token' => $googleUser->refreshToken ?? null,
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
                        'has_premium' => $user->has_premium,
                    ],
                    'message' => 'Login successful',
                ], 200);
            }

            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'password' => Hash::make('12345678'),
                'role' => $request->role,
                'avatar_url' => $googleUser->getAvatar(),
                'google_id' => $googleUser->getId(),
                'google_token' => $googleUser->token ?? null,
                'google_refresh_token' => $googleUser->refreshToken ?? null,
                'email_verified_at' => now(),
            ]);

            AdminNotificationService::notifyAdminOfNewRegistration($user);

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
                    'has_premium' => $user->has_premium,
                ],
                'message' => 'Registration successful',
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Google authentication failed: '.$e->getMessage(),
            ], 422);
        }
    }

    private function googleProvider(): SocialiteAbstractProvider
    {
        $redirectUri = config('services.google.redirect') ?: env('GOOGLE_REDIRECT_URI');

        $config = [
            'client_id' => config('services.google.client_id') ?: env('GOOGLE_CLIENT_ID'),
            'client_secret' => config('services.google.client_secret') ?: env('GOOGLE_CLIENT_SECRET'),
            'redirect' => $redirectUri,
        ];

        return Socialite::buildProvider(GoogleProvider::class, $config);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Helpers\FileUploadHelper;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Base\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User\User;
use App\Models\User\UserOnlineStatus;
use App\Domain\Notification\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        /** @var null|User $user */
        $user = $this->getAuthenticatedUser();

        if (!$user) {
            Log::error('Login authentication succeeded but no user instance found');

            return response()->json([
                'success' => false,
                'message' => 'Falha ao autenticar. Tente novamente.',
            ], 401);
        }

        try {
            $onlineStatus = $user->onlineStatus ?? UserOnlineStatus::firstOrCreate(['user_id' => $user->id]);
            $onlineStatus->updateOnlineStatus(true);
        } catch (Throwable $e) {
            Log::error('Failed to update user online status on login', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $token = $user->createToken('auth_token')->plainTextToken;
        } catch (Throwable $e) {
            Log::error('Failed to create auth token on login', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao criar token de acesso. Tente novamente mais tarde.',
            ], 422);
        }

        if (!$user->isAdmin()) {
            AdminNotificationService::notifyAdminOfNewLogin($user, [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'login_time' => now()->toISOString(),
            ]);
        }

        return response()->json([
            'success' => true,
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'avatar_url' => FileUploadHelper::resolveUrl($user->avatar_url),
                'student_verified' => $user->student_verified,
                'has_premium' => $user->has_premium,
            ],
        ], 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            try {
                $onlineStatus = $user->onlineStatus ?? UserOnlineStatus::firstOrCreate(['user_id' => $user->id]);
                $onlineStatus->updateOnlineStatus(false);
            } catch (Throwable $e) {
                Log::error('Failed to update user online status on logout', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ], 200);
    }
}

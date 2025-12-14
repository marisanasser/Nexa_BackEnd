<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\UserOnlineStatus;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $onlineStatus = $user->onlineStatus ?? UserOnlineStatus::firstOrCreate(['user_id' => $user->id]);
        $onlineStatus->updateOnlineStatus(true);

        $token = $user->createToken('auth_token')->plainTextToken;

        if (! $user->isAdmin()) {
            NotificationService::notifyAdminOfNewLogin($user, [
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
                'avatar_url' => $user->avatar_url,
                'student_verified' => $user->student_verified,
                'has_premium' => $user->has_premium,
            ],
        ], 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $onlineStatus = $user->onlineStatus ?? UserOnlineStatus::firstOrCreate(['user_id' => $user->id]);
            $onlineStatus->updateOnlineStatus(false);

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

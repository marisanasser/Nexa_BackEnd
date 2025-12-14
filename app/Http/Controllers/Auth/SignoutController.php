<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\UserOnlineStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SignoutController extends Controller
{
    
    public function __invoke(Request $request): JsonResponse
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
            'message' => 'Signed out successfully'
        ], 200);
    }
}

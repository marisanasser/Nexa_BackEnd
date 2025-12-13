<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CheckUserStatus
{
    
    public function handle(Request $request, Closure $next)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user) {
            
            if ($user->trashed()) {
                
                $user->tokens()->delete();
                
                return response()->json([
                    'success' => false,
                    'message' => 'Sua conta foi removida da plataforma. Entre em contato com o suporte para mais informações.',
                ], 403);
            }

            
            if (!$user->email_verified_at) {
                
                $user->tokens()->delete();
                
                return response()->json([
                    'success' => false,
                    'message' => 'Sua conta foi bloqueada. Entre em contato com o suporte para mais informações.',
                ], 403);
            }
        }

        return $next($request);
    }
}
<?php

declare(strict_types=1);

namespace App\Http\Middleware\Auth;

use App\Domain\Shared\Traits\HasAuthenticatedUser;
use Closure;
use Illuminate\Http\Request;

class CheckUserStatus
{
    use HasAuthenticatedUser;
    public function handle(Request $request, Closure $next)
    {
        $user = $this->getAuthenticatedUser();

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

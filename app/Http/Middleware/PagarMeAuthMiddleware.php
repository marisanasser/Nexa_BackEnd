<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PagarMeAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $request->header('X-PagarMe-Account-ID');
        $email = $request->header('X-PagarMe-Email');

        if (! $accountId || ! $email) {
            return response()->json([
                'message' => 'Pagar.me authentication headers required: X-PagarMe-Account-ID and X-PagarMe-Email',
            ], 401);
        }

        try {

            $user = User::where('account_id', $accountId)
                ->orWhere('email', $email)
                ->first();

            if (! $user) {
                return response()->json([
                    'message' => 'User not found with provided Pagar.me credentials.',
                ], 401);
            }

            if ($user->account_id && $user->account_id !== $accountId) {
                return response()->json([
                    'message' => 'Account ID mismatch.',
                ], 401);
            }

            Log::info('Pagar.me middleware authentication', [
                'user_id' => $user->id,
                'account_id' => $accountId,
                'email' => $email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            auth()->login($user);

            $request->merge(['pagarme_user' => $user]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('Pagar.me middleware authentication error', [
                'account_id' => $accountId,
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Authentication failed. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

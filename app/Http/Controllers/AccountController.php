<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    
    public function removeAccount(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();
        
        
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Senha incorreta. Por favor, verifique sua senha.',
            ], 401);
        }

        try {
            
            \Log::info('User account removal initiated', [
                'user_id' => $user->id,
                'email' => $user->email,
                'reason' => $request->reason,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            
            $user->delete();

            
            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Sua conta foi removida com sucesso. Você pode restaurá-la a qualquer momento entrando em contato com o suporte.',
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Failed to remove user account', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover conta. Tente novamente.',
            ], 500);
        }
    }

    
    public function restoreAccount(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            
            $user = User::withTrashed()
                ->where('email', $request->email)
                ->whereNotNull('deleted_at')
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhuma conta removida encontrada com este e-mail.',
                ], 404);
            }

            
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Senha incorreta. Por favor, verifique sua senha.',
                ], 401);
            }

            
            $daysSinceDeletion = now()->diffInDays($user->deleted_at);
            if ($daysSinceDeletion > 30) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta conta foi removida há mais de 30 dias e não pode ser restaurada automaticamente. Entre em contato com o suporte.',
                ], 403);
            }

            
            $user->restore();

            
            \Log::info('User account restored', [
                'user_id' => $user->id,
                'email' => $user->email,
                'days_since_deletion' => $daysSinceDeletion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Sua conta foi restaurada com sucesso!',
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
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Failed to restore user account', [
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao restaurar conta. Tente novamente.',
            ], 500);
        }
    }

    
    public function checkRemovedAccount(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            
            $removedUser = User::withTrashed()
                ->where('email', $request->email)
                ->whereNotNull('deleted_at')
                ->first();

            if (!$removedUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhuma conta removida encontrada com este e-mail.',
                ], 404);
            }

            
            $daysSinceDeletion = now()->diffInDays($removedUser->deleted_at);
            $canRestore = $daysSinceDeletion <= 30;

            return response()->json([
                'success' => true,
                'can_restore' => $canRestore,
                'days_since_deletion' => $daysSinceDeletion,
                'deleted_at' => $removedUser->deleted_at->toISOString(),
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Failed to check removed account', [
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao verificar conta. Tente novamente.',
            ], 500);
        }
    }
    

    public function checkAccount(Request $request): JsonResponse
    {
         $request->validate([
        'email' => 'required|email',
    ]);

    $exists = \App\Models\User::where('email', $request->email)->exists();

    return response()->json([
        'exists' => $exists,
        'message' => $exists 
            ? 'Email already exists.' 
            : 'You must regist.',
    ]);
    }
}

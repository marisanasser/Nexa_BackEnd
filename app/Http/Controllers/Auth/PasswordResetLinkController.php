<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordReset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetLinkController extends Controller
{
    
    public function store(Request $request): JsonResponse
    {
        
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            
            
            return response()->json([
                'success' => true,
                'message' => 'Se o email existe em nosso sistema, você receberá um link para redefinir sua senha.'
            ]);
        }

        
        
        $token = Password::createToken($user);

        
        try {
            Mail::to($user->email)->send(new PasswordReset($token, $user->email));

            return response()->json([
                'success' => true,
                'message' => 'Se o email existe em nosso sistema, você receberá um link para redefinir sua senha.'
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to send password reset email', [
                'email' => $user->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            
            return response()->json([
                'success' => true,
                'message' => 'Se o email existe em nosso sistema, você receberá um link para redefinir sua senha.'
            ]);
        }
    }
}

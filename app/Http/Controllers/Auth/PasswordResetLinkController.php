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
    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        // Validate the email input
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        // Check if user exists
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Return success even if user doesn't exist for security reasons
            // This prevents email enumeration attacks
            return response()->json([
                'success' => true,
                'message' => 'Se o email existe em nosso sistema, você receberá um link para redefinir sua senha.'
            ]);
        }

        // Generate password reset token using Laravel's Password facade
        // This creates and stores the token in password_reset_tokens table
        $token = Password::createToken($user);

        // Send password reset email via AWS SES using custom mailable
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

            // Return success even if email fails to prevent email enumeration
            return response()->json([
                'success' => true,
                'message' => 'Se o email existe em nosso sistema, você receberá um link para redefinir sua senha.'
            ]);
        }
    }
}

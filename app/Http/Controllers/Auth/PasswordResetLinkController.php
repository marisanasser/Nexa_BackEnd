<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Base\Controller;
use App\Mail\PasswordReset;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

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
                'message' => 'Se o email existe em nosso sistema, você receberá um link para redefinir sua senha.',
            ]);
        }

        $token = Password::createToken($user);

        try {
            $defaultMailer = config('mail.default');
            $fromAddress = config('mail.from.address');
            $fromName = config('mail.from.name');
            $sesRegion = env('AWS_SES_REGION', env('AWS_DEFAULT_REGION'));
            $smtpHost = env('MAIL_HOST');
            $smtpUser = env('MAIL_USERNAME') ? '***' : null;
            Log::info('Password reset mail dispatch attempt', [
                'email' => $user->email,
                'mailer' => $defaultMailer,
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'ses_region' => $sesRegion,
                'smtp_host' => $smtpHost,
                'smtp_user_set' => (bool) $smtpUser,
            ]);
            Mail::to($user->email)->send(new PasswordReset($token, $user->email));

            Log::info('Password reset mail dispatched', [
                'email' => $user->email,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Se o email existe em nosso sistema, você receberá um link para redefinir sua senha.',
                'reset_url' => config('app.frontend_url').'/reset-password?token='.urlencode($token).'&email='.urlencode($user->email),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send password reset email', [
                'email' => $user->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'mailer' => config('mail.default'),
                'from_address' => config('mail.from.address'),
                'smtp_host' => env('MAIL_HOST'),
                'ses_region' => env('AWS_SES_REGION', env('AWS_DEFAULT_REGION')),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Se o email existe em nosso sistema, você receberá um link para redefinir sua senha.',
                'reset_url' => config('app.frontend_url').'/reset-password?token='.urlencode($token).'&email='.urlencode($user->email),
            ]);
        }
    }
}

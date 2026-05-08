<?php

declare (strict_types = 1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Base\Controller;
use App\Mail\PasswordReset;
use App\Models\User\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetLinkController extends Controller
{
    private const RESET_GENERIC_MESSAGE = 'Se o email existe em nosso sistema, você recebera um link para redefinir sua senha.';

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $rawEmail        = trim((string) $request->input('email'));
        $normalizedEmail = Str::lower($rawEmail);
        $requestId       = (string) Str::uuid();

        Log::info('Password reset request received', [
            'request_id' => $requestId,
            'email_hash' => hash('sha256', $normalizedEmail),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $user = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        if (! $user) {
            Log::info('Password reset request ignored: user not found', [
                'request_id' => $requestId,
                'email_hash' => hash('sha256', $normalizedEmail),
            ]);

            return $this->genericSuccessResponse();
        }

        try {
            $token         = Password::createToken($user);
            $defaultMailer = config('mail.default');
            $fromAddress   = config('mail.from.address');
            $fromName      = config('mail.from.name');
            $sesRegion     = env('AWS_SES_REGION', env('AWS_DEFAULT_REGION'));
            $smtpHost      = env('MAIL_HOST');
            $smtpUser      = env('MAIL_USERNAME') ? '***' : null;

            Log::info('Password reset mail dispatch attempt', [
                'request_id'    => $requestId,
                'email'         => $user->email,
                'mailer'        => $defaultMailer,
                'from_address'  => $fromAddress,
                'from_name'     => $fromName,
                'ses_region'    => $sesRegion,
                'smtp_host'     => $smtpHost,
                'smtp_user_set' => (bool) $smtpUser,
            ]);

            Mail::to($user->email)->send(new PasswordReset($token, $user->email));

            Log::info('Password reset mail dispatched', [
                'request_id' => $requestId,
                'email'      => $user->email,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send password reset email', [
                'request_id'      => $requestId,
                'email'           => $user->email,
                'error'           => $e->getMessage(),
                'exception_class' => get_class($e),
                'mailer'          => config('mail.default'),
                'from_address'    => config('mail.from.address'),
                'smtp_host'       => env('MAIL_HOST'),
                'ses_region'      => env('AWS_SES_REGION', env('AWS_DEFAULT_REGION')),
            ]);
        }

        return $this->genericSuccessResponse();
    }

    private function genericSuccessResponse(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => self::RESET_GENERIC_MESSAGE,
        ]);
    }
}

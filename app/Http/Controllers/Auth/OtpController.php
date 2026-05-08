<?php

declare (strict_types = 1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Base\Controller;
use App\Mail\OtpMail;
use App\Models\User\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpController extends Controller
{
    /**
     * Send OTP to the provided contact (email or phone).
     */
    public function send(Request $request)
    {
        $request->validate([
            'contact' => 'required|string',
            'type'    => 'required|in:email,whatsapp',
        ]);

        $contact = $request->contact;
        $type    = $request->type;

        // Check if user already exists
        if ('email' === $type && User::where('email', $contact)->exists()) {
            return response()->json([
                'message' => 'Você já possui cadastro.',
                'success' => false,
            ], 409);
        }

        // Generate a 6-digit code
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store in cache for 10 minutes
        // Key format: otp_{type}_{contact}
        $key = "otp_{$type}_{$contact}";

        Cache::put($key, $code, 600);
        $otpSent  = true;
        $otpError = null;

        try {
            $defaultMailer = config('mail.default');
            $fromAddress   = config('mail.from.address');
            $fromName      = config('mail.from.name');
            $sesRegion     = env('AWS_SES_REGION', env('AWS_DEFAULT_REGION'));
            $smtpHost      = env('MAIL_HOST');
            $smtpUser      = env('MAIL_USERNAME') ? '***' : null;
            Log::info('OTP mail dispatch attempt', [
                'contact'       => $contact,
                'type'          => $type,
                'mailer'        => $defaultMailer,
                'from_address'  => $fromAddress,
                'from_name'     => $fromName,
                'ses_region'    => $sesRegion,
                'smtp_host'     => $smtpHost,
                'smtp_user_set' => (bool) $smtpUser,
            ]);
            if ('email' === $type) {
                Mail::to($contact)->send(new OtpMail($code));

                Log::info('OTP email sent', [
                    'contact' => $contact,
                    'type'    => $type,
                ]);
            } elseif ('whatsapp' === $type) {
                Log::info('WhatsApp OTP generated', [
                    'contact' => $contact,
                    'type'    => $type,
                ]);
            }
        } catch (Exception $e) {
            $otpSent  = false;
            $otpError = $e->getMessage();
            Log::error('Failed to send OTP', [
                'contact'      => $contact,
                'type'         => $type,
                'error'        => $otpError,
                'mailer'       => config('mail.default'),
                'from_address' => config('mail.from.address'),
                'smtp_host'    => env('MAIL_HOST'),
                'ses_region'   => env('AWS_SES_REGION', env('AWS_DEFAULT_REGION')),
            ]);
        }

        if ('email' === $type && ! $otpSent) {
            return response()->json([
                'message' => 'Não foi possivel enviar o codigo no momento. Tente novamente em instantes.',
                'success' => false,
                'error'   => app()->environment('production') ? null : $otpError,
            ], 503);
        }

        $debug = ! app()->environment('production')
        && filter_var(env('OTP_DEBUG', false), FILTER_VALIDATE_BOOL);

        if ($debug) {
            Log::debug('OTP debug code generated', [
                'contact' => $contact,
                'type'    => $type,
                'code'    => $code,
            ]);
        }

        return response()->json([
            'message'  => 'Código de verificação gerado.',
            'success'  => true,
            'dev_code' => $debug ? $code : null,
        ]);
    }

    /**
     * Verify the OTP code.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'contact' => 'required|string',
            'type'    => 'required|in:email,whatsapp',
            'code'    => 'required|string|size:6',
        ]);

        $contact = $request->contact;
        $type    = $request->type;
        $code    = $request->code;

        $key        = "otp_{$type}_{$contact}";
        $cachedCode = Cache::get($key);

        if ($cachedCode && $cachedCode === $code) {
            // OTP is valid
            // Optionally clear the code to prevent reuse
            // Cache::forget($key);

            return response()->json(['message' => 'Código verificado com sucesso.', 'verified' => true]);
        }

        return response()->json(['message' => 'Código inválido ou expirado.', 'verified' => false], 422);
    }
}

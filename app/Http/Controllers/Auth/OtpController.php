<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class OtpController extends Controller
{
    /**
     * Send OTP to the provided contact (email or phone).
     */
    public function send(Request $request)
    {
        $request->validate([
            'contact' => 'required|string',
            'type' => 'required|in:email,whatsapp',
        ]);

        $contact = $request->contact;
        $type = $request->type;
        
        // Generate a 6-digit code
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store in cache for 10 minutes
        // Key format: otp_{type}_{contact}
        $key = "otp_{$type}_{$contact}";

        Cache::put($key, $code, 600);

        try {
            if ($type === 'email') {
                Mail::raw(
                    "Seu código de verificação Nexa é: {$code}. Ele expira em 10 minutos.",
                    function ($message) use ($contact) {
                        $message->to($contact)->subject('Código de verificação Nexa');
                    }
                );

                Log::info("OTP email sent to {$contact}: {$code}");
            } else if ($type === 'whatsapp') {
                Log::info("WhatsApp OTP for {$contact}: {$code}");
            }
        } catch (\Exception $e) {
            Log::error('Failed to send OTP', [
                'contact' => $contact,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info("OTP generated for {$type} {$contact}: {$code}");

        $debug = filter_var(env('OTP_DEBUG', true), FILTER_VALIDATE_BOOL);

        return response()->json([
            'message' => 'Código de verificação gerado.',
            'dev_code' => $debug ? $code : null
        ]);
    }

    /**
     * Verify the OTP code.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'contact' => 'required|string',
            'type' => 'required|in:email,whatsapp',
            'code' => 'required|string|size:6',
        ]);

        $contact = $request->contact;
        $type = $request->type;
        $code = $request->code;

        $key = "otp_{$type}_{$contact}";
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

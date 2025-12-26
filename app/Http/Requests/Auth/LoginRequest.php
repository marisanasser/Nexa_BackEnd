<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function validationData(): array
    {
        $data = $this->all();

        if (empty($data)) {
            $raw = $this->getContent();
            $json = json_decode($raw, true);

            if (is_array($json) && ! empty($json)) {
                $this->merge($json);
                $data = $this->all();
            }
        }

        Log::debug('LoginRequest validation data', [
            'content_type' => $this->header('Content-Type'),
            'has_email' => array_key_exists('email', $data),
            'has_password' => array_key_exists('password', $data),
            'keys' => array_keys($data),
        ]);

        return $data;
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = \App\Models\User::withTrashed()->where('email', $this->input('email'))->first();

        if (! $user) {
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages([
                'email' => 'E-mail não registrado. Por favor, registre-se novamente.',
            ]);
        }

        if ($user->trashed()) {
            RateLimiter::hit($this->throttleKey());

            $daysSinceDeletion = now()->diffInDays($user->deleted_at);
            if ($daysSinceDeletion <= 30) {
                throw ValidationException::withMessages([
                    'email' => 'account_removed_restorable',
                    'removed_at' => $user->deleted_at->toISOString(),
                    'days_since_deletion' => $daysSinceDeletion,
                ]);
            } else {
                throw ValidationException::withMessages([
                    'email' => 'Sua conta foi removida há mais de 30 dias e não pode ser restaurada automaticamente. Entre em contato com o suporte.',
                ]);
            }
        }

        if (! $user->email_verified_at) {
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages([
                'email' => 'Sua conta foi bloqueada. Entre em contato com o suporte para mais informações.',
            ]);
        }

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages([
                'password' => 'Senha incorreta. Por favor, verifique sua senha.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 10)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        Log::info('Login rate limited', [
            'email' => $this->input('email'),
            'ip' => $this->ip(),
            'seconds_remaining' => $seconds,
            'throttle_key' => $this->throttleKey(),
        ]);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->input('email')).'|'.$this->ip());
    }
}

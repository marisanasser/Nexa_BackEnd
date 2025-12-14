<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    protected $except = [
        'api/*',
        'register',
        'login',
        'logout',
        'forgot-password',
        'reset-password',
        'verify-email/*',
        'email/verification-notification',
    ];

    protected function inExceptArray($request)
    {

        if ($request->is('api/*')) {
            return true;
        }

        if ($request->is('register') ||
            $request->is('login') ||
            $request->is('logout') ||
            $request->is('forgot-password') ||
            $request->is('reset-password') ||
            $request->is('verify-email/*') ||
            $request->is('email/verification-notification')) {
            return true;
        }

        return parent::inExceptArray($request);
    }
}

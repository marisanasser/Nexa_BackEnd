<?php

declare(strict_types=1);

return [
    'auth' => [
        'login' => [
            'attempts' => 10,
            'decay_minutes' => 1,
            'lockout_minutes' => 3,
        ],
        'registration' => [
            'attempts' => 15,
            'decay_minutes' => 1,
            'lockout_minutes' => 5,
        ],
        'password_reset' => [
            'attempts' => 10,
            'decay_minutes' => 1,
            'lockout_minutes' => 10,
        ],
    ],

    'api' => [
        'general' => [
            'attempts' => 300,
            'decay_minutes' => 1,
        ],
        'notifications' => [
            'attempts' => 600,
            'decay_minutes' => 1,
        ],
        'user_status' => [
            'attempts' => 600,
            'decay_minutes' => 1,
        ],
        'payment' => [
            'attempts' => 60,
            'decay_minutes' => 1,
        ],
    ],

    'email_verification' => [
        'resend' => [
            'attempts' => 6,
            'decay_minutes' => 60,
        ],
    ],

    'messages' => [
        'auth' => [
            'login' => 'Muitas tentativas de login. Tente novamente em alguns instantes.',
            'registration' => 'Muitas tentativas de registro. Tente novamente em alguns instantes.',
            'password_reset' => 'Muitas tentativas de redefinição de senha. Tente novamente em alguns instantes.',
        ],
        'general' => 'Muitas requisições. Tente novamente em alguns instantes.',
    ],

    'include_headers' => true,

    'cache_store' => env('RATE_LIMITING_CACHE_STORE', 'default'),
];

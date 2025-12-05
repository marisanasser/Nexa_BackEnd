<?php

return [

    

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s%s',
        'localhost,localhost:5000,127.0.0.1,127.0.0.1:8000,::1',
        env('APP_URL') ? ','.parse_url(env('APP_URL'), PHP_URL_HOST) : '',
        env('FRONTEND_URL') ? ','.parse_url(env('FRONTEND_URL'), PHP_URL_HOST) : ''
    ))),

    

    'guard' => ['web'],

    

    'expiration' => 60 * 24, 

    

    'middleware' => [
        'encrypt_cookies' => App\Http\Middleware\EncryptCookies::class,
    ],

];

<?php

return [

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'sa-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'stripe' => [
        // Support both STRIPE_SECRET_KEY (novo) e STRIPE_SECRET (legado)
        'secret' => env('STRIPE_SECRET_KEY', env('STRIPE_SECRET')),
        // Support both STRIPE_PUBLISHABLE_KEY e STRIPE_KEY (legado)
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', env('STRIPE_KEY')),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];

<?php

declare(strict_types=1);

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
        'subscription_trial_days' => (int) env('STRIPE_SUBSCRIPTION_TRIAL_DAYS', 0),
    ],

    'datacrazy' => [
        'integration_token' => env('DATACRAZY_INTEGRATION_TOKEN'),
        'hmac_secret' => env('DATACRAZY_HMAC_SECRET'),
        'max_skew_seconds' => (int) env('DATACRAZY_MAX_SKEW_SECONDS', 300),
        'default_role' => env('DATACRAZY_DEFAULT_ROLE', 'creator'),
        'success_url' => env('DATACRAZY_CHECKOUT_SUCCESS_URL'),
        'cancel_url' => env('DATACRAZY_CHECKOUT_CANCEL_URL'),
        'allowed_redirect_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DATACRAZY_ALLOWED_REDIRECT_HOSTS', parse_url((string) env('FRONTEND_URL', ''), PHP_URL_HOST) ?: ''))
        ))),
    ],

    'meta' => [
        'pixel_id' => env('META_PIXEL_ID'),
        'access_token' => env('META_CONVERSIONS_API_ACCESS_TOKEN'),
        'test_event_code' => env('META_CONVERSIONS_TEST_EVENT_CODE'),
        'api_version' => env('META_CONVERSIONS_API_VERSION', 'v22.0'),
    ],
];

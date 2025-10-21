<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'pagarme' => [
        'api_key' => env('PAGARME_API_KEY'),
        'secret_key' => env('PAGARME_SECRET_KEY'),
        'encryption_key' => env('PAGARME_ENCRYPTION_KEY'),
        'webhook_secret' => env('PAGARME_WEBHOOK_SECRET'),
        'environment' => env('PAGARME_ENVIRONMENT', 'sandbox'), // sandbox or production
        'account_id' => env('PAGARME_ACCOUNT_ID'),
        'simulation_mode' => env('PAGARME_SIMULATION_MODE', false), // Enable payment simulation
    ],

];

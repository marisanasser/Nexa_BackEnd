<?php

return [

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL'),
        env('APP_FRONTEND_URL'),
        'http://localhost:3000',
        'http://localhost:5000',
        'http://localhost:5001',
        'http://localhost:5173',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:5000',
        'http://127.0.0.1:5001',
        'http://127.0.0.1:5173',
        'https://nexa-frontend-1044548850970.southamerica-east1.run.app',
        'https://nexa-frontend-bwld7w5onq-rj.a.run.app',
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

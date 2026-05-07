<?php

declare(strict_types=1);

return [
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://www.nexacreators.com',
        'https://nexa-frontend-bwld7w5onq-rj.a.run.app',
        'https://nexa-frontend-1044548850970.southamerica-east1.run.app',
        'https://nexa-frontend-prod-1044548850970.southamerica-east1.run.app',
        'https://nexa-frontend-prod-bwld7w5onq-rj.a.run.app',
        'https://www.nexacreators.com',
        // Staging origins
        'https://nexa-frontend-stg-1044548850970.southamerica-east1.run.app',
        'https://nexa-frontend-stg-bwld7w5onq-rj.a.run.app',
    ],

    'allowed_origins_patterns' => [
        '#^https?://([a-z0-9-]+\\.)*nexacreators\\.com$#i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];

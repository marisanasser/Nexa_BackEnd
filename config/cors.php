<?php

declare(strict_types=1);

return [
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'https://nexa-frontend-bwld7w5onq-rj.a.run.app',
        'https://nexa-frontend-1044548850970.southamerica-east1.run.app',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];

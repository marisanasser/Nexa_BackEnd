<?php

return [
    

    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    
    'version' => 'latest',
    
    'credentials' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
    ],

    

    'ses' => [
        'region' => env('AWS_SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'version' => 'latest',
        'credentials' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],
    ],

    

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@nexa.com'),
        'name' => env('MAIL_FROM_NAME', 'Nexa Platform'),
    ],

    

    'verification' => [
        'expire' => env('EMAIL_VERIFICATION_EXPIRE', 60), 
        'resend_throttle' => env('EMAIL_VERIFICATION_RESEND_THROTTLE', '6,1'), 
    ],
]; 
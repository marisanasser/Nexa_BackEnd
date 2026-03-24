<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Campaign Visibility Overrides
    |--------------------------------------------------------------------------
    |
    | Keep specific production test campaigns hidden from all users except
    | the allowed e-mail account.
    |
    */
    'restricted_campaign_id' => env('RESTRICTED_CAMPAIGN_ID', 138),
    'restricted_campaign_allowed_email' => env('RESTRICTED_CAMPAIGN_ALLOWED_EMAIL', 'arturcamposba99@gmail.com'),
];


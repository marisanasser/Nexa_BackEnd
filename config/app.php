<?php

declare(strict_types=1);

use App\Domain\Campaign\Providers\CampaignServiceProvider;
use App\Domain\Chat\Providers\ChatServiceProvider;
use App\Domain\Contract\Providers\ContractServiceProvider;
use App\Domain\Payment\Providers\PaymentServiceProvider;
use App\Domain\User\Providers\UserServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;

$frontendEnv = env('FRONTEND_URL', 'http://localhost:3000');
$frontendRaw = trim((string) $frontendEnv);
$frontendRaw = trim($frontendRaw, " \t\n\r\0\x0B\"'`");
$matches = [];
if (preg_match('#^(https?://[^\s"\'`]+)#i', $frontendRaw, $matches)) {
    $frontendCandidate = $matches[1];
} else {
    $frontendCandidate = preg_split('/\s+/', $frontendRaw)[0];
    if (!preg_match('#^https?://#i', $frontendCandidate)) {
        $frontendCandidate = 'https://'.$frontendCandidate;
    }
}
$frontendUrl = rtrim($frontendCandidate, '/');

return [
    'name' => env('APP_NAME', 'Laravel'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'frontend_url' => $frontendUrl,

    'asset_url' => env('ASSET_URL'),

    'timezone' => 'UTC',

    'locale' => 'en',

    'fallback_locale' => 'en',

    'faker_locale' => 'en_US',

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    'maintenance' => [
        'driver' => 'file',
    ],

    'providers' => ServiceProvider::defaultProviders()->merge([
        SocialiteServiceProvider::class,

        AppServiceProvider::class,
        AuthServiceProvider::class,
        BroadcastServiceProvider::class,
        EventServiceProvider::class,
        RouteServiceProvider::class,

        // Domain Service Providers
        DomainServiceProvider::class,
        CampaignServiceProvider::class,
        ChatServiceProvider::class,
        ContractServiceProvider::class,
        PaymentServiceProvider::class,
        UserServiceProvider::class,

        // GCS provider removed - causing initialization errors
        // App\Providers\GoogleCloudStorageServiceProvider::class,
    ])->toArray(),

    'aliases' => Facade::defaultAliases()->merge([
        'Socialite' => Socialite::class,
    ])->toArray(),
];

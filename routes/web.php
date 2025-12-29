<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// TEMPORARY: Update Premium
Route::get('/update-premium-fix', function () {
    try {
        \DB::update("UPDATE users SET has_premium = true, premium_expires_at = NOW() + INTERVAL '10 years' WHERE email = 'brand_test@nexa.com'");
        \DB::update("UPDATE users SET has_premium = true, premium_expires_at = NOW() + INTERVAL '10 years' WHERE email = 'brand.teste@nexacreators.com.br'");
        \DB::update("UPDATE users SET has_premium = true, premium_expires_at = NOW() + INTERVAL '10 years' WHERE email = 'creator.teste@nexacreators.com.br'");
        return 'Premium updated for test users!';
    } catch (\Exception $e) {
        return $e->getMessage();
    }
});

Route::get('/debug-stripe', function () {
    return response()->json([
        'config_stripe' => config('services.stripe'),
        'env_secret_raw' => env('STRIPE_SECRET_KEY'),
        'env_secret_fallback' => env('STRIPE_SECRET'),
        'server_secret' => $_SERVER['STRIPE_SECRET_KEY'] ?? null,
        'getenv_secret' => getenv('STRIPE_SECRET_KEY'),
        'path' => base_path('config/services.php'),
        'file_content' => file_exists(base_path('config/services.php')) 
            ? substr(file_get_contents(base_path('config/services.php')), 0, 2000) 
            : 'File not found',
    ]);
});

Route::get('/storage/{path}', function ($path) {
    $filePath = storage_path('app/public/'.$path);

    if (! file_exists($filePath)) {
        abort(404);
    }

    $file = new \Illuminate\Http\File($filePath);
    $mimeType = $file->getMimeType();

    return response()->file($filePath, [
        'Content-Type' => $mimeType,
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
        'Access-Control-Allow-Credentials' => 'true',
    ]);
})->where('path', '.*');

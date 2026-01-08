<?php

declare(strict_types=1);

use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// EMERGENCY SYSTEM UPDATE - PLACE AT THE TOP
Route::get('/system-update', function () {
    try {
        DB::transaction(function (): void {
            // 1. Reset Avatars to clean broken URLs
            DB::table('users')->update(['avatar_url' => null]);
            DB::table('portfolios')->update(['profile_picture' => null]);

            // 2. Reset Plans to correct values
            DB::table('subscription_plans')->truncate();
            DB::table('subscription_plans')->insert([
                [
                    'name' => 'Plano Mensal',
                    'description' => 'Ideal para testar e começar sua jornada UGC.',
                    'price' => 39.90,
                    'duration_months' => 1,
                    'features' => json_encode(['Acesso a todas as campanhas', 'Portfólio profissional integrado', 'Pagamentos seguros e rápidos']),
                    'sort_order' => 1,
                    'is_active' => true,
                    'stripe_price_id' => null, 'stripe_product_id' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'name' => 'Plano Semestral',
                    'description' => 'Para quem já tem consistência e quer economizar.',
                    'price' => 179.40,
                    'duration_months' => 6,
                    'features' => json_encode(['Acesso a todas as campanhas', 'Portfólio profissional integrado', 'Pagamentos seguros e rápidos', 'Suporte prioritário via WhatsApp']),
                    'sort_order' => 2,
                    'is_active' => true,
                    'stripe_price_id' => null, 'stripe_product_id' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'name' => 'Plano Anual',
                    'description' => 'O melhor custo-benefício para viver de UGC.',
                    'price' => 238.80,
                    'duration_months' => 12,
                    'features' => json_encode(['Acesso a todas as campanhas', 'Portfólio profissional integrado', 'Pagamentos seguros e rápidos', 'Suporte prioritário via WhatsApp', 'Materiais educativos exclusivos']),
                    'sort_order' => 3,
                    'is_active' => true,
                    'stripe_price_id' => null, 'stripe_product_id' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ],
            ]);
        });

        return 'SISTEMA ATUALIZADO COM SUCESSO. Avatars limpos e Preços dos Planos Corrigidos. Por favor, faça seu upload agora.';
    } catch (Throwable $e) {
        return 'ERRO FATAL: '.$e->getMessage();
    }
});

Route::get('/', fn () => ['Laravel' => app()->version()]);

// TEMPORARY: Debug routes
Route::get('/debug-env', fn () => [
    'is_gcs_configured' => !empty(env('GOOGLE_CLOUD_STORAGE_BUCKET')),
    'disk' => config('filesystems.default'),
    'app_url' => config('app.url'),
]);

Route::get('/storage/{path}', function ($path) {
    if (str_starts_with($path, 'http')) {
        return redirect($path);
    }

    $filePath = storage_path('app/public/'.$path);
    if (!file_exists($filePath)) {
        abort(404);
    }

    $file = new File($filePath);

    return response()->file($filePath, ['Content-Type' => $file->getMimeType(), 'Access-Control-Allow-Origin' => '*']);
})->where('path', '.*');

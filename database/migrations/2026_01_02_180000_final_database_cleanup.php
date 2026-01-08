<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Clean up mangled avatar URLs for all users
        DB::statement("UPDATE users SET avatar_url = NULL WHERE avatar_url LIKE '%/storage/https://%'");
        DB::statement("UPDATE portfolios SET profile_picture = NULL WHERE profile_picture LIKE '%/storage/https://%'");

        // 2. Fix Subscription Plans to original prices
        DB::table('subscription_plans')->truncate();

        $plans = [
            [
                'name' => 'Plano Mensal',
                'description' => 'Ideal para testar e começar sua jornada UGC.',
                'price' => 39.90,
                'duration_months' => 1,
                'features' => json_encode([
                    'Acesso a todas as campanhas',
                    'Portfólio profissional integrado',
                    'Pagamentos seguros e rápidos',
                ]),
                'sort_order' => 1,
                'created_at' => now(), 'updated_at' => now(),
                'is_active' => true,
                'stripe_price_id' => null, 'stripe_product_id' => null,
            ],
            [
                'name' => 'Plano Semestral',
                'description' => 'Para quem já tem consistência e quer economizar.',
                'price' => 29.90 * 6, // 179.40
                'duration_months' => 6,
                'features' => json_encode([
                    'Acesso a todas as campanhas',
                    'Portfólio profissional integrado',
                    'Pagamentos seguros e rápidos',
                    'Suporte prioritário via WhatsApp',
                ]),
                'sort_order' => 2,
                'created_at' => now(), 'updated_at' => now(),
                'is_active' => true,
                'stripe_price_id' => null, 'stripe_product_id' => null,
            ],
            [
                'name' => 'Plano Anual',
                'description' => 'O melhor custo-benefício para viver de UGC.',
                'price' => 19.90 * 12, // 238.80
                'duration_months' => 12,
                'features' => json_encode([
                    'Acesso a todas as campanhas',
                    'Portfólio profissional integrado',
                    'Pagamentos seguros e rápidos',
                    'Suporte prioritário via WhatsApp',
                    'Materiais educativos exclusivos',
                ]),
                'sort_order' => 3,
                'created_at' => now(), 'updated_at' => now(),
                'is_active' => true,
                'stripe_price_id' => null, 'stripe_product_id' => null,
            ],
        ];

        DB::table('subscription_plans')->insert($plans);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Internal cleanups don't need reverse
    }
};

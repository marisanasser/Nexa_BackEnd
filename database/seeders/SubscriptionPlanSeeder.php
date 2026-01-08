<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('subscription_plans')->truncate();

        $plans = [
            [
                'name' => 'Plano Mensal',
                'description' => 'Ideal para testar e começar sua jornada UGC.',
                'price' => 39.90,
                'duration_months' => 1,
                'is_active' => true,
                'features' => json_encode([
                    'Acesso a todas as campanhas',
                    'Portfólio profissional integrado',
                    'Pagamentos seguros e rápidos',
                ]),
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
                'stripe_price_id' => null,
                'stripe_product_id' => null,
            ],
            [
                'name' => 'Plano Semestral',
                'description' => 'Para quem já tem consistência e quer economizar.',
                'price' => 179.40, // 29.90 * 6
                'duration_months' => 6,
                'is_active' => true,
                'features' => json_encode([
                    'Acesso a todas as campanhas',
                    'Portfólio profissional integrado',
                    'Pagamentos seguros e rápidos',
                    'Suporte prioritário via WhatsApp',
                ]),
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
                'stripe_price_id' => null,
                'stripe_product_id' => null,
            ],
            [
                'name' => 'Plano Anual',
                'description' => 'O melhor custo-benefício para viver de UGC.',
                'price' => 238.80, // 19.90 * 12
                'duration_months' => 12,
                'is_active' => true,
                'features' => json_encode([
                    'Acesso a todas as campanhas',
                    'Portfólio profissional integrado',
                    'Pagamentos seguros e rápidos',
                    'Suporte prioritário via WhatsApp',
                    'Materiais educativos exclusivos',
                ]),
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
                'stripe_price_id' => null,
                'stripe_product_id' => null,
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('subscription_plans')->insert($plan);
        }
    }
}

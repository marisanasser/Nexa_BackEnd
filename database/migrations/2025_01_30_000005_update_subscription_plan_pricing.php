<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    
    public function up(): void
    {
        
        DB::table('subscription_plans')->where('name', 'Plano Mensal')->update([
            'name' => 'Plano Mensal',
            'description' => 'Assinatura de 1 mês do Nexa Premium',
            'price' => 39.90,
            'duration_months' => 1,
            'features' => json_encode([
                'Aplicações ilimitadas em campanhas',
                'Acesso a todas as campanhas exclusivas',
                'Prioridade na aprovação de campanhas',
                'Suporte premium via chat',
                'Ferramentas avançadas de criação de conteúdo'
            ]),
            'updated_at' => now(),
        ]);

        DB::table('subscription_plans')->where('name', 'Plano Semestral')->update([
            'name' => 'Six-Month Plan',
            'description' => 'Assinatura de 1 mês do Nexa Premium',
            'price' => 29.90,
            'duration_months' => 1,
            'features' => json_encode([
                'Aplicações ilimitadas em campanhas',
                'Acesso a todas as campanhas exclusivas',
                'Prioridade na aprovação de campanhas',
                'Suporte premium via chat',
                'Ferramentas avançadas de criação de conteúdo'
            ]),
            'updated_at' => now(),
        ]);

        DB::table('subscription_plans')->where('name', 'Plano Anual')->update([
            'name' => 'Annual Plan',
            'description' => '12-month subscription to Nexa Premium',
            'price' => 19.90,
            'duration_months' => 12,
            'features' => json_encode([
                'Aplicações ilimitadas em campanhas',
                'Acesso a todas as campanhas exclusivas',
                'Prioridade na aprovação de campanhas',
                'Suporte premium via chat',
                'Ferramentas avançadas de criação de conteúdo',
                'Melhor valor - economia máxima comparado ao plano mensal'
            ]),
            'updated_at' => now(),
        ]);
    }

    
    public function down(): void
    {
        
        DB::table('subscription_plans')->where('name', 'Plano Mensal')->update([
            'price' => 29.99,
            'updated_at' => now(),
        ]);

        DB::table('subscription_plans')->where('name', 'Six-Month Plan')->update([
            'name' => 'Plano Semestral',
            'price' => 119.94,
            'duration_months' => 6,
            'features' => json_encode([
                'Aplicações ilimitadas em campanhas',
                'Acesso a todas as campanhas exclusivas',
                'Prioridade na aprovação de campanhas',
                'Suporte premium via chat',
                'Ferramentas avançadas de criação de conteúdo',
                'Economia de 33% comparado ao plano mensal'
            ]),
            'updated_at' => now(),
        ]);

        DB::table('subscription_plans')->where('name', 'Annual Plan')->update([
            'name' => 'Plano Anual',
            'price' => 1799.28,
            'duration_months' => 72,
            'features' => json_encode([
                'Aplicações ilimitadas em campanhas',
                'Acesso a todas as campanhas exclusivas',
                'Prioridade na aprovação de campanhas',
                'Suporte premium via chat',
                'Ferramentas avançadas de criação de conteúdo',
                'Economia de 17% comparado ao plano mensal',
                'Acesso garantido por 6 anos'
            ]),
            'updated_at' => now(),
        ]);
    }
}; 
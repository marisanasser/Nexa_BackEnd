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
            'updated_at' => now(),
        ]);

        
        DB::table('subscription_plans')->whereIn('name', ['Plano Semestral', 'Six-Month Plan'])->update([
            'name' => 'Plano Semestral',
            'description' => 'Assinatura de 6 meses do Nexa Premium - R$ 29,90/mês',
            'price' => 29.90,
            'duration_months' => 6,
            'updated_at' => now(),
        ]);

        
        DB::table('subscription_plans')->whereIn('name', ['Plano Anual', 'Annual Plan'])->update([
            'name' => 'Plano Anual',
            'description' => 'Assinatura de 12 meses do Nexa Premium - R$ 19,90/mês',
            'price' => 19.90,
            'duration_months' => 12,
            'updated_at' => now(),
        ]);

        
        DB::table('subscription_plans')->update([
            'stripe_price_id' => null,
            'stripe_product_id' => null,
            'updated_at' => now(),
        ]);
    }

    
    public function down(): void
    {
        
        DB::table('subscription_plans')->where('name', 'Plano Mensal')->update([
            'price' => 39.90,
            'updated_at' => now(),
        ]);

        DB::table('subscription_plans')->where('name', 'Plano Semestral')->update([
            'price' => 179.40,
            'updated_at' => now(),
        ]);

        DB::table('subscription_plans')->where('name', 'Plano Anual')->update([
            'price' => 238.80,
            'updated_at' => now(),
        ]);
    }
};


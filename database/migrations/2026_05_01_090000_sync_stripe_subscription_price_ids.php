<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const PLANS = [
        'Plano Mensal' => [
            'price' => 39.90,
            'duration_months' => 1,
            'stripe_product_id' => 'prod_U5xG4211uPCXGo',
            'stripe_price_id' => 'price_1T7lLUA91THu9AMO7x0XDCB8',
        ],
        'Plano Semestral' => [
            'price' => 179.40,
            'duration_months' => 6,
            'stripe_product_id' => 'prod_U5xG2qkP1R2XMR',
            'stripe_price_id' => 'price_1TS4mfA91THu9AMOnQ4JNfGq',
        ],
        'Plano Anual' => [
            'price' => 238.80,
            'duration_months' => 12,
            'stripe_product_id' => 'prod_U5xGRHkUobamc3',
            'stripe_price_id' => 'price_1TS4meA91THu9AMOjZiel88m',
        ],
    ];

    public function up(): void
    {
        foreach (self::PLANS as $name => $plan) {
            DB::table('subscription_plans')
                ->where('name', $name)
                ->update([
                    ...$plan,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PLANS) as $name) {
            DB::table('subscription_plans')
                ->where('name', $name)
                ->update([
                    'stripe_product_id' => null,
                    'stripe_price_id' => null,
                    'updated_at' => now(),
                ]);
        }
    }
};

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Payment\WithdrawalMethod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WithdrawalMethodSeeder extends Seeder
{
    public function run(): void
    {
        $stripeMethods = [
            [
                'code' => WithdrawalMethod::METHOD_STRIPE_CONNECT,
                'name' => 'Stripe Connect',
                'description' => 'Saque para conta conectada no Stripe.',
                'min_amount' => 10.00,
                'max_amount' => 100000.00,
                'processing_time' => '1-2 dias uteis',
                'fee' => 0.00,
                'is_active' => true,
                'required_fields' => json_encode([]),
                'field_config' => json_encode([]),
                'sort_order' => 1,
            ],
            [
                'code' => WithdrawalMethod::METHOD_STRIPE_CONNECT_BANK_ACCOUNT,
                'name' => 'Stripe Conta Bancaria',
                'description' => 'Saque para conta bancaria conectada no Stripe.',
                'min_amount' => 10.00,
                'max_amount' => 100000.00,
                'processing_time' => '1-2 dias uteis',
                'fee' => 0.00,
                'is_active' => true,
                'required_fields' => json_encode([]),
                'field_config' => json_encode([]),
                'sort_order' => 2,
            ],
            [
                'code' => WithdrawalMethod::METHOD_STRIPE_CARD,
                'name' => 'Stripe Card',
                'description' => 'Saque via metodo Stripe.',
                'min_amount' => 10.00,
                'max_amount' => 100000.00,
                'processing_time' => '1-2 dias uteis',
                'fee' => 0.00,
                'is_active' => true,
                'required_fields' => json_encode([]),
                'field_config' => json_encode([]),
                'sort_order' => 3,
            ],
        ];

        foreach ($stripeMethods as $method) {
            DB::table('withdrawal_methods')->updateOrInsert(
                ['code' => $method['code']],
                array_merge($method, ['updated_at' => now(), 'created_at' => now()])
            );
        }

        DB::table('withdrawal_methods')
            ->whereIn('code', ['pagarme_bank_transfer', 'pagarme_account', 'bank_transfer', 'pix'])
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }
}


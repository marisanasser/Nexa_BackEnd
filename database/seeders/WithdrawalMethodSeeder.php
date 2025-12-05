<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WithdrawalMethodSeeder extends Seeder
{
    
    public function run(): void
    {
        $methods = [
            [
                'code' => 'pagarme_bank_transfer',
                'name' => 'Transferência Bancária via Pagar.me',
                'description' => '123',
                'min_amount' => 10.00,
                'max_amount' => 10000.00,
                'processing_time' => '1-2 dias úteis',
                'fee' => 0.00,
                'is_active' => true,
                'required_fields' => json_encode([]), 
                'field_config' => json_encode([]), 
                'sort_order' => 1,
            ],
            [
                'code' => 'pix',
                'name' => 'PIX',
                'description' => 'Transferência instantânea via PIX para CPF/CNPJ',
                'min_amount' => 5.00,
                'max_amount' => 5000.00,
                'processing_time' => 'Até 24 horas',
                'fee' => 2.50,
                'is_active' => true,
                'required_fields' => json_encode(['pix_key', 'pix_key_type']),
                'field_config' => json_encode([
                    'pix_key_types' => ['cpf', 'cnpj', 'email', 'phone', 'random']
                ]),
                'sort_order' => 2,
            ],
            [
                'code' => 'pagarme_account',
                'name' => 'Conta Pagar.me',
                'description' => 'Saque para conta Pagar.me (sem taxa)',
                'min_amount' => 5.00,
                'max_amount' => 5000.00,
                'processing_time' => 'Até 24 horas',
                'fee' => 0.00,
                'is_active' => true,
                'required_fields' => json_encode([]),
                'field_config' => json_encode([]),
                'sort_order' => 3,
            ],
        ];

        foreach ($methods as $method) {
            if (!DB::table('withdrawal_methods')->where('code', 'pagame_bank_transfer')->exists()) {
    DB::table('withdrawal_methods')->insert($method);
}
        }
    }
}

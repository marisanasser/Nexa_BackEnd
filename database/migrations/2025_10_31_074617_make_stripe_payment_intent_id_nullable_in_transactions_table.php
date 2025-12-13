<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    
    public function up(): void
    {
        
        DB::statement('DROP INDEX IF EXISTS transactions_stripe_payment_intent_id_unique');
        
        if (DB::getDriverName() !== 'sqlite') {
            
            DB::statement('ALTER TABLE transactions ALTER COLUMN stripe_payment_intent_id DROP NOT NULL');
        }
    }

    
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            
            DB::statement('ALTER TABLE transactions ALTER COLUMN stripe_payment_intent_id SET NOT NULL');
        }
        
        
        DB::statement('
            CREATE UNIQUE INDEX transactions_stripe_payment_intent_id_unique 
            ON transactions(stripe_payment_intent_id) 
            WHERE stripe_payment_intent_id IS NOT NULL
        ');
    }
};

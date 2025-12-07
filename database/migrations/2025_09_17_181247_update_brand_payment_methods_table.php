<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('brand_payment_methods', function (Blueprint $table) {
            
            $table->string('pagarme_customer_id')->nullable()->change();
            $table->string('pagarme_card_id')->nullable()->change();
        });
    }

    
    public function down(): void
    {
        Schema::table('brand_payment_methods', function (Blueprint $table) {
            
            $table->string('pagarme_customer_id')->nullable(false)->change();
            $table->string('pagarme_card_id')->nullable(false)->change();
        });
    }
};

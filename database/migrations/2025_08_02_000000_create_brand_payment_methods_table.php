<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::create('brand_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('pagarme_customer_id')->nullable();
            $table->string('pagarme_card_id')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last4')->nullable();
            $table->string('card_holder_name');
            $table->string('card_hash')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['user_id', 'card_hash']);
            $table->index(['user_id', 'is_default']);
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('brand_payment_methods');
    }
}; 
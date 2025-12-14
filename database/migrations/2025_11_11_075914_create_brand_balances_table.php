<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->decimal('available_balance', 10, 2)->default(0);
            $table->decimal('total_funded', 10, 2)->default(0);
            $table->decimal('total_spent', 10, 2)->default(0);
            $table->timestamps();

            $table->foreign('brand_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_balances');
    }
};

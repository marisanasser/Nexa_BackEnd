<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('brand_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->decimal('available_balance', 10, 2)->default(0); // Available for making offers/contracts
            $table->decimal('total_funded', 10, 2)->default(0); // Total amount funded to platform
            $table->decimal('total_spent', 10, 2)->default(0); // Total amount spent on offers/contracts
            $table->timestamps();

            $table->foreign('brand_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique('brand_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_balances');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creator_id');
            $table->decimal('amount', 10, 2);
            $table->decimal('platform_fee', 5, 2)->default(5.00)->comment('Platform fee percentage (e.g., 5.00 for 5%)');
            $table->decimal('fixed_fee', 10, 2)->default(5.00)->comment('Fixed platform fee amount (e.g., 5.00 for R$5)');
            $table->string('withdrawal_method');
            $table->json('withdrawal_details');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->string('transaction_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->foreign('creator_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['creator_id', 'status']);
            $table->index(['status', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};

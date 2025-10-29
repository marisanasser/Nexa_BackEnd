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
        Schema::table('job_payments', function (Blueprint $table) {
            $table->string('stripe_transfer_id')->nullable()->after('transaction_id');
            $table->string('stripe_balance_tx_id')->nullable()->after('stripe_transfer_id');

            $table->index(['stripe_transfer_id']);
            $table->index(['stripe_balance_tx_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_payments', function (Blueprint $table) {
            $table->dropIndex(['stripe_transfer_id']);
            $table->dropIndex(['stripe_balance_tx_id']);
            $table->dropColumn(['stripe_transfer_id', 'stripe_balance_tx_id']);
        });
    }
};



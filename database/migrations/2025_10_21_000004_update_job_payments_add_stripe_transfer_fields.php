<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('job_payments', function (Blueprint $table): void {
            $table->string('stripe_transfer_id')->nullable()->after('transaction_id');
            $table->string('stripe_balance_tx_id')->nullable()->after('stripe_transfer_id');

            $table->index(['stripe_transfer_id']);
            $table->index(['stripe_balance_tx_id']);
        });
    }

    public function down(): void
    {
        Schema::table('job_payments', function (Blueprint $table): void {
            $table->dropIndex(['stripe_transfer_id']);
            $table->dropIndex(['stripe_balance_tx_id']);
            $table->dropColumn(['stripe_transfer_id', 'stripe_balance_tx_id']);
        });
    }
};

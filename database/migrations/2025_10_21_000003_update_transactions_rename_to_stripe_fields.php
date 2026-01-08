<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('transactions', 'pagarme_transaction_id')) {
                $table->renameColumn('pagarme_transaction_id', 'stripe_payment_intent_id');
            }
            $table->string('stripe_charge_id')->nullable()->after('stripe_payment_intent_id');
            $table->json('metadata')->nullable()->after('payment_data');

            $table->index(['stripe_payment_intent_id']);
            $table->index(['stripe_charge_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['stripe_payment_intent_id']);
            $table->dropIndex(['stripe_charge_id']);
            $table->dropColumn(['stripe_charge_id', 'metadata']);
            if (Schema::hasColumn('transactions', 'stripe_payment_intent_id')) {
                $table->renameColumn('stripe_payment_intent_id', 'pagarme_transaction_id');
            }
        });
    }
};

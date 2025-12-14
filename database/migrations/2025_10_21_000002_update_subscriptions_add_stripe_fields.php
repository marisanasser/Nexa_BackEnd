<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->after('transaction_id');
            $table->string('stripe_latest_invoice_id')->nullable()->after('stripe_subscription_id');
            $table->string('stripe_status')->nullable()->after('stripe_latest_invoice_id');
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');

            $table->index(['stripe_subscription_id']);
            $table->index(['stripe_latest_invoice_id']);
            $table->index(['stripe_status']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropIndex(['stripe_latest_invoice_id']);
            $table->dropIndex(['stripe_status']);
            $table->dropColumn(['stripe_subscription_id', 'stripe_latest_invoice_id', 'stripe_status', 'cancellation_reason']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('brand_payment_methods', function (Blueprint $table): void {
            $table->string('stripe_customer_id')->nullable()->after('pagarme_card_id');
            $table->string('stripe_payment_method_id')->nullable()->after('stripe_customer_id');
            $table->string('stripe_setup_intent_id')->nullable()->after('stripe_payment_method_id');

            $table->index('stripe_customer_id');
            $table->index('stripe_payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('brand_payment_methods', function (Blueprint $table): void {
            $table->dropIndex(['stripe_customer_id']);
            $table->dropIndex(['stripe_payment_method_id']);
            $table->dropColumn(['stripe_customer_id', 'stripe_payment_method_id', 'stripe_setup_intent_id']);
        });
    }
};

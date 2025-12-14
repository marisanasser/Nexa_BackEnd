<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('stripe_product_id')->nullable()->after('sort_order');
            $table->string('stripe_price_id')->nullable()->after('stripe_product_id');

            $table->index(['stripe_product_id']);
            $table->index(['stripe_price_id']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropIndex(['stripe_product_id']);
            $table->dropIndex(['stripe_price_id']);
            $table->dropColumn(['stripe_product_id', 'stripe_price_id']);
        });
    }
};

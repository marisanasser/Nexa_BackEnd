<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('stripe_account_id')->nullable()->after('premium_expires_at');
            $table->string('stripe_payment_method_id')->nullable()->after('stripe_account_id');
            $table->enum('stripe_verification_status', ['pending', 'verified', 'failed'])->default('pending')->after('stripe_payment_method_id');

            $table->index(['stripe_account_id']);
            $table->index(['stripe_payment_method_id']);
            $table->index(['stripe_verification_status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['stripe_account_id']);
            $table->dropIndex(['stripe_payment_method_id']);
            $table->dropIndex(['stripe_verification_status']);
            $table->dropColumn(['stripe_account_id', 'stripe_payment_method_id', 'stripe_verification_status']);
        });
    }
};

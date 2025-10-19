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
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_account_id')->nullable()->after('premium_expires_at');
            $table->enum('stripe_verification_status', ['pending', 'verified', 'failed'])->default('pending')->after('stripe_account_id');
            
            $table->index(['stripe_account_id']);
            $table->index(['stripe_verification_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_account_id']);
            $table->dropIndex(['stripe_verification_status']);
            $table->dropColumn(['stripe_account_id', 'stripe_verification_status']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'stripe_payment_method_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('stripe_payment_method_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'stripe_payment_method_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('stripe_payment_method_id');
            });
        }
    }
};

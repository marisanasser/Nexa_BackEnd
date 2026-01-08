<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('status');
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'active', 'completed', 'cancelled', 'disputed'])->default('active')->after('requirements');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('status');
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->enum('status', ['active', 'completed', 'cancelled', 'disputed'])->default('active')->after('requirements');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->enum('workflow_status', [
                'active',
                'waiting_review',
                'payment_available',
                'payment_withdrawn',
                'terminated',
            ])->default('active')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('workflow_status');
        });
    }
};

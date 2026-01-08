<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->boolean('is_barter')->default(false)->after('rejection_reason');
            $table->text('barter_description')->nullable()->after('is_barter');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn(['is_barter', 'barter_description']);
        });
    }
};

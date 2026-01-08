<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaign_timelines', function (Blueprint $table): void {
            $table->integer('extension_days')->default(0);
            $table->text('extension_reason')->nullable();
            $table->datetime('extended_at')->nullable();
            $table->unsignedBigInteger('extended_by')->nullable();
            $table->foreign('extended_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_timelines', function (Blueprint $table): void {
            $table->dropForeign(['extended_by']);
            $table->dropColumn(['extension_days', 'extension_reason', 'extended_at', 'extended_by']);
        });
    }
};

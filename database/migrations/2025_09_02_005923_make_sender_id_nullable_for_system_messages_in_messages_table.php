<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if ('sqlite' !== DB::getDriverName()) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->dropForeign(['sender_id']);

                $table->unsignedBigInteger('sender_id')->nullable()->change();

                $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if ('sqlite' !== DB::getDriverName()) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->dropForeign(['sender_id']);

                $table->unsignedBigInteger('sender_id')->nullable(false)->change();

                $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }
};

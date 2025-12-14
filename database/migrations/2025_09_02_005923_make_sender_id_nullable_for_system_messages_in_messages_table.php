<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('messages', function (Blueprint $table) {

                $table->dropForeign(['sender_id']);

                $table->unsignedBigInteger('sender_id')->nullable()->change();

                $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('messages', function (Blueprint $table) {

                $table->dropForeign(['sender_id']);

                $table->unsignedBigInteger('sender_id')->nullable(false)->change();

                $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }
};

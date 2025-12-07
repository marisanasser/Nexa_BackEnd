<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_token', 2048)->nullable()->change();
            $table->string('google_refresh_token', 2048)->nullable()->change();
        });
    }

    
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_token', 255)->nullable()->change();
            $table->string('google_refresh_token', 255)->nullable()->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_system_message')->default(false)->after('message_type');
        });
    }

    
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('is_system_message');
        });
    }
};

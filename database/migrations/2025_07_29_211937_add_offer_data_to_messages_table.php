<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->json('offer_data')->nullable()->after('message_type');
        });
    }

    
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('offer_data');
        });
    }
};

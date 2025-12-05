<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('image_url');
            $table->string('attach_file')->nullable()->after('logo');
        });
    }

    
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['logo', 'attach_file']);
        });
    }
};

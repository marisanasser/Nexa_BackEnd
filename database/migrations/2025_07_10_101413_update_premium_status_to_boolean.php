<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            
            $table->boolean('has_premium')->default(false)->after('language');
        });

        
        DB::table('users')->where('premium_status', 'premium')->update(['has_premium' => true]);
        
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('premium_status');
        });
    }

    
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            
            $table->string('premium_status')->nullable()->default('free')->after('language');
        });

        
        DB::table('users')->where('has_premium', true)->update(['premium_status' => 'premium']);
        DB::table('users')->where('has_premium', false)->update(['premium_status' => 'free']);

        
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('has_premium');
        });
    }
}; 
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            
            DB::statement('ALTER TABLE users ALTER COLUMN birth_date DROP NOT NULL');
            
            
            DB::statement("UPDATE users SET birth_date = NULL WHERE birth_date = '1990-01-01'");
            DB::statement("UPDATE users SET languages = NULL WHERE languages::text = '[\"English\"]'");
            DB::statement("UPDATE users SET language = NULL WHERE language = 'English'");
        }
    }

    
    public function down(): void
    {
        
        
    }
};
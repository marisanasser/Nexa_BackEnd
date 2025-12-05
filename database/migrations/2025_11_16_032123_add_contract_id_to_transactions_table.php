<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'contract_id')) {
                $table->unsignedBigInteger('contract_id')->nullable()->after('user_id');
                
                
                if (DB::getDriverName() !== 'sqlite') {
                    $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('set null');
                }
            }
        });
    }

    
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['contract_id']);
            }
            $table->dropColumn('contract_id');
        });
    }
};

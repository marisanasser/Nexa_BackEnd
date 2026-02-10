<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // High-level phase tracking
            $table->enum('phase', [
                'contracting', 
                'alignment', 
                'creation', 
                'production', 
                'approval', 
                'payment', 
                'finished'
            ])->default('contracting')->after('workflow_status');

            // Current active milestone
            $table->foreignId('current_milestone_id')
                  ->nullable()
                  ->after('phase')
                  ->constrained('contract_milestones')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['current_milestone_id']);
            $table->dropColumn(['phase', 'current_milestone_id']);
        });
    }
};

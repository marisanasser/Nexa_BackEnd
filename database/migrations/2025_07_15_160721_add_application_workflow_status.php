<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('campaign_applications', function (Blueprint $table) {
            
            $table->enum('workflow_status', [
                'first_contact_pending',    
                'agreement_in_progress',    
                'agreement_finalized'       
            ])->default('first_contact_pending')->after('status');
            
            
            $table->timestamp('first_contact_at')->nullable()->after('workflow_status');
            
            
            $table->timestamp('agreement_finalized_at')->nullable()->after('first_contact_at');
        });
    }

    
    public function down(): void
    {
        Schema::table('campaign_applications', function (Blueprint $table) {
            $table->dropColumn(['workflow_status', 'first_contact_at', 'agreement_finalized_at']);
        });
    }
}; 
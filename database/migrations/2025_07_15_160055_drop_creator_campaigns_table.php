<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::dropIfExists('creator_campaigns');
    }

    
    public function down(): void
    {
        Schema::create('creator_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('campaign_id')->constrained('campaigns')->onDelete('cascade');
            $table->timestamps();
            
            
            $table->unique(['creator_id', 'campaign_id']);
        });
    }
};

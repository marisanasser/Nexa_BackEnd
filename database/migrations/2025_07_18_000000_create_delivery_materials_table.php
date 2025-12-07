<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::create('delivery_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->onDelete('cascade');
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('brand_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('milestone_id')->nullable()->constrained('campaign_timelines')->onDelete('set null');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_type');
            $table->bigInteger('file_size');
            $table->enum('media_type', ['image', 'video', 'document', 'other'])->default('other');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('rejection_reason')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            
            
            $table->index(['contract_id', 'status']);
            $table->index(['creator_id', 'status']);
            $table->index(['brand_id', 'status']);
            $table->index(['milestone_id', 'status']);
            $table->index('status');
            $table->index('submitted_at');
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('delivery_materials');
    }
}; 
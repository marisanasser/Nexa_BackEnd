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
        Schema::create('contract_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'submitted', 'changes_requested', 'approved'])->default('pending');
            $table->json('submission_data')->nullable(); // Links, attachments, etc.
            $table->text('feedback')->nullable(); // Brand feedback if changes requested
            $table->timestamp('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
            
            // Index for querying milestones by contract and order
            $table->index(['contract_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_milestones');
    }
};

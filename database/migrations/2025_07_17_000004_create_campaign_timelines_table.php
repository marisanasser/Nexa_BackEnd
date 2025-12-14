<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_timelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->onDelete('cascade');
            $table->string('milestone_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->datetime('deadline');
            $table->datetime('completed_at')->nullable();
            $table->string('status')->default('pending');
            $table->text('comment')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_size')->nullable();
            $table->string('file_type')->nullable();
            $table->text('justification')->nullable();
            $table->boolean('is_delayed')->default(false);
            $table->datetime('delay_notified_at')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'milestone_type']);
            $table->index(['status', 'is_delayed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_timelines');
    }
};

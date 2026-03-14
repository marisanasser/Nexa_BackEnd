<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_text_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('current_title');
            $table->text('current_description');
            $table->string('suggested_title')->nullable();
            $table->text('suggested_description')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['campaign_id', 'status', 'created_at'], 'campaign_text_suggestions_campaign_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_text_suggestions');
    }
};

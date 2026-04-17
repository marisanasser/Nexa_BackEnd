<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('creator_balance_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('creator_id');
            $table->decimal('amount', 10, 2);
            $table->enum('kind', ['credit', 'debit'])->default('credit');
            $table->boolean('affects_available')->default(true);
            $table->string('reason', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('creator_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['creator_id', 'is_active']);
            $table->index(['reason']);
            $table->index(['kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_balance_adjustments');
    }
};


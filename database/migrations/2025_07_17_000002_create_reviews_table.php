<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('reviewer_id'); 
            $table->unsignedBigInteger('reviewed_id'); 
            $table->integer('rating'); 
            $table->text('comment')->nullable();
            $table->json('rating_categories')->nullable(); 
            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('cascade');
            $table->foreign('reviewer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_id')->references('id')->on('users')->onDelete('cascade');
            
            $table->unique(['contract_id', 'reviewer_id']); 
            $table->index(['reviewed_id', 'rating']);
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
}; 
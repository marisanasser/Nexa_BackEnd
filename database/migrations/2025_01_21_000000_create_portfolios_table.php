<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title')->nullable();
            $table->text('bio')->nullable();
            $table->string('profile_picture')->nullable();
            $table->timestamps();

            $table->unique('user_id');

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolios');
    }
};

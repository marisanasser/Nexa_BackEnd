<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_room_id');
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('message');
            $table->string('message_type')->default('text'); 
            $table->string('file_path')->nullable(); 
            $table->string('file_name')->nullable(); 
            $table->string('file_size')->nullable(); 
            $table->string('file_type')->nullable(); 
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('chat_room_id')->references('id')->on('chat_rooms')->onDelete('cascade');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            
            
            $table->index(['chat_room_id', 'created_at']);
            $table->index(['sender_id', 'created_at']);
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');

            $table->string('bank_code');
            $table->string('agencia');
            $table->string('agencia_dv')->nullable();
            $table->string('conta');
            $table->string('conta_dv')->nullable();

            $table->string('cpf');
            $table->string('name');

            $table->string('recipient_id')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table): void {
            // Status do chat: active, completed, archived
            $table->string('chat_status')->default('active')->after('is_active');
            
            // Data de arquivamento
            $table->timestamp('archived_at')->nullable()->after('chat_status');
            
            // Motivo do encerramento (completado, cancelado, etc)
            $table->string('closure_reason')->nullable()->after('archived_at');
            
            // Resumo da campanha para relatórios
            $table->json('campaign_summary')->nullable()->after('closure_reason');
            
            // Índice para busca rápida por status
            $table->index('chat_status');
        });
    }

    public function down(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table): void {
            $table->dropIndex(['chat_status']);
            $table->dropColumn([
                'chat_status',
                'archived_at',
                'closure_reason',
                'campaign_summary',
            ]);
        });
    }
};

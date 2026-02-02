<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addIndex('users', ['role']);
        $this->addIndex('users', ['email']);
        $this->addIndex('users', ['stripe_customer_id']);

        $this->addIndex('campaigns', ['brand_id']);
        $this->addIndex('campaigns', ['category']);
        $this->addIndex('campaigns', ['status']);
        $this->addIndex('campaigns', ['is_active']);

        $this->addIndex('contracts', ['offer_id']);
        $this->addIndex('contracts', ['brand_id']);
        $this->addIndex('contracts', ['creator_id']);
        $this->addIndex('contracts', ['status']);
        $this->addIndex('contracts', ['workflow_status']);

        $this->addIndex('bids', ['campaign_id']);
        $this->addIndex('bids', ['user_id']);
        $this->addIndex('bids', ['status']);

        $this->addIndex('chat_rooms', ['campaign_id']);
        $this->addIndex('chat_rooms', ['brand_id']);
        $this->addIndex('chat_rooms', ['creator_id']);
        
        $this->addIndex('messages', ['chat_room_id']);
        $this->addIndex('messages', ['sender_id']);
    }

    private function addIndex(string $table, array $columns): void
    {
        $indexName = $table . '_' . implode('_', $columns) . '_index';
        
        $exists = DB::select("SELECT 1 FROM pg_indexes WHERE indexname = ?", [$indexName]);
        
        if (empty($exists)) {
            Schema::table($table, function (Blueprint $table) use ($columns) {
                $table->index($columns);
            });
        }
    }

    public function down(): void
    {
        // No down needed for performance indices that might already exist
    }
};

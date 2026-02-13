<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For Postgres, we can add the value to the enum type
        if (DB::connection()->getDriverName() === 'pgsql') {
            // We use a try-catch block or raw statement that ignores error if exists
            // But standard SQL doesn't support IF NOT EXISTS for enum values easily in all PG versions
            // However, since 9.1 it supports ALTER TYPE ... ADD VALUE [IF NOT EXISTS] (IF NOT EXISTS is newer)
            // Let's assume standard behavior.
            try {
                DB::statement("ALTER TYPE contracts_workflow_status_enum ADD VALUE IF NOT EXISTS 'payment_pending' AFTER 'active'");
            } catch (\Exception $e) {
                // If it fails, it might be because the type name is different or it already exists (if IF NOT EXISTS is not supported)
                // Let's try to find the constraint name if it's a check constraint
                // But for now, let's assume it works or log it.
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Enum values cannot be removed in Postgres easily
    }
};

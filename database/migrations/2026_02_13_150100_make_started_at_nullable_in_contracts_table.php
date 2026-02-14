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
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite has limited ALTER COLUMN support and in fresh setups started_at is already nullable.
            return;
        }

        // Bypass Doctrine for timestamp change on Postgres
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contracts ALTER COLUMN started_at DROP NOT NULL');
        } else {
            Schema::table('contracts', function (Blueprint $table) {
                $table->timestamp('started_at')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
             // We can't easily revert to NOT NULL without ensuring data integrity, but let's try
             // DB::statement('ALTER TABLE contracts ALTER COLUMN started_at SET NOT NULL');
        } else {
            Schema::table('contracts', function (Blueprint $table) {
                $table->timestamp('started_at')->nullable(false)->change();
            });
        }
    }
};

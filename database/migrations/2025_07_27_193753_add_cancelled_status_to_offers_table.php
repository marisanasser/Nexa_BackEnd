<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if ('sqlite' !== DB::getDriverName()) {
            DB::statement('ALTER TABLE offers DROP CONSTRAINT offers_status_check');

            DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_status_check CHECK (status = ANY (ARRAY['pending', 'accepted', 'rejected', 'expired', 'cancelled']))");
        }
    }

    public function down(): void
    {
        if ('sqlite' !== DB::getDriverName()) {
            DB::statement('ALTER TABLE offers DROP CONSTRAINT offers_status_check');

            DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_status_check CHECK (status = ANY (ARRAY['pending', 'accepted', 'rejected', 'expired']))");
        }
    }
};

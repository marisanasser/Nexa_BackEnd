<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('campaigns', 'min_age')) {
                $table->integer('min_age')->nullable()->after('max_bids');
            }
            if (! Schema::hasColumn('campaigns', 'max_age')) {
                $table->integer('max_age')->nullable()->after('min_age');
            }
            if (! Schema::hasColumn('campaigns', 'target_genders')) {
                $table->json('target_genders')->nullable()->after('max_age');
            }
            if (! Schema::hasColumn('campaigns', 'target_creator_types')) {
                $table->json('target_creator_types')->nullable()->after('target_genders');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['min_age', 'max_age', 'target_genders', 'target_creator_types']);
        });
    }
};

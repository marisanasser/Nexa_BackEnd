<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        $duplicateOffers = DB::table('offers')
            ->where('status', 'pending')
            ->select('brand_id', 'creator_id', DB::raw('MAX(id) as max_id'))
            ->groupBy('brand_id', 'creator_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateOffers as $duplicate) {

            DB::table('offers')
                ->where('brand_id', $duplicate->brand_id)
                ->where('creator_id', $duplicate->creator_id)
                ->where('status', 'pending')
                ->where('id', '!=', $duplicate->max_id)
                ->delete();
        }

        DB::statement('CREATE UNIQUE INDEX unique_pending_offer_per_brand_creator ON offers (brand_id, creator_id) WHERE status = \'pending\'');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_pending_offer_per_brand_creator');
    }
};

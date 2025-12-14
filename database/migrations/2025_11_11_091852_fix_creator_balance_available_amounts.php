<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {

        $balances = DB::table('creator_balances')
            ->where('total_earned', '>', 0)
            ->where('available_balance', '=', 0)
            ->where('pending_balance', '=', 0)
            ->whereRaw('total_withdrawn < total_earned')
            ->get();

        Log::info('Fixing creator balances with missing available_balance', [
            'count' => $balances->count(),
        ]);

        foreach ($balances as $balance) {

            $amountToAdd = $balance->total_earned - $balance->total_withdrawn;

            if ($amountToAdd > 0) {

                DB::table('creator_balances')
                    ->where('id', $balance->id)
                    ->update([
                        'available_balance' => $amountToAdd,
                        'updated_at' => now(),
                    ]);

                Log::info('Fixed creator balance', [
                    'creator_balance_id' => $balance->id,
                    'creator_id' => $balance->creator_id,
                    'total_earned' => $balance->total_earned,
                    'total_withdrawn' => $balance->total_withdrawn,
                    'amount_added_to_available' => $amountToAdd,
                ]);
            }
        }

        Log::info('Completed fixing creator balances', [
            'fixed_count' => $balances->count(),
        ]);
    }

    public function down(): void {}
};

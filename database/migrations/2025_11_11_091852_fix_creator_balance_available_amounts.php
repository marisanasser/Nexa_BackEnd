<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fixes creator balances where total_earned exists but available_balance is 0
     * This happens when the payment was processed but not moved to available_balance
     */
    public function up(): void
    {
        // Find all creator balances where:
        // - total_earned > 0
        // - available_balance = 0
        // - pending_balance = 0
        // - total_withdrawn < total_earned (meaning there's money that should be available)
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
            // Calculate the amount that should be in available_balance
            // This is the total_earned minus what has already been withdrawn
            $amountToAdd = $balance->total_earned - $balance->total_withdrawn;
            
            if ($amountToAdd > 0) {
                // Add the missing amount to available_balance
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

    /**
     * Reverse the migrations.
     * Note: This is a data fix migration, so we can't safely reverse it
     */
    public function down(): void
    {
        // This migration is not reversible as we're fixing data inconsistencies
        // If needed, you would need to manually adjust the balances
    }
};

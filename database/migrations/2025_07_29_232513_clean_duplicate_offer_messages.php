<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    
    public function up(): void
    {
        
        if (DB::table('migrations')->where('migration', '2025_07_29_232513_clean_duplicate_offer_messages')->exists()) {
            Log::info('Migration clean_duplicate_offer_messages already run, skipping');
            return;
        }

        Log::info('Starting clean_duplicate_offer_messages migration');

        
        $offerMessages = DB::table('messages')
            ->where('message_type', 'offer')
            ->whereNotNull('offer_data')
            ->get();

        Log::info('Found ' . $offerMessages->count() . ' offer messages to process');

        $offerGroups = [];
        
        
        foreach ($offerMessages as $message) {
            $offerData = json_decode($message->offer_data, true);
            $offerId = $offerData['offer_id'] ?? null;
            
            if ($offerId) {
                if (!isset($offerGroups[$offerId])) {
                    $offerGroups[$offerId] = [];
                }
                $offerGroups[$offerId][] = $message;
            }
        }

        $totalDeleted = 0;

        
        foreach ($offerGroups as $offerId => $messages) {
            if (count($messages) > 1) {
                
                usort($messages, function($a, $b) {
                    return strtotime($b->created_at) - strtotime($a->created_at);
                });
                
                
                $messagesToDelete = array_slice($messages, 1);
                
                foreach ($messagesToDelete as $messageToDelete) {
                    DB::table('messages')
                        ->where('id', $messageToDelete->id)
                        ->delete();
                    $totalDeleted++;
                }
                
                Log::info("Cleaned up " . count($messagesToDelete) . " duplicate messages for offer " . $offerId);
            }
        }

        Log::info("Migration completed. Total messages deleted: " . $totalDeleted);
    }

    
    public function down(): void
    {
        
        
        Log::warning('Cannot reverse clean_duplicate_offer_messages migration - data was permanently deleted');
    }
};

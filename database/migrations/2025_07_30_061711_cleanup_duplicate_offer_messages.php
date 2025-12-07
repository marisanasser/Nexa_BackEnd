<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    
    public function up(): void
    {
        
        $messages = DB::table('messages')
            ->where('message_type', 'offer')
            ->get();

        foreach ($messages as $message) {
            $offerData = json_decode($message->offer_data, true);
            
            if ($offerData && is_array($offerData)) {
                
                if (!isset($offerData['status']) || $offerData['status'] === null) {
                    $offerData['status'] = 'pending';
                    
                    
                    DB::table('messages')
                        ->where('id', $message->id)
                        ->update(['offer_data' => json_encode($offerData)]);
                }
            }
        }

        
        $duplicateMessages = DB::table('messages')
            ->where('message_type', 'offer')
            ->whereIn('message', ['Oferta enviada: R$ 50,00', 'Oferta enviada: R$ 30,00'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('message');

        foreach ($duplicateMessages as $messageText => $messages) {
            if ($messages->count() > 1) {
                
                $latestMessage = $messages->first();
                $olderMessages = $messages->skip(1);
                
                foreach ($olderMessages as $oldMessage) {
                    DB::table('messages')->where('id', $oldMessage->id)->delete();
                }
            }
        }
    }

    
    public function down(): void
    {
        
    }
};

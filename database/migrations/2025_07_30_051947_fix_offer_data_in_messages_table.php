<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        $messages = DB::table('messages')
            ->where('message_type', 'offer')
            ->whereNotNull('offer_data')
            ->get();

        foreach ($messages as $message) {
            $offerData = $message->offer_data;

            if (is_string($offerData)) {
                $decoded = json_decode($offerData, true);
                if ($decoded !== null && is_array($decoded)) {

                    DB::table('messages')
                        ->where('id', $message->id)
                        ->update(['offer_data' => json_encode($decoded)]);
                }
            }
        }
    }

    public function down(): void {}
};

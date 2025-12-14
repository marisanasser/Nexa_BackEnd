<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        $offerMessages = DB::table('messages')
            ->where('message_type', 'offer')
            ->whereNotNull('offer_data')
            ->get();

        foreach ($offerMessages as $message) {
            $offerData = json_decode($message->offer_data, true);

            if (isset($offerData['offer_id'])) {

                $offer = DB::table('offers')->where('id', $offerData['offer_id'])->first();

                if ($offer) {

                    $updatedOfferData = [
                        'offer_id' => $offer->id,
                        'title' => $offer->title ?? 'Oferta de Projeto',
                        'description' => $offer->description ?? 'Oferta enviada via chat',
                        'budget' => $offer->budget,
                        'formatted_budget' => 'R$ '.number_format($offer->budget, 2, ',', '.'),
                        'estimated_days' => $offer->estimated_days,
                        'status' => $offer->status,
                        'expires_at' => $offer->expires_at,
                        'days_until_expiry' => max(0, now()->diffInDays($offer->expires_at, false)),
                        'sender' => $offerData['sender'] ?? [
                            'id' => $offer->brand_id,
                            'name' => 'Unknown',
                            'avatar_url' => null,
                        ],
                    ];

                    DB::table('messages')
                        ->where('id', $message->id)
                        ->update([
                            'offer_data' => json_encode($updatedOfferData),
                        ]);
                }
            }
        }
    }

    public function down(): void {}
};

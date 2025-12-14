<?php

namespace App\Console\Commands;

use App\Models\Offer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireOffers extends Command
{
    protected $signature = 'offers:expire';

    protected $description = 'Expire offers that are older than 1 day';

    public function handle()
    {
        $this->info('Starting offer expiration process...');

        $expiredOffers = Offer::where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->get();

        $count = 0;

        foreach ($expiredOffers as $offer) {
            $offer->update([
                'status' => 'expired',
            ]);

            $count++;

            Log::info('Offer expired automatically', [
                'offer_id' => $offer->id,
                'brand_id' => $offer->brand_id,
                'creator_id' => $offer->creator_id,
                'expired_at' => now(),
            ]);
        }

        $this->info("Expired {$count} offers successfully.");

        $messageCount = \App\Models\Message::count();
        $offerMessageCount = \App\Models\Message::where('message_type', 'offer')->count();

        Log::info('Message statistics', [
            'total_messages' => $messageCount,
            'offer_messages' => $offerMessageCount,
            'timestamp' => now(),
        ]);

        return 0;
    }
}

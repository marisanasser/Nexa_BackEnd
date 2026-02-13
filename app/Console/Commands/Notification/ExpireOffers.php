<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Models\Chat\Message;
use App\Models\Contract\Offer;
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
            ->get()
        ;

        $count = 0;

        foreach ($expiredOffers as $offer) {
            $offer->update([
                'status' => 'expired',
            ]);

            ++$count;

            Log::info('Offer expired automatically', [
                'offer_id' => $offer->getKey(),
                'brand_id' => $offer->brand->getKey(),
                'creator_id' => $offer->creator->getKey(),
                'expired_at' => now(),
            ]);
        }

        $this->info("Expired {$count} offers successfully.");

        $messageCount = Message::count();
        $offerMessageCount = Message::where('message_type', 'offer')->count();

        Log::info('Message statistics', [
            'total_messages' => $messageCount,
            'offer_messages' => $offerMessageCount,
            'timestamp' => now(),
        ]);

        return 0;
    }
}

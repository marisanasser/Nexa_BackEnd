<?php

declare(strict_types=1);

namespace App\Console\Commands\Subscription;

use App\Models\Payment\Subscription;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use Pusher\ApiErrorException;
use function in_array;

use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

class ResetSubscriptions extends Command
{
    protected $signature = 'subscriptions:reset {--confirm : Confirm the reset operation}';

    protected $description = 'Reset all subscriptions (cancel in Stripe and clear local records)';

    public function handle()
    {
        if (!$this->option('confirm')) {
            $this->warn('⚠️  This will cancel all subscriptions in Stripe and clear local records.');
            $this->info('This operation cannot be undone!');
            $this->newLine();
            $this->info('To proceed, run: php artisan subscriptions:reset --confirm');

            return 1;
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        if (empty(config('services.stripe.secret'))) {
            $this->error('❌ STRIPE_SECRET is not configured in .env file');

            return 1;
        }

        $this->info('🔄 Resetting all subscriptions...');
        $this->newLine();

        $subscriptions = Subscription::all();
        $count = $subscriptions->count();

        if (0 === $count) {
            $this->info('No subscriptions found to reset.');

            return 0;
        }

        $this->info("Found {$count} subscription(s) to reset.");
        $this->newLine();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $canceledCount = 0;
        $errorCount = 0;

        foreach ($subscriptions as $subscription) {
            $this->processSubscriptionReset($subscription, $bar, $canceledCount, $errorCount);
        }

        $bar->finish();
        $this->newLine();
        $this->newLine();

        $this->info('Clearing local subscription records...');
        DB::table('subscriptions')->truncate();

        $this->info('Clearing user premium flags...');
        DB::table('users')->update([
            'has_premium' => false,
            'premium_expires_at' => null,
        ]);

        $this->newLine();
        $this->info('✅ Reset completed!');
        $this->info("   - Canceled in Stripe: {$canceledCount}");
        $this->info("   - Errors: {$errorCount}");
        $this->info('   - Local records cleared');

        return 0;
    }

    private function processSubscriptionReset(Subscription $subscription, $bar, int &$canceledCount, int &$errorCount): void
    {
        try {
            if ($subscription->stripe_subscription_id) {
                try {
                    $stripeSub = StripeSubscription::retrieve($subscription->stripe_subscription_id);

                    if (in_array($stripeSub->status, ['active', 'trialing', 'past_due'])) {
                        $stripeSub->cancel();
                    }
                } catch (ApiErrorException $e) {
                    $this->newLine();
                    $this->warn("Could not cancel Stripe subscription {$subscription->stripe_subscription_id}: ".$e->getMessage());
                }
            }

            $bar->advance();
            ++$canceledCount;
        } catch (Exception $e) {
            $this->newLine();
            $this->error("Error processing subscription {$subscription->getKey()}: ".$e->getMessage());
            $bar->advance();
            ++$errorCount;
        }
    }
}

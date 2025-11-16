<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Stripe\Exception\StripeException;

class ResetSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:reset {--confirm : Confirm the reset operation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset all subscriptions (cancel in Stripe and clear local records)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->option('confirm')) {
            $this->warn('⚠️  This will cancel all subscriptions in Stripe and clear local records.');
            $this->info('This operation cannot be undone!');
            $this->newLine();
            $this->info('To proceed, run: php artisan subscriptions:reset --confirm');
            return 1;
        }

        // Set Stripe API key
        Stripe::setApiKey(config('services.stripe.secret'));

        if (empty(config('services.stripe.secret'))) {
            $this->error('❌ STRIPE_SECRET is not configured in .env file');
            return 1;
        }

        $this->info('🔄 Resetting all subscriptions...');
        $this->newLine();

        // Get all subscriptions
        $subscriptions = Subscription::all();
        $count = $subscriptions->count();

        if ($count === 0) {
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
            try {
                // Cancel subscription in Stripe if it has a stripe_subscription_id
                if ($subscription->stripe_subscription_id) {
                    try {
                        $stripeSub = StripeSubscription::retrieve($subscription->stripe_subscription_id);
                        
                        // Only cancel if it's still active
                        if (in_array($stripeSub->status, ['active', 'trialing', 'past_due'])) {
                            $stripeSub->cancel();
                        }
                    } catch (StripeException $e) {
                        // Subscription might already be canceled or not exist
                        $this->newLine();
                        $this->warn("Could not cancel Stripe subscription {$subscription->stripe_subscription_id}: " . $e->getMessage());
                    }
                }

                $bar->advance();
                $canceledCount++;

            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Error processing subscription {$subscription->id}: " . $e->getMessage());
                $bar->advance();
                $errorCount++;
            }
        }

        $bar->finish();
        $this->newLine();
        $this->newLine();

        // Clear all subscription records
        $this->info('Clearing local subscription records...');
        DB::table('subscriptions')->truncate();
        
        // Clear user premium flags
        $this->info('Clearing user premium flags...');
        DB::table('users')->update([
            'has_premium' => false,
            'premium_expires_at' => null,
        ]);

        $this->newLine();
        $this->info("✅ Reset completed!");
        $this->info("   - Canceled in Stripe: {$canceledCount}");
        $this->info("   - Errors: {$errorCount}");
        $this->info("   - Local records cleared");

        return 0;
    }
}


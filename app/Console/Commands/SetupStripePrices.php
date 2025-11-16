<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Stripe\Stripe;
use Stripe\Product;
use Stripe\Price;
use Stripe\Exception\StripeException;

class SetupStripePrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stripe:setup-prices {--force : Force update existing prices}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up Stripe products and prices for subscription plans';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Set Stripe API key
        Stripe::setApiKey(config('services.stripe.secret'));

        if (empty(config('services.stripe.secret'))) {
            $this->error('❌ STRIPE_SECRET is not configured in .env file');
            $this->info('Please set STRIPE_SECRET in your .env file');
            return 1;
        }

        $this->info('Setting up Stripe products and prices for subscription plans...');
        $this->newLine();

        $plans = SubscriptionPlan::all();

        if ($plans->isEmpty()) {
            $this->error('No subscription plans found');
            return 1;
        }

        $bar = $this->output->createProgressBar($plans->count());
        $bar->start();

        foreach ($plans as $plan) {
            try {
                // Check if plan already has stripe_price_id
                if ($plan->stripe_price_id && !$this->option('force')) {
                    $this->newLine();
                    $this->warn("Plan '{$plan->name}' already has a Stripe price ID: {$plan->stripe_price_id}");
                    $this->info("Skipping... Use --force to update existing prices.");
                    $bar->advance();
                    continue;
                }

                // If force and price exists, delete old price first
                if ($this->option('force') && $plan->stripe_price_id) {
                    try {
                        $oldPrice = Price::retrieve($plan->stripe_price_id);
                        $oldPrice->delete();
                        $this->newLine();
                        $this->info("Deleted old price for plan '{$plan->name}': {$plan->stripe_price_id}");
                    } catch (\Exception $e) {
                        $this->newLine();
                        $this->warn("Could not delete old price for plan '{$plan->name}': " . $e->getMessage());
                    }
                }

                // Create or retrieve product
                $product = $this->createOrRetrieveProduct($plan);
                
                // Create price
                $price = $this->createPrice($plan, $product->id);

                // Update plan with Stripe IDs
                $plan->update([
                    'stripe_product_id' => $product->id,
                    'stripe_price_id' => $price->id,
                ]);

                $this->newLine();
                $this->info("✅ Created price for '{$plan->name}': {$price->id} (R$ {$plan->price}/mês, {$plan->duration_months} meses)");

                $bar->advance();

            } catch (StripeException $e) {
                $this->newLine();
                $this->error("Error setting up plan '{$plan->name}': " . $e->getMessage());
                $bar->advance();
                continue;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Unexpected error for plan '{$plan->name}': " . $e->getMessage());
                $bar->advance();
                continue;
            }
        }

        $bar->finish();
        $this->newLine();
        $this->newLine();

        $this->info('✅ Stripe prices setup completed!');
        $this->info('Review your plans at: https://dashboard.stripe.com/products');

        return 0;
    }

    /**
     * Create or retrieve a Stripe product
     */
    private function createOrRetrieveProduct(SubscriptionPlan $plan): Product
    {
        // Check if product already exists
        if ($plan->stripe_product_id) {
            try {
                return Product::retrieve($plan->stripe_product_id);
            } catch (\Exception $e) {
                // Product doesn't exist, create new one
            }
        }

        // Create new product
        return Product::create([
            'name' => $plan->name,
            'description' => $plan->description,
            'metadata' => [
                'plan_id' => $plan->id,
                'duration_months' => $plan->duration_months,
            ],
        ]);
    }

    /**
     * Create a Stripe price for the plan
     * All plans are now monthly recurring (interval_count: 1)
     * The duration_months is used to set cancel_at when creating the subscription
     */
    private function createPrice(SubscriptionPlan $plan, string $productId): Price
    {
        // Convert price to cents (monthly price)
        $priceInCents = (int) round($plan->price * 100);

        // All subscription plans are now monthly recurring
        // The total duration (duration_months) will be handled via cancel_at when creating subscriptions
        return Price::create([
            'product' => $productId,
            'unit_amount' => $priceInCents,
            'currency' => 'brl', // Brazilian Real
            'recurring' => [
                'interval' => 'month',
                'interval_count' => 1, // Always monthly, regardless of plan duration
            ],
            'metadata' => [
                'plan_id' => $plan->id,
                'duration_months' => $plan->duration_months,
                'monthly_price' => $plan->price,
            ],
        ]);
    }
}

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
    protected $signature = 'stripe:setup-prices';

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
                if ($plan->stripe_price_id) {
                    $this->newLine();
                    $this->warn("Plan '{$plan->name}' already has a Stripe price ID: {$plan->stripe_price_id}");
                    $this->info("Skipping... Use --force to update existing prices.");
                    $bar->advance();
                    continue;
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
     */
    private function createPrice(SubscriptionPlan $plan, string $productId): Price
    {
        // Convert price to cents
        $priceInCents = (int) round($plan->price * 100);

        // Determine if it's recurring or one-time
        // For subscription plans, we'll use recurring
        return Price::create([
            'product' => $productId,
            'unit_amount' => $priceInCents,
            'currency' => 'brl', // Brazilian Real
            'recurring' => [
                'interval' => $plan->duration_months > 1 ? 'month' : 'month',
                'interval_count' => $plan->duration_months,
            ],
            'metadata' => [
                'plan_id' => $plan->id,
                'duration_months' => $plan->duration_months,
            ],
        ]);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
use Stripe\Stripe;

class SetupStripePrices extends Command
{
    protected $signature = 'stripe:setup-prices {--force : Force update existing prices}';

    protected $description = 'Set up Stripe products and prices for subscription plans';

    public function handle()
    {

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

                if ($plan->stripe_price_id && ! $this->option('force')) {
                    $this->newLine();
                    $this->warn("Plan '{$plan->name}' already has a Stripe price ID: {$plan->stripe_price_id}");
                    $this->info('Skipping... Use --force to update existing prices.');
                    $bar->advance();

                    continue;
                }

                if ($this->option('force') && $plan->stripe_price_id) {
                    try {
                        $oldPrice = Price::retrieve($plan->stripe_price_id);
                        $oldPrice->delete();
                        $this->newLine();
                        $this->info("Deleted old price for plan '{$plan->name}': {$plan->stripe_price_id}");
                    } catch (\Exception $e) {
                        $this->newLine();
                        $this->warn("Could not delete old price for plan '{$plan->name}': ".$e->getMessage());
                    }
                }

                $product = $this->createOrRetrieveProduct($plan);

                $price = $this->createPrice($plan, $product->id);

                $plan->update([
                    'stripe_product_id' => $product->id,
                    'stripe_price_id' => $price->id,
                ]);

                $this->newLine();
                $this->info("✅ Created price for '{$plan->name}': {$price->id} (R$ {$plan->price}/mês, {$plan->duration_months} meses)");

                $bar->advance();

            } catch (ApiErrorException $e) {
                $this->newLine();
                $this->error("Error setting up plan '{$plan->name}': ".$e->getMessage());
                $bar->advance();

                continue;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Unexpected error for plan '{$plan->name}': ".$e->getMessage());
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

    private function createOrRetrieveProduct(SubscriptionPlan $plan): Product
    {

        if ($plan->stripe_product_id) {
            try {
                return Product::retrieve($plan->stripe_product_id);
            } catch (\Exception $e) {

            }
        }

        return Product::create([
            'name' => $plan->name,
            'description' => $plan->description,
            'metadata' => [
                'plan_id' => $plan->id,
                'duration_months' => $plan->duration_months,
            ],
        ]);
    }

    private function createPrice(SubscriptionPlan $plan, string $productId): Price
    {

        $priceInCents = (int) round($plan->price * 100);

        return Price::create([
            'product' => $productId,
            'unit_amount' => $priceInCents,
            'currency' => 'brl',
            'recurring' => [
                'interval' => 'month',
                'interval_count' => 1,
            ],
            'metadata' => [
                'plan_id' => $plan->id,
                'duration_months' => $plan->duration_months,
                'monthly_price' => $plan->price,
            ],
        ]);
    }
}

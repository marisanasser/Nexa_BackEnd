<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Stripe\Customer;

class ListStripeSubscriptions extends Command
{
    protected $signature = 'stripe:list-subscriptions {--email= : Filter by customer email} {--limit=10 : Number of subscriptions to list}';
    protected $description = 'List recent Stripe subscriptions';

    public function handle()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        
        $email = $this->option('email');
        $limit = (int) $this->option('limit');
        
        try {
            if ($email) {
                // Find customer by email
                $customers = Customer::all([
                    'email' => $email,
                    'limit' => 10,
                ]);
                
                if (empty($customers->data)) {
                    $this->warn("No customer found with email: {$email}");
                    return 1;
                }
                
                $this->info("Found " . count($customers->data) . " customer(s) with email: {$email}");
                
                foreach ($customers->data as $customer) {
                    $this->info("\nCustomer: {$customer->id} ({$customer->email})");
                    
                    // Get subscriptions for this customer
                    $subscriptions = StripeSubscription::all([
                        'customer' => $customer->id,
                        'limit' => $limit,
                    ]);
                    
                    if (empty($subscriptions->data)) {
                        $this->warn("  No subscriptions found");
                        continue;
                    }
                    
                    foreach ($subscriptions->data as $sub) {
                        $this->line("  Subscription: {$sub->id}");
                        $this->line("    Status: {$sub->status}");
                        $this->line("    Created: " . date('Y-m-d H:i:s', $sub->created));
                        $this->line("    Current period: " . date('Y-m-d', $sub->current_period_start) . " to " . date('Y-m-d', $sub->current_period_end));
                        
                        // Check if exists in local DB
                        $localSub = \App\Models\Subscription::where('stripe_subscription_id', $sub->id)->first();
                        if ($localSub) {
                            $this->info("    ✅ Exists in local DB (ID: {$localSub->id})");
                        } else {
                            $this->warn("    ❌ NOT in local DB");
                        }
                    }
                }
            } else {
                // List recent subscriptions
                $subscriptions = StripeSubscription::all([
                    'limit' => $limit,
                    'status' => 'all',
                ]);
                
                $this->info("Recent Stripe subscriptions:");
                
                foreach ($subscriptions->data as $sub) {
                    $customer = Customer::retrieve($sub->customer);
                    $this->line("\nSubscription: {$sub->id}");
                    $this->line("  Customer: {$customer->email} ({$sub->customer})");
                    $this->line("  Status: {$sub->status}");
                    $this->line("  Created: " . date('Y-m-d H:i:s', $sub->created));
                    
                    // Check if exists in local DB
                    $localSub = \App\Models\Subscription::where('stripe_subscription_id', $sub->id)->first();
                    if ($localSub) {
                        $this->info("  ✅ Exists in local DB (ID: {$localSub->id}, User: {$localSub->user_id})");
                    } else {
                        $this->warn("  ❌ NOT in local DB");
                        
                        // Try to find user by customer email
                        $user = User::where('email', $customer->email)->first();
                        if ($user) {
                            $this->info("  User found: ID {$user->id} ({$user->email})");
                        }
                    }
                }
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }
}




<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

class ProcessSubscription extends Command
{
    protected $signature = 'subscription:process {subscription_id : Stripe subscription ID}';
    protected $description = 'Process a subscription that was created in Stripe but not in local database';

    public function handle()
    {
        $stripeSubscriptionId = $this->argument('subscription_id');
        
        Stripe::setApiKey(config('services.stripe.secret'));
        
        try {
            // Retrieve subscription from Stripe
            $stripeSub = StripeSubscription::retrieve($stripeSubscriptionId, [
                'expand' => ['latest_invoice.payment_intent']
            ]);
            
            $this->info("Subscription found in Stripe:");
            $this->info("  Status: {$stripeSub->status}");
            $this->info("  Customer: {$stripeSub->customer}");
            
            // Find user by customer ID
            $user = User::where('stripe_customer_id', $stripeSub->customer)->first();
            
            if (!$user) {
                $this->error("User not found for customer ID: {$stripeSub->customer}");
                return 1;
            }
            
            $this->info("User found: {$user->email} (ID: {$user->id})");
            
            // Check if subscription already exists
            $existingSub = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if ($existingSub) {
                $this->warn("Subscription already exists in database (ID: {$existingSub->id})");
                $this->info("Updating user premium status...");
                
                // Update user premium status
                $currentPeriodEnd = isset($stripeSub->current_period_end) 
                    ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end) 
                    : null;
                
                if (!$currentPeriodEnd) {
                    $plan = SubscriptionPlan::find($existingSub->subscription_plan_id);
                    if ($plan) {
                        $currentPeriodEnd = \Carbon\Carbon::now()->addMonths($plan->duration_months);
                    }
                }
                
                $user->update([
                    'has_premium' => true,
                    'premium_expires_at' => $currentPeriodEnd?->format('Y-m-d H:i:s'),
                ]);
                
                $this->info("✅ User premium status updated!");
                return 0;
            }
            
            // Get plan from subscription price
            $priceId = $stripeSub->items->data[0]->price->id ?? null;
            if (!$priceId) {
                $this->error("Could not get price ID from subscription");
                return 1;
            }
            
            $plan = SubscriptionPlan::where('stripe_price_id', $priceId)->first();
            if (!$plan) {
                $this->error("Plan not found for price ID: {$priceId}");
                return 1;
            }
            
            $this->info("Plan found: {$plan->name} (ID: {$plan->id})");
            
            // Check payment status
            $invoiceStatus = null;
            $paymentIntentStatus = null;
            
            if (isset($stripeSub->latest_invoice) && is_object($stripeSub->latest_invoice)) {
                $invoiceStatus = $stripeSub->latest_invoice->status ?? null;
                if (isset($stripeSub->latest_invoice->payment_intent)) {
                    if (is_object($stripeSub->latest_invoice->payment_intent)) {
                        $paymentIntentStatus = $stripeSub->latest_invoice->payment_intent->status ?? null;
                    }
                }
            }
            
            $paymentSuccessful = (
                $stripeSub->status === 'active' ||
                $invoiceStatus === 'paid' ||
                $paymentIntentStatus === 'succeeded'
            );
            
            if (!$paymentSuccessful) {
                $this->warn("Payment not yet confirmed. Status: {$stripeSub->status}, Invoice: {$invoiceStatus}, PaymentIntent: {$paymentIntentStatus}");
                if (!$this->confirm("Continue anyway?")) {
                    return 1;
                }
            }
            
            DB::beginTransaction();
            
            try {
                $currentPeriodEnd = isset($stripeSub->current_period_end) 
                    ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end) 
                    : null;
                $currentPeriodStart = isset($stripeSub->current_period_start) 
                    ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_start) 
                    : null;
                
                $invoiceId = $stripeSub->latest_invoice->id ?? null;
                $paymentIntentId = null;
                if (isset($stripeSub->latest_invoice) && is_object($stripeSub->latest_invoice)) {
                    if (isset($stripeSub->latest_invoice->payment_intent)) {
                        if (is_object($stripeSub->latest_invoice->payment_intent)) {
                            $paymentIntentId = $stripeSub->latest_invoice->payment_intent->id ?? null;
                        }
                    }
                }
                
                $transactionId = $paymentIntentId ?? $invoiceId ?? 'stripe_' . $stripeSubscriptionId;
                
                // Create transaction
                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'stripe_payment_intent_id' => $transactionId,
                    'status' => 'paid',
                    'amount' => $plan->price,
                    'payment_method' => 'stripe',
                    'payment_data' => [
                        'invoice' => $invoiceId,
                        'subscription' => $stripeSubscriptionId,
                    ],
                    'paid_at' => now(),
                ]);
                
                // Set cancel_at for plans with fixed duration
                if ($plan->duration_months > 1) {
                    $cancelAt = \Carbon\Carbon::now()->addMonths($plan->duration_months)->timestamp;
                    try {
                        $stripeSub = StripeSubscription::update($stripeSubscriptionId, [
                            'cancel_at' => $cancelAt,
                        ]);
                        $this->info("✅ Set cancel_at for subscription");
                    } catch (\Exception $e) {
                        $this->warn("Could not set cancel_at: " . $e->getMessage());
                    }
                }
                
                // Create subscription
                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'subscription_plan_id' => $plan->id,
                    'status' => Subscription::STATUS_ACTIVE,
                    'amount_paid' => $plan->price,
                    'payment_method' => 'stripe',
                    'transaction_id' => $transaction->id,
                    'auto_renew' => true,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'stripe_latest_invoice_id' => $invoiceId,
                    'stripe_status' => $stripeSub->status ?? 'active',
                    'starts_at' => $currentPeriodStart,
                    'expires_at' => $currentPeriodEnd,
                ]);
                
                // Calculate expiration date
                $premiumExpiresAt = $currentPeriodEnd;
                if (!$premiumExpiresAt) {
                    $premiumExpiresAt = \Carbon\Carbon::now()->addMonths($plan->duration_months);
                }
                
                // Update user premium flags
                $user->update([
                    'has_premium' => true,
                    'premium_expires_at' => $premiumExpiresAt->format('Y-m-d H:i:s'),
                ]);
                
                DB::commit();
                
                $this->info("✅ Subscription created successfully!");
                $this->info("  Subscription ID: {$subscription->id}");
                $this->info("  User premium: {$user->has_premium}");
                $this->info("  Premium expires at: {$user->premium_expires_at}");
                
                return 0;
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("Error: " . $e->getMessage());
                $this->error($e->getTraceAsString());
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error("Error retrieving subscription from Stripe: " . $e->getMessage());
            return 1;
        }
    }
}


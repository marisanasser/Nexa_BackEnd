<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Subscription as LocalSubscription;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class StripeWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        Log::info('Stripe webhook received', [
            'has_payload' => !empty($payload),
            'payload_length' => strlen($payload),
            'has_signature' => !empty($sigHeader),
            'has_webhook_secret' => !empty($webhookSecret),
            'ip_address' => $request->ip(),
        ]);

        if (!$webhookSecret) {
            Log::error('Stripe webhook secret missing - webhook cannot be processed', [
                'config_exists' => config('services.stripe.webhook_secret') !== null,
            ]);
            return response()->json(['error' => 'Webhook not configured'], 503);
        }

        try {
            Log::info('Verifying Stripe webhook signature', [
                'signature_length' => strlen($sigHeader ?? ''),
                'webhook_secret_length' => strlen($webhookSecret),
            ]);
            
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );
            
            Log::info('Stripe webhook signature verified successfully', [
                'event_id' => $event->id ?? 'no_id',
                'event_type' => $event->type ?? 'no_type',
                'livemode' => $event->livemode ?? false,
            ]);

            // Idempotency: avoid reprocessing
            $eventId = $event->id ?? null;
            if ($eventId && \Illuminate\Support\Facades\Cache::has('stripe_event_'.$eventId)) {
                return response()->json(['status' => 'duplicate']);
            }
            if ($eventId) {
                \Illuminate\Support\Facades\Cache::put('stripe_event_'.$eventId, true, 3600);
            }

            Log::info('Processing Stripe webhook event', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'livemode' => $event->livemode ?? false,
                'created' => $event->created ?? null,
            ]);
            
            switch ($event->type) {
                case 'checkout.session.completed':
                    $session = $event->data->object;
                    
                    Log::info('Stripe checkout.session.completed event received', [
                        'event_id' => $event->id,
                        'session_id' => $session->id ?? 'no_id',
                        'customer_id' => $session->customer ?? 'no_customer',
                        'subscription_id' => $session->subscription ?? null,
                        'mode' => $session->mode ?? 'unknown',
                    ]);
                    
                    // Handle subscription checkout
                    if ($session->mode === 'subscription' && $session->subscription) {
                        $this->handleCheckoutSessionCompleted($session);
                    }
                    break;
                    
                case 'invoice.paid':
                    $invoice = $event->data->object;
                    $stripeSubscriptionId = $invoice->subscription ?? null;
                    
                    Log::info('Stripe invoice.paid event received', [
                        'event_id' => $event->id,
                        'invoice_id' => $invoice->id ?? 'no_id',
                        'subscription_id' => $stripeSubscriptionId,
                        'amount_paid' => $invoice->amount_paid ?? 0,
                        'currency' => $invoice->currency ?? 'unknown',
                    ]);
                    
                    if ($stripeSubscriptionId) {
                        // Check if subscription exists, if not create it (payment is now confirmed)
                        $existingSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
                        if (!$existingSub) {
                            // Payment is confirmed but subscription doesn't exist yet - create it now
                            Log::info('Creating subscription from invoice.paid event', [
                                'stripe_subscription_id' => $stripeSubscriptionId,
                            ]);
                            $this->createSubscriptionFromInvoice($invoice);
                        } else {
                            // Subscription exists, just sync it
                            $this->syncSubscription($stripeSubscriptionId, $invoice->id);
                        }
                    }
                    break;
                    
                case 'invoice.payment_failed':
                    $invoice = $event->data->object;
                    $stripeSubscriptionId = $invoice->subscription ?? null;
                    
                    Log::warning('Stripe invoice.payment_failed event received', [
                        'event_id' => $event->id,
                        'invoice_id' => $invoice->id ?? 'no_id',
                        'subscription_id' => $stripeSubscriptionId,
                        'attempt_count' => $invoice->attempt_count ?? 0,
                        'amount_due' => $invoice->amount_due ?? 0,
                    ]);
                    
                    if ($stripeSubscriptionId) {
                        $this->markSubscriptionPaymentFailed($stripeSubscriptionId, $invoice->id);
                    }
                    break;
                    
                case 'customer.subscription.updated':
                case 'customer.subscription.created':
                    $stripeSub = $event->data->object;
                    
                    Log::info('Stripe subscription event received', [
                        'event_id' => $event->id,
                        'event_type' => $event->type,
                        'subscription_id' => $stripeSub->id ?? 'no_id',
                        'subscription_status' => $stripeSub->status ?? 'unknown',
                        'customer_id' => $stripeSub->customer ?? 'no_customer',
                        'latest_invoice' => $stripeSub->latest_invoice ?? null,
                    ]);
                    
                    $this->syncSubscription($stripeSub->id, $stripeSub->latest_invoice ?? null);
                    break;
                    
                case 'charge.dispute.created':
                case 'transfer.failed':
                case 'payout.paid':
                case 'payout.failed':
                    Log::info('Stripe payment/payout event received', [
                        'event_id' => $event->id,
                        'event_type' => $event->type,
                        'object_id' => $event->data->object->id ?? 'no_id',
                        'amount' => $event->data->object->amount ?? 0,
                        'currency' => $event->data->object->currency ?? 'unknown',
                    ]);
                    // TODO: implementar reconciliação de pagamentos/saques
                    break;
                    
                default:
                    Log::debug('Unhandled Stripe event type', [
                        'event_id' => $event->id,
                        'event_type' => $event->type,
                        'object_type' => $event->data->object->object ?? 'unknown',
                    ]);
            }

            return response()->json(['received' => true]);
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook payload error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe webhook signature error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook processing error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    private function handleCheckoutSessionCompleted($session): void
    {
        try {
            Log::info('Handling checkout session completed', [
                'session_id' => $session->id,
                'subscription_id' => $session->subscription,
                'customer_id' => $session->customer,
            ]);

            $stripeSubscriptionId = $session->subscription;
            if (!$stripeSubscriptionId) {
                Log::warning('No subscription ID in checkout session', [
                    'session_id' => $session->id,
                ]);
                return;
            }

            // Retrieve subscription from Stripe to get details
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $stripeSub = \Stripe\Subscription::retrieve($stripeSubscriptionId, [
                'expand' => ['latest_invoice.payment_intent']
            ]);

            $customerId = $stripeSub->customer;
            $user = User::where('stripe_customer_id', $customerId)->first();

            if (!$user) {
                Log::warning('User not found for Stripe customer', [
                    'customer_id' => $customerId,
                    'subscription_id' => $stripeSubscriptionId,
                ]);
                return;
            }

            // Get plan from metadata or from subscription items
            $planId = null;
            
            // Check metadata (can be array or object)
            if (isset($session->metadata)) {
                if (is_array($session->metadata) && isset($session->metadata['plan_id'])) {
                    $planId = (int) $session->metadata['plan_id'];
                } elseif (is_object($session->metadata) && isset($session->metadata->plan_id)) {
                    $planId = (int) $session->metadata->plan_id;
                }
            }
            
            // If not found in metadata, try to get plan from subscription price
            if (!$planId) {
                $priceId = $stripeSub->items->data[0]->price->id ?? null;
                if ($priceId) {
                    $plan = SubscriptionPlan::where('stripe_price_id', $priceId)->first();
                    if ($plan) {
                        $planId = $plan->id;
                    }
                }
            }

            if (!$planId) {
                Log::error('Could not determine plan for checkout session', [
                    'session_id' => $session->id,
                    'subscription_id' => $stripeSubscriptionId,
                    'metadata' => $session->metadata ?? null,
                ]);
                return;
            }

            $plan = SubscriptionPlan::find($planId);
            if (!$plan) {
                Log::error('Plan not found for checkout session', [
                    'plan_id' => $planId,
                    'session_id' => $session->id,
                ]);
                return;
            }

            // Check payment status - only create subscription if payment is successful
            $paymentStatus = $stripeSub->status ?? 'incomplete';
            $invoiceStatus = null;
            $paymentIntentStatus = null;
            
            // Check invoice status
            if (isset($stripeSub->latest_invoice) && is_object($stripeSub->latest_invoice)) {
                $invoiceStatus = $stripeSub->latest_invoice->status ?? null;
                if (isset($stripeSub->latest_invoice->payment_intent)) {
                    if (is_object($stripeSub->latest_invoice->payment_intent)) {
                        $paymentIntentStatus = $stripeSub->latest_invoice->payment_intent->status ?? null;
                    } elseif (is_string($stripeSub->latest_invoice->payment_intent)) {
                        // Fetch payment intent if it's just an ID
                        try {
                            $pi = \Stripe\PaymentIntent::retrieve($stripeSub->latest_invoice->payment_intent);
                            $paymentIntentStatus = $pi->status ?? null;
                        } catch (\Exception $e) {
                            Log::warning('Could not retrieve payment intent', [
                                'payment_intent_id' => $stripeSub->latest_invoice->payment_intent,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
            
            // Determine if payment was successful
            $paymentSuccessful = (
                $paymentStatus === 'active' ||
                $invoiceStatus === 'paid' ||
                $paymentIntentStatus === 'succeeded'
            );
            
            Log::info('Payment status check', [
                'subscription_status' => $paymentStatus,
                'invoice_status' => $invoiceStatus,
                'payment_intent_status' => $paymentIntentStatus,
                'payment_successful' => $paymentSuccessful,
            ]);
            
            // If payment is not successful yet, wait for invoice.paid event
            if (!$paymentSuccessful) {
                Log::info('Payment not yet successful, subscription will be created when invoice.paid event is received', [
                    'subscription_id' => $stripeSubscriptionId,
                    'status' => $paymentStatus,
                ]);
                return;
            }

            // Check if subscription already exists
            $existingSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if ($existingSub) {
                Log::info('Subscription already exists, syncing instead', [
                    'subscription_id' => $existingSub->id,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                ]);
                $this->syncSubscription($stripeSubscriptionId, $stripeSub->latest_invoice ?? null);
                return;
            }

            // Create local subscription record only if payment is successful
            DB::beginTransaction();

            $currentPeriodEnd = isset($stripeSub->current_period_end) 
                ? Carbon::createFromTimestamp($stripeSub->current_period_end) 
                : null;
            $currentPeriodStart = isset($stripeSub->current_period_start) 
                ? Carbon::createFromTimestamp($stripeSub->current_period_start) 
                : null;

            $invoiceId = null;
            if ($stripeSub->latest_invoice) {
                if (is_object($stripeSub->latest_invoice)) {
                    $invoiceId = $stripeSub->latest_invoice->id ?? null;
                } elseif (is_string($stripeSub->latest_invoice)) {
                    $invoiceId = $stripeSub->latest_invoice;
                }
            }

            $paymentIntentId = null;
            if (isset($stripeSub->latest_invoice) && is_object($stripeSub->latest_invoice)) {
                $paymentIntentId = $stripeSub->latest_invoice->payment_intent->id ?? null;
            }
            
            // Also try to get payment intent from invoice if expanded
            if (!$paymentIntentId && isset($stripeSub->latest_invoice) && is_object($stripeSub->latest_invoice)) {
                if (is_string($stripeSub->latest_invoice->payment_intent ?? null)) {
                    $paymentIntentId = $stripeSub->latest_invoice->payment_intent;
                }
            }

            // Use payment intent ID as transaction identifier (unique identifier for Stripe transactions)
            $transactionId = $paymentIntentId ?? $invoiceId ?? 'stripe_' . $stripeSubscriptionId;

            // Create transaction
            try {
                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'stripe_payment_intent_id' => $transactionId,
                    'status' => $stripeSub->status === 'active' ? 'paid' : 'pending',
                    'amount' => $plan->price,
                    'payment_method' => 'stripe',
                    'payment_data' => [
                        'invoice' => $invoiceId,
                        'subscription' => $stripeSubscriptionId,
                        'checkout_session' => $session->id,
                    ],
                    'paid_at' => $stripeSub->status === 'active' ? now() : null,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to create transaction', [
                    'error' => $e->getMessage(),
                    'user_id' => $user->id,
                    'transaction_id' => $transactionId,
                ]);
                throw $e;
            }

            // Create subscription
            try {
                Log::info('Creating subscription record', [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'transaction_id' => $transaction->id,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'stripe_status' => $stripeSub->status ?? 'incomplete',
                ]);
                
                $subscription = LocalSubscription::create([
                    'user_id' => $user->id,
                    'subscription_plan_id' => $plan->id,
                    'status' => $stripeSub->status === 'active' ? LocalSubscription::STATUS_ACTIVE : LocalSubscription::STATUS_PENDING,
                    'amount_paid' => $plan->price,
                    'payment_method' => 'stripe',
                    'transaction_id' => $transaction->id,
                    'auto_renew' => true,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'stripe_latest_invoice_id' => $invoiceId,
                    'stripe_status' => $stripeSub->status ?? 'incomplete',
                    'starts_at' => $currentPeriodStart,
                    'expires_at' => $currentPeriodEnd,
                ]);
                
                Log::info('Subscription created successfully', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to create subscription', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'transaction_id' => $transaction->id ?? null,
                ]);
                throw $e;
            }

            // Update user premium flags when subscription is successfully created
            // Set premium status based on subscription period end date
            try {
                $user->update([
                    'has_premium' => true,
                    'premium_expires_at' => $currentPeriodEnd,
                ]);
                
                Log::info('User premium status updated', [
                    'user_id' => $user->id,
                    'has_premium' => true,
                    'premium_expires_at' => $currentPeriodEnd?->toISOString(),
                    'subscription_status' => $subscription->status,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to update user premium status', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                // Don't throw - subscription is created, user update can be retried
            }

            DB::commit();

            Log::info('Subscription created from checkout session', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'status' => $subscription->status,
                'has_premium' => $user->has_premium,
                'premium_expires_at' => $user->premium_expires_at?->toISOString(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to handle checkout session completed', [
                'session_id' => $session->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function createSubscriptionFromInvoice($invoice): void
    {
        try {
            $stripeSubscriptionId = $invoice->subscription ?? null;
            if (!$stripeSubscriptionId) {
                return;
            }

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $stripeSub = \Stripe\Subscription::retrieve($stripeSubscriptionId, [
                'expand' => ['latest_invoice.payment_intent']
            ]);

            $customerId = $stripeSub->customer;
            $user = User::where('stripe_customer_id', $customerId)->first();

            if (!$user) {
                Log::warning('User not found for Stripe customer in invoice.paid', [
                    'customer_id' => $customerId,
                ]);
                return;
            }

            // Get plan from subscription price
            $priceId = $stripeSub->items->data[0]->price->id ?? null;
            if (!$priceId) {
                Log::error('Could not get price ID from subscription', [
                    'subscription_id' => $stripeSubscriptionId,
                ]);
                return;
            }

            $plan = SubscriptionPlan::where('stripe_price_id', $priceId)->first();
            if (!$plan) {
                Log::error('Plan not found for price ID', [
                    'price_id' => $priceId,
                    'subscription_id' => $stripeSubscriptionId,
                ]);
                return;
            }

            // Check if subscription already exists (race condition check)
            $existingSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if ($existingSub) {
                Log::info('Subscription already exists, syncing instead', [
                    'subscription_id' => $existingSub->id,
                ]);
                $this->syncSubscription($stripeSubscriptionId, $invoice->id);
                return;
            }

            DB::beginTransaction();

            $currentPeriodEnd = isset($stripeSub->current_period_end) 
                ? Carbon::createFromTimestamp($stripeSub->current_period_end) 
                : null;
            $currentPeriodStart = isset($stripeSub->current_period_start) 
                ? Carbon::createFromTimestamp($stripeSub->current_period_start) 
                : null;

            $invoiceId = $invoice->id ?? null;
            $paymentIntentId = null;
            if (isset($invoice->payment_intent)) {
                if (is_object($invoice->payment_intent)) {
                    $paymentIntentId = $invoice->payment_intent->id ?? null;
                } elseif (is_string($invoice->payment_intent)) {
                    $paymentIntentId = $invoice->payment_intent;
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

            // Create subscription with active status (payment is confirmed)
            $subscription = LocalSubscription::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'status' => LocalSubscription::STATUS_ACTIVE,
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

            // Update user premium flags (payment is confirmed)
            $user->update([
                'has_premium' => true,
                'premium_expires_at' => $currentPeriodEnd,
            ]);

            DB::commit();

            Log::info('Subscription created from invoice.paid event', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to create subscription from invoice', [
                'invoice_id' => $invoice->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function syncSubscription(string $stripeSubscriptionId, ?string $latestInvoiceId = null): void
    {
        try {
            Log::info('Syncing subscription from Stripe webhook', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
            ]);
            
            // Fetch subscription from Stripe for authoritative data
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            
            Log::info('Retrieving subscription from Stripe API', [
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);
            
            $stripeSub = \Stripe\Subscription::retrieve($stripeSubscriptionId);
            
            Log::info('Subscription retrieved from Stripe', [
                'stripe_subscription_id' => $stripeSub->id,
                'status' => $stripeSub->status ?? 'unknown',
                'customer' => $stripeSub->customer ?? 'no_customer',
                'current_period_start' => $stripeSub->current_period_start ?? null,
                'current_period_end' => $stripeSub->current_period_end ?? null,
            ]);

            // Find local subscription
            $localSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if (!$localSub) {
                Log::warning('Local subscription not found for Stripe ID', [
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'search_count' => LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->count(),
                ]);
                return;
            }
            
            Log::info('Local subscription found for sync', [
                'local_subscription_id' => $localSub->id,
                'user_id' => $localSub->user_id,
                'current_status' => $localSub->status,
                'stripe_status' => $localSub->stripe_status,
            ]);

            DB::beginTransaction();

            $currentPeriodEnd = isset($stripeSub->current_period_end) ? Carbon::createFromTimestamp($stripeSub->current_period_end) : null;
            $currentPeriodStart = isset($stripeSub->current_period_start) ? Carbon::createFromTimestamp($stripeSub->current_period_start) : null;

            // Only activate subscription if Stripe status is 'active'
            // Other statuses like 'incomplete', 'trialing', etc. should remain pending
            $shouldActivate = ($stripeSub->status === 'active');
            
            $localSub->update([
                'status' => $shouldActivate ? LocalSubscription::STATUS_ACTIVE : LocalSubscription::STATUS_PENDING,
                'starts_at' => $currentPeriodStart,
                'expires_at' => $currentPeriodEnd,
                'stripe_status' => $stripeSub->status,
                'stripe_latest_invoice_id' => $latestInvoiceId,
            ]);

            // Update user premium flags only if subscription is active
            $user = User::find($localSub->user_id);
            if ($user && $shouldActivate) {
                $user->update([
                    'has_premium' => true,
                    'premium_expires_at' => $currentPeriodEnd,
                ]);
                
                Log::info('User premium access activated from webhook', [
                    'user_id' => $user->id,
                    'subscription_id' => $localSub->id,
                    'premium_expires_at' => $currentPeriodEnd?->toISOString(),
                ]);
            } elseif ($user && !$shouldActivate) {
                Log::info('Subscription status updated but not activated - waiting for payment', [
                    'user_id' => $user->id,
                    'subscription_id' => $localSub->id,
                    'stripe_status' => $stripeSub->status,
                ]);
            }

            DB::commit();
            
            Log::info('Subscription synced successfully from webhook', [
                'local_subscription_id' => $localSub->id,
                'user_id' => $localSub->user_id,
                'new_status' => $localSub->status,
                'premium_expires_at' => $user->premium_expires_at?->toISOString(),
            ]);
            
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to sync subscription from webhook', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function markSubscriptionPaymentFailed(string $stripeSubscriptionId, ?string $latestInvoiceId = null): void
    {
        try {
            Log::info('Marking subscription payment as failed', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
            ]);
            
            $localSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if (!$localSub) {
                Log::warning('Local subscription not found when marking payment failed', [
                    'stripe_subscription_id' => $stripeSubscriptionId,
                ]);
                return;
            }
            
            Log::info('Local subscription found for payment failure update', [
                'local_subscription_id' => $localSub->id,
                'user_id' => $localSub->user_id,
                'current_status' => $localSub->status,
            ]);

            $localSub->update([
                'status' => LocalSubscription::STATUS_PENDING,
                'stripe_status' => 'past_due',
                'stripe_latest_invoice_id' => $latestInvoiceId,
            ]);
            
            Log::info('Subscription payment marked as failed', [
                'local_subscription_id' => $localSub->id,
                'user_id' => $localSub->user_id,
                'new_status' => $localSub->status,
                'stripe_status' => $localSub->stripe_status,
            ]);
            
        } catch (\Throwable $e) {
            Log::error('Failed to mark subscription payment failed', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle contract funding checkout session completion
     */
    private function handleContractFundingCheckout($session): void
    {
        try {
            Log::info('Handling contract funding checkout session completed', [
                'session_id' => $session->id,
                'customer_id' => $session->customer,
                'mode' => $session->mode,
                'payment_status' => $session->payment_status ?? 'unknown',
                'metadata' => $session->metadata ?? null,
            ]);

            // Check if this is a contract funding checkout
            $metadata = $session->metadata ?? null;
            $contractId = null;
            $type = null;

            if (is_array($metadata)) {
                $contractId = $metadata['contract_id'] ?? null;
                $type = $metadata['type'] ?? null;
            } elseif (is_object($metadata)) {
                $contractId = $metadata->contract_id ?? null;
                $type = $metadata->type ?? null;
            }

            if ($type !== 'contract_funding' || !$contractId) {
                Log::info('Checkout session is not for contract funding, skipping', [
                    'session_id' => $session->id,
                    'type' => $type,
                    'contract_id' => $contractId,
                ]);
                return;
            }

            // Only process if payment was successful
            if ($session->payment_status !== 'paid') {
                Log::warning('Contract funding checkout payment not paid', [
                    'session_id' => $session->id,
                    'payment_status' => $session->payment_status,
                    'contract_id' => $contractId,
                ]);
                return;
            }

            // Find the contract
            $contract = \App\Models\Contract::find($contractId);
            if (!$contract) {
                Log::error('Contract not found for funding checkout', [
                    'session_id' => $session->id,
                    'contract_id' => $contractId,
                ]);
                return;
            }

            // Check if payment was already processed
            if ($contract->payment && $contract->payment->status === 'completed') {
                Log::info('Contract payment already processed', [
                    'contract_id' => $contract->id,
                    'session_id' => $session->id,
                ]);
                return;
            }

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            
            // Retrieve the payment intent to get charge details
            $paymentIntentId = $session->payment_intent ?? null;
            if (!$paymentIntentId) {
                Log::error('No payment intent in checkout session', [
                    'session_id' => $session->id,
                    'contract_id' => $contractId,
                ]);
                return;
            }

            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

            if ($paymentIntent->status !== 'succeeded') {
                Log::warning('Payment intent not succeeded', [
                    'payment_intent_id' => $paymentIntentId,
                    'status' => $paymentIntent->status,
                    'contract_id' => $contractId,
                ]);
                return;
            }

            DB::beginTransaction();

            // Create transaction record
            $transaction = \App\Models\Transaction::create([
                'user_id' => $contract->brand_id,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_charge_id' => $paymentIntent->latest_charge ?? null,
                'status' => 'paid',
                'amount' => $contract->budget,
                'payment_method' => 'stripe',
                'payment_data' => [
                    'checkout_session' => $session->id,
                    'payment_intent' => $paymentIntent->id,
                    'intent' => $paymentIntent->toArray(),
                ],
                'paid_at' => now(),
                'contract_id' => $contract->id,
            ]);

            // Calculate payment amounts
            $platformFee = $contract->budget * 0.05; // 5% platform fee
            $creatorAmount = $contract->budget * 0.95; // 95% for creator

            // Create job payment record
            $jobPayment = \App\Models\JobPayment::create([
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'creator_id' => $contract->creator_id,
                'total_amount' => $contract->budget,
                'platform_fee' => $platformFee,
                'creator_amount' => $creatorAmount,
                'payment_method' => 'stripe_escrow',
                'status' => 'completed', // Payment is completed via checkout
                'transaction_id' => $transaction->id,
            ]);

            // Credit pending balance to creator escrow
            $balance = \App\Models\CreatorBalance::firstOrCreate(
                ['creator_id' => $contract->creator_id],
                [
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'total_earned' => 0,
                    'total_withdrawn' => 0,
                ]
            );
            $balance->increment('pending_balance', $jobPayment->creator_amount);

            // Update contract status
            $contract->update([
                'status' => 'active',
                'workflow_status' => 'active',
                'started_at' => now(),
            ]);

            DB::commit();

            // Notify both parties
            \App\Services\NotificationService::notifyCreatorOfContractStarted($contract);
            \App\Services\NotificationService::notifyBrandOfContractStarted($contract);

            Log::info('Contract funding processed successfully from checkout', [
                'contract_id' => $contract->id,
                'session_id' => $session->id,
                'payment_intent_id' => $paymentIntent->id,
                'transaction_id' => $transaction->id,
                'job_payment_id' => $jobPayment->id,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to handle contract funding checkout', [
                'session_id' => $session->id ?? 'unknown',
                'contract_id' => $contractId ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}



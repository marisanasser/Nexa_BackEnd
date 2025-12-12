<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\WebhookEvent;
use Stripe\Subscription as StripeSubscription;
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

            // Idempotency check using Database
            $eventId = $event->id ?? null;
            if ($eventId) {
                // First check if we have already processed this event
                $existingEvent = WebhookEvent::where('stripe_event_id', $eventId)->first();
                
                if ($existingEvent && $existingEvent->status === 'processed') {
                    Log::info('Stripe event already processed (Database)', ['event_id' => $eventId]);
                    return response()->json(['status' => 'duplicate', 'source' => 'database']);
                }
                
                // If it exists but failed or is processing, we might want to retry or just log it
                // For now, let's treat 'processing' as duplicate to avoid race conditions
                if ($existingEvent && $existingEvent->status === 'processing') {
                     Log::info('Stripe event currently processing', ['event_id' => $eventId]);
                     return response()->json(['status' => 'processing']);
                }

                // If not exists, create record
                if (!$existingEvent) {
                    try {
                        WebhookEvent::create([
                            'stripe_event_id' => $eventId,
                            'type' => $event->type,
                            'payload' => json_decode($payload, true),
                            'status' => 'processing',
                        ]);
                    } catch (\Exception $e) {
                         // Fallback for race condition where another request created it just now
                         Log::warning('Could not create WebhookEvent record, might be duplicate', ['error' => $e->getMessage()]);
                         return response()->json(['status' => 'duplicate']);
                    }
                }
            }
            
            // Fallback to Cache for extra safety (or if DB fails)
            if ($eventId && \Illuminate\Support\Facades\Cache::has('stripe_event_'.$eventId)) {
                return response()->json(['status' => 'duplicate', 'source' => 'cache']);
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
            
            try {
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
                        
                        if ($session->mode === 'subscription' && $session->subscription) {
                            $this->handleCheckoutSessionCompleted($session);
                        }
                        elseif ($session->mode === 'payment') {
                            $this->handleContractFundingCheckout($session);
                        }
                        elseif ($session->mode === 'setup') {
                            $this->handleSetupModeCheckout($session);
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
                        
                        $existingSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
                        if (!$existingSub) {
                            
                            Log::info('Creating subscription from invoice.paid event', [
                                'stripe_subscription_id' => $stripeSubscriptionId,
                            ]);
                            $this->createSubscriptionFromInvoice($invoice);
                        } else {
                            
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
                    
                    break;
                    
                default:
                    Log::debug('Unhandled Stripe event type', [
                        'event_id' => $event->id,
                        'event_type' => $event->type,
                        'object_type' => $event->data->object->object ?? 'unknown',
                    ]);
            }

            // Update event status to processed
            if (isset($existingEvent)) {
                $existingEvent->update(['status' => 'processed']);
            } elseif ($eventId) {
                try {
                    WebhookEvent::where('stripe_event_id', $eventId)->update(['status' => 'processed']);
                } catch (\Exception $e) {
                     // Ignore if update fails
                }
            }

            return response()->json(['received' => true]);
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook payload error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe webhook signature error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook processing error', [
                'error' => $e->getMessage(),
                'event_id' => $event->id ?? 'unknown',
            ]);

            // Update event status to failed
            if (isset($existingEvent)) {
                $existingEvent->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
            } elseif (isset($eventId)) {
                try {
                    WebhookEvent::where('stripe_event_id', $eventId)->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage()
                    ]);
                } catch (\Exception $ex) {
                     // Ignore
                }
            }

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

            
            $planId = null;
            
            
            if (isset($session->metadata)) {
                if (is_array($session->metadata) && isset($session->metadata['plan_id'])) {
                    $planId = (int) $session->metadata['plan_id'];
                } elseif (is_object($session->metadata) && isset($session->metadata->plan_id)) {
                    $planId = (int) $session->metadata->plan_id;
                }
            }
            
            
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

            
            $paymentStatus = $stripeSub->status ?? 'incomplete';
            $invoiceStatus = null;
            $paymentIntentStatus = null;
            
            
            if (isset($stripeSub->latest_invoice) && is_object($stripeSub->latest_invoice)) {
                $invoiceStatus = $stripeSub->latest_invoice->status ?? null;
                if (isset($stripeSub->latest_invoice->payment_intent)) {
                    if (is_object($stripeSub->latest_invoice->payment_intent)) {
                        $paymentIntentStatus = $stripeSub->latest_invoice->payment_intent->status ?? null;
                    } elseif (is_string($stripeSub->latest_invoice->payment_intent)) {
                        
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
            
            
            if (!$paymentSuccessful) {
                Log::info('Payment not yet successful, subscription will be created when invoice.paid event is received', [
                    'subscription_id' => $stripeSubscriptionId,
                    'status' => $paymentStatus,
                ]);
                return;
            }

            
            $existingSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if ($existingSub) {
                Log::info('Subscription already exists, syncing instead', [
                    'subscription_id' => $existingSub->id,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                ]);
                $this->syncSubscription($stripeSubscriptionId, $stripeSub->latest_invoice ?? null);
                return;
            }

            
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
            
            
            if (!$paymentIntentId && isset($stripeSub->latest_invoice) && is_object($stripeSub->latest_invoice)) {
                if (is_string($stripeSub->latest_invoice->payment_intent ?? null)) {
                    $paymentIntentId = $stripeSub->latest_invoice->payment_intent;
                }
            }
            
            $stripeSubId = is_object($session->subscription) ? $session->subscription->id : $session->subscription;
            $transactionId = $paymentIntentId ?? $invoiceId ?? 'stripe_' . $stripeSubscriptionId;
            $durationMonths = (int)($session->metadata->duration_months ?? 1);
            $cancelAt = Carbon::now()->addMonths($durationMonths)->timestamp;
                StripeSubscription::update($stripeSubId, [
                'cancel_at' => $cancelAt,
            ]);
            
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

            
            try {
                Log::info('Creating subscription record', [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'transaction_id' => $transaction->id,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'stripe_status' => $stripeSub->status ?? 'incomplete',
                ]);
                
                
                if ($plan->duration_months > 1) {
                    $cancelAt = \Carbon\Carbon::now()->addMonths($plan->duration_months)->timestamp;
                    try {
                        $stripeSub = \Stripe\Subscription::update($stripeSubscriptionId, [
                            'cancel_at' => $cancelAt,
                        ]);
                        Log::info('Set cancel_at for subscription', [
                            'subscription_id' => $stripeSubscriptionId,
                            'plan_id' => $plan->id,
                            'duration_months' => $plan->duration_months,
                            'cancel_at_timestamp' => $cancelAt,
                            'cancel_at_date' => \Carbon\Carbon::createFromTimestamp($cancelAt)->toDateTimeString(),
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to set cancel_at for subscription', [
                            'subscription_id' => $stripeSubscriptionId,
                            'error' => $e->getMessage(),
                        ]);
                        
                    }
                }
                
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

            
            if ($plan->duration_months > 1) {
                $cancelAt = \Carbon\Carbon::now()->addMonths($plan->duration_months)->timestamp;
                try {
                    $stripeSub = \Stripe\Subscription::update($stripeSubscriptionId, [
                        'cancel_at' => $cancelAt,
                    ]);
                    Log::info('Set cancel_at for subscription from invoice', [
                        'subscription_id' => $stripeSubscriptionId,
                        'plan_id' => $plan->id,
                        'duration_months' => $plan->duration_months,
                        'cancel_at_timestamp' => $cancelAt,
                        'cancel_at_date' => \Carbon\Carbon::createFromTimestamp($cancelAt)->toDateTimeString(),
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to set cancel_at for subscription from invoice', [
                        'subscription_id' => $stripeSubscriptionId,
                        'error' => $e->getMessage(),
                    ]);
                    
                }
            }
            
            
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

            
            
            $shouldActivate = ($stripeSub->status === 'active');
            
            $localSub->update([
                'status' => $shouldActivate ? LocalSubscription::STATUS_ACTIVE : LocalSubscription::STATUS_PENDING,
                'starts_at' => $currentPeriodStart,
                'expires_at' => $currentPeriodEnd,
                'stripe_status' => $stripeSub->status,
                'stripe_latest_invoice_id' => $latestInvoiceId,
            ]);

            
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

    
    private function handleContractFundingCheckout($session): void
    {
        try {
            Log::info('Handling payment mode checkout session completed', [
                'session_id' => $session->id,
                'customer_id' => $session->customer,
                'mode' => $session->mode,
                'payment_status' => $session->payment_status ?? 'unknown',
                'metadata' => $session->metadata ?? null,
            ]);

            
            $metadata = $session->metadata ?? null;
            $contractId = null;
            $type = null;
            $userId = null;
            $amount = null;

            if (is_array($metadata)) {
                $contractId = $metadata['contract_id'] ?? null;
                $type = $metadata['type'] ?? null;
                $userId = $metadata['user_id'] ?? null;
                $amount = $metadata['amount'] ?? null;
            } elseif (is_object($metadata)) {
                $contractId = $metadata->contract_id ?? null;
                $type = $metadata->type ?? null;
                $userId = $metadata->user_id ?? null;
                $amount = $metadata->amount ?? null;
            }

            
            if ($type === 'offer_funding') {
                $this->handleOfferFundingCheckout($session, $userId, $amount);
                return;
            }

            
            if ($type !== 'contract_funding' || !$contractId) {
                Log::info('Checkout session is not for contract or offer funding, skipping', [
                    'session_id' => $session->id,
                    'type' => $type,
                    'contract_id' => $contractId,
                ]);
                return;
            }

            
            if ($session->payment_status !== 'paid') {
                Log::warning('Contract funding checkout payment not paid', [
                    'session_id' => $session->id,
                    'payment_status' => $session->payment_status,
                    'contract_id' => $contractId,
                ]);
                return;
            }

            
            $contract = \App\Models\Contract::find($contractId);
            if (!$contract) {
                Log::error('Contract not found for funding checkout', [
                    'session_id' => $session->id,
                    'contract_id' => $contractId,
                ]);
                return;
            }

            
            if ($contract->payment && $contract->payment->status === 'completed') {
                Log::info('Contract payment already processed', [
                    'contract_id' => $contract->id,
                    'session_id' => $session->id,
                ]);
                return;
            }

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            
            
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

            
            $platformFee = $contract->budget * 0.05; 
            $creatorAmount = $contract->budget * 0.95; 

            
            $jobPayment = \App\Models\JobPayment::create([
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'creator_id' => $contract->creator_id,
                'total_amount' => $contract->budget,
                'platform_fee' => $platformFee,
                'creator_amount' => $creatorAmount,
                'payment_method' => 'stripe_escrow',
                'status' => 'completed', 
                'transaction_id' => $transaction->id,
            ]);

            
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

            
            $contract->update([
                'status' => 'active',
                'workflow_status' => 'active',
                'started_at' => now(),
            ]);

            DB::commit();

            
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

    
    private function handleOfferFundingCheckout($session, $userId, $amount): void
    {
        try {
            Log::info('Handling offer funding checkout session completed', [
                'session_id' => $session->id,
                'user_id' => $userId,
                'amount' => $amount,
                'payment_status' => $session->payment_status ?? 'unknown',
            ]);

            
            if ($session->payment_status !== 'paid') {
                Log::warning('Offer funding checkout payment not paid', [
                    'session_id' => $session->id,
                    'payment_status' => $session->payment_status,
                ]);
                return;
            }

            
            $user = null;
            if ($userId) {
                $user = \App\Models\User::find($userId);
            }

            if (!$user && $session->customer) {
                $user = \App\Models\User::where('stripe_customer_id', $session->customer)->first();
            }

            if (!$user) {
                Log::error('User not found for offer funding checkout', [
                    'session_id' => $session->id,
                    'user_id' => $userId,
                    'customer_id' => $session->customer,
                ]);
                return;
            }

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            
            
            $paymentIntentId = $session->payment_intent ?? null;
            if (!$paymentIntentId) {
                Log::error('No payment intent in offer funding checkout session', [
                    'session_id' => $session->id,
                    'user_id' => $user->id,
                ]);
                return;
            }

            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId, [
                'expand' => ['payment_method', 'charges.data.payment_method_details'],
            ]);

            if ($paymentIntent->status !== 'succeeded') {
                Log::warning('Payment intent not succeeded for offer funding', [
                    'payment_intent_id' => $paymentIntentId,
                    'status' => $paymentIntent->status,
                    'user_id' => $user->id,
                ]);
                return;
            }

            
            $transactionAmount = $amount ? (float) $amount : ($session->amount_total / 100);

            
            $metadata = $session->metadata ?? null;

            
            $campaignId = null;
            if (is_array($metadata)) {
                $campaignId = $metadata['campaign_id'] ?? null;
            } elseif (is_object($metadata)) {
                $campaignId = $metadata->campaign_id ?? null;
            }

            
            if ($campaignId) {
                try {
                    $campaign = \App\Models\Campaign::find($campaignId);
                    if ($campaign) {
                        
                        $campaign->update([
                            'final_price' => $transactionAmount,
                        ]);
                        
                        Log::info('Campaign final_price updated from offer funding', [
                            'campaign_id' => $campaignId,
                            'final_price' => $transactionAmount,
                            'user_id' => $user->id,
                            'session_id' => $session->id,
                        ]);
                    } else {
                        Log::warning('Campaign not found for offer funding', [
                            'campaign_id' => $campaignId,
                            'session_id' => $session->id,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to update campaign final_price from offer funding', [
                        'campaign_id' => $campaignId,
                        'session_id' => $session->id,
                        'error' => $e->getMessage(),
                    ]);
                    
                }
            }

            
            $charge = null;
            $cardBrand = null;
            $cardLast4 = null;
            $cardHolderName = null;

            if (!empty($paymentIntent->charges->data)) {
                $charge = $paymentIntent->charges->data[0];
                $paymentMethodDetails = $charge->payment_method_details->card ?? null;
                if ($paymentMethodDetails) {
                    $cardBrand = $paymentMethodDetails->brand ?? null;
                    $cardLast4 = $paymentMethodDetails->last4 ?? null;
                    $cardHolderName = $paymentMethodDetails->name ?? null;
                }
            }

            DB::beginTransaction();

            try {
                
                $existingTransaction = \App\Models\Transaction::where('stripe_payment_intent_id', $paymentIntentId)
                    ->where('user_id', $user->id)
                    ->first();

                if ($existingTransaction) {
                    Log::info('Offer funding transaction already exists', [
                        'transaction_id' => $existingTransaction->id,
                        'payment_intent_id' => $paymentIntentId,
                        'user_id' => $user->id,
                    ]);
                    
                    DB::commit();
                    return;
                }

                
                $transaction = \App\Models\Transaction::create([
                    'user_id' => $user->id,
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'stripe_charge_id' => $charge->id ?? null,
                    'status' => 'paid',
                    'amount' => $transactionAmount,
                    'payment_method' => 'stripe',
                    'card_brand' => $cardBrand,
                    'card_last4' => $cardLast4,
                    'card_holder_name' => $cardHolderName,
                    'payment_data' => [
                        'checkout_session_id' => $session->id,
                        'payment_intent' => $paymentIntent->id,
                        'charge_id' => $charge->id ?? null,
                        'type' => 'offer_funding',
                        'metadata' => $session->metadata ?? null,
                    ],
                    'paid_at' => now(),
                ]);

                DB::commit();

                Log::info('Offer funding transaction created successfully', [
                    'transaction_id' => $transaction->id,
                    'user_id' => $user->id,
                    'session_id' => $session->id,
                    'payment_intent_id' => $paymentIntent->id,
                    'amount' => $transactionAmount,
                ]);

                
                try {
                    $metadata = $session->metadata ?? null;
                    $fundingData = [
                        'transaction_id' => $transaction->id,
                        'session_id' => $session->id,
                        'payment_intent_id' => $paymentIntent->id,
                    ];
                    
                    if (is_array($metadata)) {
                        $fundingData['creator_id'] = $metadata['creator_id'] ?? null;
                        $fundingData['chat_room_id'] = $metadata['chat_room_id'] ?? null;
                        $fundingData['campaign_id'] = $metadata['campaign_id'] ?? null;
                    } elseif (is_object($metadata)) {
                        $fundingData['creator_id'] = $metadata->creator_id ?? null;
                        $fundingData['chat_room_id'] = $metadata->chat_room_id ?? null;
                        $fundingData['campaign_id'] = $metadata->campaign_id ?? null;
                    }
                    
                    $notification = \App\Models\Notification::createPlatformFundingSuccess(
                        $user->id,
                        $transactionAmount,
                        $fundingData
                    );
                    
                    
                    \App\Services\NotificationService::sendSocketNotification($user->id, $notification);
                    
                    Log::info('Platform funding success notification created', [
                        'notification_id' => $notification->id,
                        'user_id' => $user->id,
                        'amount' => $transactionAmount,
                    ]);
                } catch (\Exception $e) {
                    
                    Log::error('Failed to create platform funding success notification', [
                        'user_id' => $user->id,
                        'amount' => $transactionAmount,
                        'error' => $e->getMessage(),
                    ]);
                }

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to handle offer funding checkout', [
                'session_id' => $session->id ?? 'unknown',
                'user_id' => $userId ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    
    private function handleSetupModeCheckout($session): void
    {
        try {
            Log::info('Handling setup mode checkout session completed', [
                'session_id' => $session->id,
                'customer_id' => $session->customer,
                'mode' => $session->mode,
                'setup_intent' => $session->setup_intent ?? null,
            ]);

            
            $setupIntentId = $session->setup_intent ?? null;
            if (!$setupIntentId) {
                Log::warning('No setup intent in checkout session', [
                    'session_id' => $session->id,
                ]);
                return;
            }

            
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $setupIntent = \Stripe\SetupIntent::retrieve($setupIntentId, [
                'expand' => ['payment_method']
            ]);

            if ($setupIntent->status !== 'succeeded') {
                Log::warning('Setup intent not succeeded', [
                    'setup_intent_id' => $setupIntentId,
                    'status' => $setupIntent->status,
                ]);
                return;
            }

            
            $paymentMethodId = null;
            if (is_object($setupIntent->payment_method)) {
                $paymentMethodId = $setupIntent->payment_method->id ?? null;
            } elseif (is_string($setupIntent->payment_method)) {
                $paymentMethodId = $setupIntent->payment_method;
            }

            if (!$paymentMethodId) {
                Log::warning('No payment method in setup intent', [
                    'setup_intent_id' => $setupIntentId,
                ]);
                return;
            }

            
            $customerId = $session->customer;
            if (!$customerId) {
                Log::warning('No customer ID in checkout session', [
                    'session_id' => $session->id,
                ]);
                return;
            }

            $user = \App\Models\User::where('stripe_customer_id', $customerId)->first();
            if (!$user) {
                Log::warning('User not found for Stripe customer', [
                    'customer_id' => $customerId,
                    'session_id' => $session->id,
                ]);
                return;
            }

            
            
            if ($user->stripe_payment_method_id === $paymentMethodId) {
                Log::info('Payment method already stored, skipping webhook processing', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodId,
                    'session_id' => $session->id,
                ]);
                return;
            }

            
            $isCreatorOrStudent = $user->isCreator() || $user->isStudent();

            
            $paymentMethod = null;
            $cardBrand = null;
            $cardLast4 = null;
            $cardExpMonth = null;
            $cardExpYear = null;
            
            try {
                $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
                if ($paymentMethod->type === 'card' && isset($paymentMethod->card)) {
                    $cardBrand = $paymentMethod->card->brand ?? null;
                    $cardLast4 = $paymentMethod->card->last4 ?? null;
                    $cardExpMonth = $paymentMethod->card->exp_month ?? null;
                    $cardExpYear = $paymentMethod->card->exp_year ?? null;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to retrieve payment method details from Stripe', [
                    'payment_method_id' => $paymentMethodId,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::beginTransaction();
            
            try {
                
                $updatedRows = DB::table('users')
                    ->where('id', $user->id)
                    ->update(['stripe_payment_method_id' => $paymentMethodId]);

                
                $actualStoredId = DB::table('users')
                    ->where('id', $user->id)
                    ->value('stripe_payment_method_id');

                if ($actualStoredId !== $paymentMethodId) {
                    
                    $user->refresh();
                    $user->stripe_payment_method_id = $paymentMethodId;
                    $user->save();
                    $user->refresh();
                }

                
                $stripeCardMethod = null;
                if ($isCreatorOrStudent) {
                    
                    $stripeCardMethod = \App\Models\WithdrawalMethod::where('code', 'stripe_card')->first();
                    
                    if (!$stripeCardMethod) {
                        
                        $stripeCardMethod = \App\Models\WithdrawalMethod::create([
                            'code' => 'stripe_card',
                            'name' => 'Cartão de Crédito/Débito (Stripe)',
                            'description' => 'Receba seus saques diretamente no seu cartão de crédito ou débito cadastrado no Stripe',
                            'min_amount' => 10.00,
                            'max_amount' => 10000.00,
                            'processing_time' => '1-3 dias úteis',
                            'fee' => 0.00,
                            'is_active' => true,
                            'required_fields' => [],
                            'field_config' => [],
                            'sort_order' => 100,
                        ]);
                        
                        Log::info('Created stripe_card withdrawal method in withdrawal_methods table', [
                            'withdrawal_method_id' => $stripeCardMethod->id,
                            'user_id' => $user->id,
                        ]);
                    } else {
                        
                        if (!$stripeCardMethod->is_active) {
                            $stripeCardMethod->update(['is_active' => true]);
                            Log::info('Activated stripe_card withdrawal method', [
                                'withdrawal_method_id' => $stripeCardMethod->id,
                                'user_id' => $user->id,
                            ]);
                        }
                    }
                } else {
                    Log::info('User is not creator or student, skipping withdrawal_methods table update', [
                        'user_id' => $user->id,
                        'user_role' => $user->role,
                    ]);
                }

                DB::commit();

                Log::info('Updated user payment method ID and withdrawal methods from setup mode checkout', [
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'is_creator_or_student' => $isCreatorOrStudent,
                    'payment_method_id' => $paymentMethodId,
                    'updated_rows' => $updatedRows,
                    'verified' => ($user->stripe_payment_method_id === $paymentMethodId),
                    'card_brand' => $cardBrand,
                    'card_last4' => $cardLast4,
                    'withdrawal_method_updated' => $isCreatorOrStudent && $stripeCardMethod ? true : false,
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to update user and withdrawal methods in transaction', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to handle setup mode checkout', [
                'session_id' => $session->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}


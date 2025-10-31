<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\Subscription as StripeSubscription;

class StripeBillingController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create/activate a subscription for creators via Stripe Billing
     */
    public function createSubscription(Request $request): JsonResponse
    {
        $request->validate([
            'subscription_plan_id' => 'required|integer|exists:subscription_plans,id',
            'payment_method_id' => 'required|string',
        ]);
        $user = auth()->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
        }
        if (!$user->isCreator()) {
            return response()->json(['success' => false, 'message' => 'Only creators can subscribe'], 403);
        }

        // Prevent duplicate active access
        if ($user->hasPremiumAccess()) {
            return response()->json(['success' => false, 'message' => 'User already has premium access'], 409);
        }
        $plan = SubscriptionPlan::find($request->subscription_plan_id);
        if (!$plan || empty($plan->stripe_price_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe price not configured for plan',
                'plan_id' => $plan?->id,
            ], 409);
        }
        DB::beginTransaction();
        try {
            // Ensure Stripe customer exists
            if (!$user->stripe_customer_id) {
                $customer = Customer::create([
                    'email' => $user->email,
                    'metadata' => [ 'user_id' => $user->id, 'name' => $user->name ],
                ]);
                $user->update(['stripe_customer_id' => $customer->id]);
            } else {
                $customer = Customer::retrieve($user->stripe_customer_id);
            }

            // Attach and set default payment method
            $pm = StripePaymentMethod::retrieve($request->payment_method_id);
            $pm->attach(['customer' => $customer->id]);
            Customer::update($customer->id, [
                'invoice_settings' => ['default_payment_method' => $pm->id],
            ]);
            // Create subscription
            $stripeSub = StripeSubscription::create([
                'customer' => $customer->id,
                'items' => [
                    ['price' => $plan->stripe_price_id],
                ],
                'payment_behavior' => 'default_incomplete',
                'expand' => ['latest_invoice.payment_intent'],
            ]);
            // Safely extract invoice and payment intent IDs from initial subscription creation
            $initialInvoiceId = null;
            $initialPaymentIntentId = null;
            
            if (isset($stripeSub->latest_invoice)) {
                if (is_object($stripeSub->latest_invoice)) {
                    $initialInvoiceId = $stripeSub->latest_invoice->id ?? null;
                    
                    if (isset($stripeSub->latest_invoice->payment_intent)) {
                        if (is_object($stripeSub->latest_invoice->payment_intent)) {
                            $initialPaymentIntentId = $stripeSub->latest_invoice->payment_intent->id ?? null;
                        } elseif (is_string($stripeSub->latest_invoice->payment_intent)) {
                            $initialPaymentIntentId = $stripeSub->latest_invoice->payment_intent;
                        }
                    }
                } elseif (is_string($stripeSub->latest_invoice)) {
                    $initialInvoiceId = $stripeSub->latest_invoice;
                }
            }
            
            // Persist local subscription in pending state
            $localTx = Transaction::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $initialPaymentIntentId,
                'status' => 'pending', // Start as pending, will be updated to 'paid' when payment succeeds
                'amount' => $plan->price,
                'payment_method' => 'stripe',
                'payment_data' => [ 'invoice' => $initialInvoiceId ],
            ]);

            $localSub = Subscription::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'status' => Subscription::STATUS_PENDING,
                'amount_paid' => $plan->price,
                'payment_method' => 'stripe',
                'transaction_id' => $localTx->id,
                'auto_renew' => true,
                'stripe_subscription_id' => $stripeSub->id,
                'stripe_latest_invoice_id' => $initialInvoiceId,
                'stripe_status' => $stripeSub->status ?? 'incomplete',
            ]);

            DB::commit();

            // Refresh subscription from Stripe to get latest status
            $stripeSub = StripeSubscription::retrieve($stripeSub->id, [
                'expand' => ['latest_invoice.payment_intent']
            ]);

            // Safely extract invoice and payment intent
            // Stripe may return objects or string IDs, so we need to check types
            $invoice = null;
            $pi = null;
            
            if (isset($stripeSub->latest_invoice)) {
                // Check if latest_invoice is an object (expanded) or string (ID)
                if (is_object($stripeSub->latest_invoice)) {
                    $invoice = $stripeSub->latest_invoice;
                    
                    // Check if payment_intent is an object or string
                    if (isset($invoice->payment_intent)) {
                        if (is_object($invoice->payment_intent)) {
                            $pi = $invoice->payment_intent;
                        }
                        // If it's a string ID, we can't access properties - will remain null
                    }
                }
                // If latest_invoice is a string ID, we can't access it - will remain null
            }
            
            // Handle 3D Secure authentication
            if ($pi && is_object($pi) && isset($pi->status) && $pi->status === 'requires_action') {
                return response()->json([
                    'success' => true,
                    'requires_action' => true,
                    'client_secret' => $pi->client_secret ?? null,
                    'subscription_id' => $localSub->id,
                ]);
            }

            // Check if payment succeeded immediately
            $paymentSucceeded = false;
            if ($invoice && is_object($invoice) && isset($invoice->status) && $invoice->status === 'paid') {
                $paymentSucceeded = true;
            } elseif ($pi && is_object($pi) && isset($pi->status) && $pi->status === 'succeeded') {
                $paymentSucceeded = true;
            } elseif (isset($stripeSub->status) && $stripeSub->status === 'active') {
                $paymentSucceeded = true;
            }

            // If payment succeeded immediately, activate subscription now
            if ($paymentSucceeded) {
                DB::beginTransaction();
                try {
                    $currentPeriodEnd = isset($stripeSub->current_period_end) 
                        ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end) 
                        : null;
                    $currentPeriodStart = isset($stripeSub->current_period_start) 
                        ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_start) 
                        : null;

                    $invoiceId = null;
                    if ($invoice && is_object($invoice) && isset($invoice->id)) {
                        $invoiceId = $invoice->id;
                    } elseif (is_string($stripeSub->latest_invoice ?? null)) {
                        // latest_invoice is a string ID
                        $invoiceId = $stripeSub->latest_invoice;
                    }
                    
                    $paymentIntentId = null;
                    if ($pi && is_object($pi) && isset($pi->id)) {
                        $paymentIntentId = $pi->id;
                    } elseif ($invoice && is_object($invoice) && is_string($invoice->payment_intent ?? null)) {
                        // payment_intent is a string ID
                        $paymentIntentId = $invoice->payment_intent;
                    }

                    $localSub->update([
                        'status' => Subscription::STATUS_ACTIVE,
                        'starts_at' => $currentPeriodStart,
                        'expires_at' => $currentPeriodEnd,
                        'stripe_status' => $stripeSub->status ?? 'active',
                        'stripe_latest_invoice_id' => $invoiceId,
                    ]);

                    $localTx->update([
                        'status' => 'paid',
                        'stripe_payment_intent_id' => $paymentIntentId ?? $localTx->stripe_payment_intent_id,
                        'paid_at' => now(),
                    ]);

                    $user->update([
                        'has_premium' => true,
                        'premium_expires_at' => $currentPeriodEnd,
                    ]);

                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'requires_action' => false,
                        'subscription_id' => $localSub->id,
                        'subscription_status' => 'active',
                        'activated' => true,
                    ]);
                } catch (\Throwable $e) {
                    DB::rollBack();
                    Log::error('Failed to activate subscription immediately', [
                        'subscription_id' => $localSub->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Payment is still processing, return pending status
            return response()->json([
                'success' => true,
                'requires_action' => false,
                'subscription_id' => $localSub->id,
                'subscription_status' => 'pending',
                'activated' => false,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Stripe createSubscription error', [ 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ]);
            return response()->json(['success' => false, 'message' => 'Subscription creation failed'], 500);
        }
    }

    /**
     * Get current subscription status (from local mirror)
     */
    public function getSubscriptionStatus(): JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated'], 401);
        }

        $active = $user->activeSubscription;
        return response()->json([
            'has_premium' => $user->has_premium,
            'premium_expires_at' => $user->premium_expires_at?->format('Y-m-d H:i:s'),
            'free_trial_expires_at' => $user->free_trial_expires_at?->format('Y-m-d H:i:s'),
            'is_premium_active' => $user->hasPremiumAccess(),
            'is_on_trial' => $user->isOnTrial(),
            'student_verified' => $user->student_verified,
            'student_expires_at' => $user->student_expires_at?->format('Y-m-d H:i:s'),
            'is_student' => $user->isStudent(),
            'subscription' => $active ? [
                'id' => $active->id,
                'status' => $active->status,
                'expires_at' => $active->expires_at?->format('Y-m-d H:i:s'),
                'stripe_status' => $active->stripe_status ?? null,
            ] : null,
        ]);
    }
}



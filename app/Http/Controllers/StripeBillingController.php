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
            StripePaymentMethod::attach($pm->id, ['customer' => $customer->id]);
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

            // Persist local subscription in pending state
            $localTx = Transaction::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $stripeSub->latest_invoice->payment_intent?->id,
                'status' => $stripeSub->status,
                'amount' => $plan->price,
                'payment_method' => 'stripe',
                'payment_data' => [ 'invoice' => $stripeSub->latest_invoice?->id ],
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
                'stripe_latest_invoice_id' => $stripeSub->latest_invoice?->id,
                'stripe_status' => $stripeSub->status,
            ]);

            DB::commit();
            Log::info('Stripe createSubscription success', [
                'userId' => $user->id,
                'subscriptionId' => $localSub->id,
                'stripeSubscriptionId' => $stripeSub->id,
            ]);

            $pi = $stripeSub->latest_invoice->payment_intent ?? null;
            if ($pi && $pi->status === 'requires_action') {
                return response()->json([
                    'success' => true,
                    'requires_action' => true,
                    'client_secret' => $pi->client_secret,
                    'subscription_id' => $localSub->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'requires_action' => false,
                'subscription_id' => $localSub->id,
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
            'subscription' => $active ? [
                'id' => $active->id,
                'status' => $active->status,
                'expires_at' => $active->expires_at?->format('Y-m-d H:i:s'),
                'stripe_status' => $active->stripe_status ?? null,
            ] : null,
        ]);
    }
}



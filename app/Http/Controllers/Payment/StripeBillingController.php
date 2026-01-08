<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Base\Controller;
use App\Models\Payment\Subscription;
use App\Models\Payment\SubscriptionPlan;
use App\Models\Payment\Transaction;
use App\Models\User\User;
use Carbon\Carbon;
use DateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Throwable;

class StripeBillingController extends Controller
{
    public function __construct()
    {
        $stripeSecret = config('services.stripe.secret');
        Stripe::setApiKey($stripeSecret);

        Log::info('StripeBillingController initialized', [
            'has_stripe_secret' => !empty($stripeSecret),
            'stripe_secret_length' => $stripeSecret ? strlen($stripeSecret) : 0,
        ]);
    }

    public function createSubscription(Request $request): JsonResponse
    {
        $request->validate([
            'subscription_plan_id' => 'required|integer|exists:subscription_plans,id',
            'payment_method_id' => 'required|string',
        ]);
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
        }
        if (!$user->isCreator()) {
            return response()->json(['success' => false, 'message' => 'Only creators can subscribe'], 403);
        }

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
        Log::info('Stripe subscription creation started', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'stripe_price_id' => $plan->stripe_price_id,
            'payment_method_id' => $request->payment_method_id,
            'plan_price' => $plan->price,
        ]);

        DB::beginTransaction();

        try {
            if (!$user->stripe_customer_id) {
                Log::info('Creating new Stripe customer for subscription', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);

                $customer = Customer::create([
                    'email' => $user->email,
                    'metadata' => ['user_id' => $user->id, 'name' => $user->name],
                ]);
                $user->update(['stripe_customer_id' => $customer->id]);

                Log::info('Stripe customer created for subscription', [
                    'user_id' => $user->id,
                    'customer_id' => $customer->id,
                ]);
            } else {
                Log::info('Retrieving existing Stripe customer for subscription', [
                    'user_id' => $user->id,
                    'customer_id' => $user->stripe_customer_id,
                ]);

                $customer = Customer::retrieve($user->stripe_customer_id);
            }

            Log::info('Attaching payment method to customer', [
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'payment_method_id' => $request->payment_method_id,
            ]);

            $pm = StripePaymentMethod::retrieve($request->payment_method_id);
            $pm->attach(['customer' => $customer->id]);
            Customer::update($customer->id, [
                'invoice_settings' => ['default_payment_method' => $pm->id],
            ]);

            if ($user->stripe_payment_method_id !== $pm->id) {
                $updatedRows = DB::table('users')
                    ->where('id', $user->id)
                    ->update(['stripe_payment_method_id' => $pm->id])
                ;

                $actualStoredId = DB::table('users')
                    ->where('id', $user->id)
                    ->value('stripe_payment_method_id')
                ;

                if ($actualStoredId !== $pm->id) {
                    $user->refresh();
                    $user->stripe_payment_method_id = $pm->id;
                    $user->save();
                    $user->refresh();
                } else {
                    $user->refresh();
                }

                Log::info('Updated user stripe_payment_method_id after attaching payment method for subscription', [
                    'user_id' => $user->id,
                    'stripe_payment_method_id' => $pm->id,
                    'updated_rows' => $updatedRows,
                    'verified' => ($user->stripe_payment_method_id === $pm->id),
                ]);
            }

            Log::info('Creating Stripe subscription', [
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'stripe_price_id' => $plan->stripe_price_id,
            ]);

            $cancelAt = null;
            if ($plan->duration_months > 1) {
                $cancelAt = Carbon::now()->addMonths($plan->duration_months)->timestamp;
            }

            $subscriptionParams = [
                'customer' => $customer->id,
                'items' => [
                    ['price' => $plan->stripe_price_id],
                ],
                'payment_behavior' => 'default_incomplete',
                'expand' => ['latest_invoice.payment_intent'],
            ];

            if ($cancelAt) {
                $subscriptionParams['cancel_at'] = $cancelAt;
                Log::info('Setting subscription cancel_at', [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'duration_months' => $plan->duration_months,
                    'cancel_at_timestamp' => $cancelAt,
                    'cancel_at_date' => Carbon::createFromTimestamp($cancelAt)->toDateTimeString(),
                ]);
            }

            $stripeSub = StripeSubscription::create($subscriptionParams);

            Log::info('Stripe subscription created', [
                'user_id' => $user->id,
                'subscription_id' => $stripeSub->id,
                'status' => $stripeSub->status ?? 'unknown',
                'latest_invoice' => $stripeSub->latest_invoice->id ?? 'no_invoice',
            ]);

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

            $localTx = Transaction::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $initialPaymentIntentId,
                'status' => 'pending',
                'amount' => $plan->price,
                'payment_method' => 'stripe',
                'payment_data' => ['invoice' => $initialInvoiceId],
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

            Log::info('Local subscription and transaction records created', [
                'user_id' => $user->id,
                'local_subscription_id' => $localSub->id,
                'transaction_id' => $localTx->id,
                'stripe_subscription_id' => $stripeSub->id,
            ]);

            Log::info('Refreshing subscription from Stripe to get latest status', [
                'user_id' => $user->id,
                'stripe_subscription_id' => $stripeSub->id,
            ]);

            $stripeSub = StripeSubscription::retrieve($stripeSub->id, [
                'expand' => ['latest_invoice.payment_intent'],
            ]);

            Log::info('Stripe subscription refreshed', [
                'user_id' => $user->id,
                'subscription_id' => $stripeSub->id,
                'status' => $stripeSub->status ?? 'unknown',
                'has_latest_invoice' => isset($stripeSub->latest_invoice),
            ]);

            $invoice = null;
            $pi = null;

            if (isset($stripeSub->latest_invoice)) {
                if (is_object($stripeSub->latest_invoice)) {
                    $invoice = $stripeSub->latest_invoice;

                    if (isset($invoice->payment_intent)) {
                        if (is_object($invoice->payment_intent)) {
                            $pi = $invoice->payment_intent;
                        }
                    }
                }
            }

            if ($pi && is_object($pi) && isset($pi->status) && 'requires_action' === $pi->status) {
                Log::info('Payment requires 3D Secure authentication', [
                    'user_id' => $user->id,
                    'subscription_id' => $localSub->id,
                    'payment_intent_id' => $pi->id ?? 'no_id',
                    'payment_intent_status' => $pi->status,
                ]);

                return response()->json([
                    'success' => true,
                    'requires_action' => true,
                    'client_secret' => $pi->client_secret ?? null,
                    'subscription_id' => $localSub->id,
                ]);
            }

            Log::info('Checking if payment succeeded immediately', [
                'user_id' => $user->id,
                'subscription_id' => $localSub->id,
                'invoice_status' => $invoice && is_object($invoice) ? ($invoice->status ?? 'unknown') : 'no_invoice',
                'payment_intent_status' => $pi && is_object($pi) ? ($pi->status ?? 'unknown') : 'no_pi',
                'subscription_status' => $stripeSub->status ?? 'unknown',
            ]);

            $paymentSucceeded = false;
            if ($invoice && is_object($invoice) && isset($invoice->status) && 'paid' === $invoice->status) {
                $paymentSucceeded = true;
            } elseif ($pi && is_object($pi) && isset($pi->status) && 'succeeded' === $pi->status) {
                $paymentSucceeded = true;
            } elseif (isset($stripeSub->status) && 'active' === $stripeSub->status) {
                $paymentSucceeded = true;
            }

            if ($paymentSucceeded) {
                Log::info('Payment succeeded immediately, activating subscription', [
                    'user_id' => $user->id,
                    'subscription_id' => $localSub->id,
                ]);

                DB::beginTransaction();

                try {
                    $currentPeriodEnd = isset($stripeSub->current_period_end)
                        ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                        : null;
                    $currentPeriodStart = isset($stripeSub->current_period_start)
                        ? Carbon::createFromTimestamp($stripeSub->current_period_start)
                        : null;

                    $invoiceId = null;
                    if ($invoice && is_object($invoice) && isset($invoice->id)) {
                        $invoiceId = $invoice->id;
                    } elseif (is_string($stripeSub->latest_invoice ?? null)) {
                        $invoiceId = $stripeSub->latest_invoice;
                    }

                    $paymentIntentId = null;
                    if ($pi && is_object($pi) && isset($pi->id)) {
                        $paymentIntentId = $pi->id;
                    } elseif ($invoice && is_object($invoice) && is_string($invoice->payment_intent ?? null)) {
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

                    Log::info('Subscription activated successfully', [
                        'user_id' => $user->id,
                        'subscription_id' => $localSub->id,
                        'transaction_id' => $localTx->id,
                        'premium_expires_at' => $currentPeriodEnd?->toISOString(),
                    ]);

                    return response()->json([
                        'success' => true,
                        'requires_action' => false,
                        'subscription_id' => $localSub->id,
                        'subscription_status' => 'active',
                        'activated' => true,
                    ]);
                } catch (Throwable $e) {
                    DB::rollBack();
                    Log::error('Failed to activate subscription immediately', [
                        'user_id' => $user->id,
                        'subscription_id' => $localSub->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            Log::info('Payment is still processing, subscription remains pending', [
                'user_id' => $user->id,
                'subscription_id' => $localSub->id,
            ]);

            return response()->json([
                'success' => true,
                'requires_action' => false,
                'subscription_id' => $localSub->id,
                'subscription_status' => 'pending',
                'activated' => false,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Stripe createSubscription error', [
                'user_id' => $user->id ?? 'unknown',
                'plan_id' => $plan->id ?? 'unknown',
                'payment_method_id' => $request->payment_method_id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false, 'message' => 'Subscription creation failed'], 500);
        }
    }

    public function getCheckoutUrl(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $request->validate([
                'plan_id' => 'required|integer|exists:subscription_plans,id',
            ]);

            $plan = SubscriptionPlan::find($request->plan_id);
            if (!$plan || !$plan->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Plan not found or inactive',
                ], 404);
            }

            if (empty($plan->stripe_price_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe price not configured for this plan',
                ], 400);
            }

            if (!$user->stripe_customer_id) {
                $customer = Customer::create([
                    'email' => $user->email,
                    'metadata' => ['user_id' => $user->id, 'name' => $user->name],
                ]);
                $user->update(['stripe_customer_id' => $customer->id]);
            } else {
                try {
                    $customer = Customer::retrieve($user->stripe_customer_id);
                } catch (InvalidRequestException $e) {
                    Log::warning('Stripe customer not found, creating new one', [
                        'user_id' => $user->id,
                        'old_customer_id' => $user->stripe_customer_id,
                        'error' => $e->getMessage(),
                    ]);

                    $customer = Customer::create([
                        'email' => $user->email,
                        'metadata' => ['user_id' => $user->id, 'name' => $user->name],
                    ]);
                    $user->update(['stripe_customer_id' => $customer->id]);
                }
            }

            $frontendUrl = config('app.frontend_url', 'http://localhost:5000');

            $checkoutSession = Session::create([
                'customer' => $customer->id,
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price' => $plan->stripe_price_id,
                    'quantity' => 1,
                ]],
                'mode' => 'subscription',
                'locale' => 'pt-BR',
                'success_url' => $frontendUrl.'/creator/subscription?success=true&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $frontendUrl.'/creator/subscription?canceled=true',
                'metadata' => [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'duration_months' => $plan->duration_months,
                ],
            ]);

            return response()->json([
                'success' => true,
                'url' => $checkoutSession->url,
            ]);
        } catch (Exception $e) {
            Log::error('Error creating checkout URL', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error creating checkout URL. Please try again.',
            ], 500);
        }
    }

    public function createSubscriptionFromCheckout(Request $request): JsonResponse
    {
        Log::info('HHHHHHHHHHHHHHHHHHHHHHHHHHHH', [
            'user_id' => auth()->id(),
            'session_id' => $request->session_id,
        ]);

        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $request->validate([
                'session_id' => 'required|string',
            ]);

            $sessionId = $request->session_id;

            Log::info('Creating subscription from checkout session', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
            ]);

            $session = Session::retrieve($sessionId, [
                'expand' => ['subscription', 'subscription.latest_invoice.payment_intent'],
            ]);

            if (!$session->subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No subscription found in checkout session',
                ], 400);
            }

            $stripeSubscriptionId = is_object($session->subscription)
                ? $session->subscription->id
                : $session->subscription;

            $existingSub = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if ($existingSub) {
                Log::info('Subscription already exists', [
                    'subscription_id' => $existingSub->id,
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Subscription already exists',
                    'subscription_id' => $existingSub->id,
                ]);
            }

            $stripeSub = is_object($session->subscription)
                ? $session->subscription
                : StripeSubscription::retrieve($stripeSubscriptionId, [
                    'expand' => ['latest_invoice.payment_intent'],
                ]);

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
                return response()->json([
                    'success' => false,
                    'message' => 'Could not determine plan for subscription',
                ], 400);
            }

            $plan = SubscriptionPlan::find($planId);
            if (!$plan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Plan not found',
                ], 404);
            }

            $paymentSuccessful = false;
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
                'active' === $stripeSub->status
                || 'paid' === $invoiceStatus
                || 'succeeded' === $paymentIntentStatus
            );

            if (!$paymentSuccessful) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not yet confirmed. Subscription will be created when payment is processed.',
                ], 400);
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
                if (isset($stripeSub->latest_invoice->payment_intent)) {
                    if (is_object($stripeSub->latest_invoice->payment_intent)) {
                        $paymentIntentId = $stripeSub->latest_invoice->payment_intent->id ?? null;
                    } elseif (is_string($stripeSub->latest_invoice->payment_intent)) {
                        $paymentIntentId = $stripeSub->latest_invoice->payment_intent;
                    }
                }
            }

            $transactionId = $paymentIntentId ?? $invoiceId ?? 'stripe_'.$stripeSubscriptionId;

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $transactionId,
                'status' => 'paid',
                'amount' => $plan->price,
                'payment_method' => 'stripe',
                'payment_data' => [
                    'invoice' => $invoiceId,
                    'subscription' => $stripeSubscriptionId,
                    'checkout_session' => $sessionId,
                ],
                'paid_at' => now(),
            ]);

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

            $premiumExpiresAt = $currentPeriodEnd;
            if (!$premiumExpiresAt) {
                $premiumExpiresAt = Carbon::now()->addMonths($plan->duration_months);
                Log::info('Using plan duration to calculate expiration', [
                    'plan_id' => $plan->id,
                    'duration_months' => $plan->duration_months,
                    'calculated_expires_at' => $premiumExpiresAt->toISOString(),
                ]);
            }

            if (!$premiumExpiresAt instanceof Carbon) {
                $premiumExpiresAt = Carbon::parse($premiumExpiresAt);
            }

            $user->update([
                'has_premium' => true,
                'premium_expires_at' => $premiumExpiresAt->format('Y-m-d H:i:s'),
            ]);

            $user->refresh();

            DB::commit();

            Log::info('Subscription created from checkout session (no webhook)', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_duration_months' => $plan->duration_months,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'premium_expires_at' => $premiumExpiresAt->toISOString(),
                'has_premium' => $user->has_premium,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription created successfully',
                'subscription' => [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'expires_at' => $subscription->expires_at?->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create subscription from checkout', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create subscription: '.$e->getMessage(),
            ], 500);
        }
    }

    public function createSubscriptionFromCheckoutPublic(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'session_id' => 'required|string',
            ]);

            $sessionId = $request->session_id;

            Log::info('Creating subscription from checkout session (public)', [
                'session_id' => $sessionId,
                'ip' => $request->ip(),
            ]);

            $session = Session::retrieve($sessionId, [
                'expand' => ['subscription', 'subscription.latest_invoice.payment_intent'],
            ]);

            if (!$session->subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No subscription found in checkout session',
                ], 400);
            }

            $stripeSubscriptionId = is_object($session->subscription)
                ? $session->subscription->id
                : $session->subscription;

            $existingSub = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if ($existingSub) {
                Log::info('Subscription already exists (public)', [
                    'subscription_id' => $existingSub->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Subscription already exists',
                    'subscription_id' => $existingSub->id,
                ]);
            }

            $customerId = is_object($session->customer)
                ? $session->customer->id
                : $session->customer;

            if (!$customerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No customer found in checkout session',
                ], 400);
            }

            $user = User::where('stripe_customer_id', $customerId)->first();

            if (!$user) {
                Log::warning('User not found for Stripe customer (public)', [
                    'customer_id' => $customerId,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'User not found for this checkout session',
                ], 404);
            }

            $metadata = $session->metadata ?? null;
            $sessionUserId = null;

            if (is_array($metadata)) {
                $sessionUserId = $metadata['user_id'] ?? null;
            } elseif (is_object($metadata)) {
                $sessionUserId = $metadata->user_id ?? null;
            }

            if ($sessionUserId && (string) $sessionUserId !== (string) $user->id) {
                Log::warning('User ID mismatch in checkout session (public)', [
                    'session_user_id' => $sessionUserId,
                    'found_user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid checkout session',
                ], 403);
            }

            $stripeSub = is_object($session->subscription)
                ? $session->subscription
                : StripeSubscription::retrieve($stripeSubscriptionId, [
                    'expand' => ['latest_invoice.payment_intent'],
                ]);

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
                return response()->json([
                    'success' => false,
                    'message' => 'Could not determine plan for checkout session',
                ], 400);
            }

            $plan = SubscriptionPlan::find($planId);
            if (!$plan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Plan not found',
                ], 404);
            }

            $paymentSuccessful = false;
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
                'active' === $stripeSub->status
                || 'paid' === $invoiceStatus
                || 'succeeded' === $paymentIntentStatus
            );

            if (!$paymentSuccessful) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not yet confirmed. Subscription will be created when payment is processed.',
                ], 400);
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
                if (isset($stripeSub->latest_invoice->payment_intent)) {
                    if (is_object($stripeSub->latest_invoice->payment_intent)) {
                        $paymentIntentId = $stripeSub->latest_invoice->payment_intent->id ?? null;
                    } elseif (is_string($stripeSub->latest_invoice->payment_intent)) {
                        $paymentIntentId = $stripeSub->latest_invoice->payment_intent;
                    }
                }
            }

            $transactionId = $paymentIntentId ?? $invoiceId ?? 'stripe_'.$stripeSubscriptionId;

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $transactionId,
                'status' => 'paid',
                'amount' => $plan->price,
                'payment_method' => 'stripe',
                'payment_data' => [
                    'invoice' => $invoiceId,
                    'subscription' => $stripeSubscriptionId,
                    'checkout_session' => $sessionId,
                ],
                'paid_at' => now(),
            ]);

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

            $premiumExpiresAt = $currentPeriodEnd;
            if (!$premiumExpiresAt) {
                $premiumExpiresAt = Carbon::now()->addMonths($plan->duration_months);
                Log::info('Using plan duration to calculate expiration (public)', [
                    'plan_id' => $plan->id,
                    'duration_months' => $plan->duration_months,
                    'calculated_expires_at' => $premiumExpiresAt->toISOString(),
                ]);
            }

            if (!$premiumExpiresAt instanceof Carbon) {
                $premiumExpiresAt = Carbon::parse($premiumExpiresAt);
            }

            $user->update([
                'has_premium' => true,
                'premium_expires_at' => $premiumExpiresAt->format('Y-m-d H:i:s'),
            ]);

            $user->refresh();

            DB::commit();

            Log::info('Subscription created from checkout session (public endpoint)', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_duration_months' => $plan->duration_months,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'premium_expires_at' => $premiumExpiresAt->toISOString(),
                'has_premium' => $user->has_premium,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription created successfully',
                'subscription' => [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'expires_at' => $subscription->expires_at?->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create subscription from checkout (public)', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'session_id' => $request->session_id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create subscription: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getSubscriptionStatus(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['message' => 'User not authenticated'], 401);
        }

        $user->refresh();

        $pendingSub = Subscription::where('user_id', $user->id)
            ->where('status', Subscription::STATUS_PENDING)
            ->whereNotNull('stripe_subscription_id')
            ->first()
        ;

        if ($pendingSub && $pendingSub->stripe_subscription_id) {
            Log::info('Syncing pending subscription from Stripe', [
                'user_id' => $user->id,
                'subscription_id' => $pendingSub->id,
                'stripe_subscription_id' => $pendingSub->stripe_subscription_id,
            ]);

            try {
                $this->syncPendingSubscription($pendingSub);

                $pendingSub->refresh();

                $user->refresh();
            } catch (Exception $e) {
                Log::error('Failed to sync pending subscription', [
                    'user_id' => $user->id,
                    'subscription_id' => $pendingSub->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$user->hasPremiumAccess() && $user->stripe_customer_id) {
            try {
                $this->syncSubscriptionsFromStripe($user);

                $user->refresh();
            } catch (Exception $e) {
                Log::error('Failed to sync subscriptions from Stripe', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $active = $user->activeSubscription;

        $formatDate = function ($date) {
            if (!$date) {
                return null;
            }

            if (is_string($date)) {
                try {
                    return Carbon::parse($date)->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    Log::warning('Failed to parse date string', [
                        'date' => $date,
                        'error' => $e->getMessage(),
                    ]);

                    return $date;
                }
            }

            if ($date instanceof Carbon) {
                return $date->format('Y-m-d H:i:s');
            }

            if ($date instanceof DateTime) {
                return $date->format('Y-m-d H:i:s');
            }

            return null;
        };

        try {
            return response()->json([
                'has_premium' => $user->has_premium,
                'premium_expires_at' => $formatDate($user->premium_expires_at),
                'free_trial_expires_at' => $formatDate($user->free_trial_expires_at),
                'is_premium_active' => $user->hasPremiumAccess(),
                'is_on_trial' => $user->isOnTrial(),
                'student_verified' => $user->student_verified,
                'student_expires_at' => $formatDate($user->student_expires_at),
                'is_student' => $user->isStudent(),
                'subscription' => $active ? [
                    'id' => $active->id,
                    'status' => $active->status,
                    'expires_at' => $formatDate($active->expires_at),
                    'stripe_status' => $active->stripe_status ?? null,
                ] : null,
            ]);
        } catch (Exception $e) {
            Log::error('Error formatting subscription status response', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'premium_expires_at_type' => gettype($user->premium_expires_at),
                'premium_expires_at_value' => $user->premium_expires_at,
            ]);

            return response()->json([
                'has_premium' => $user->has_premium ?? false,
                'premium_expires_at' => is_string($user->premium_expires_at) ? $user->premium_expires_at : ($user->premium_expires_at?->format('Y-m-d H:i:s') ?? null),
                'free_trial_expires_at' => is_string($user->free_trial_expires_at) ? $user->free_trial_expires_at : ($user->free_trial_expires_at?->format('Y-m-d H:i:s') ?? null),
                'is_premium_active' => $user->hasPremiumAccess(),
                'is_on_trial' => $user->isOnTrial(),
                'student_verified' => $user->student_verified ?? false,
                'student_expires_at' => is_string($user->student_expires_at) ? $user->student_expires_at : ($user->student_expires_at?->format('Y-m-d H:i:s') ?? null),
                'is_student' => $user->isStudent(),
                'subscription' => $active ? [
                    'id' => $active->id,
                    'status' => $active->status,
                    'expires_at' => is_string($active->expires_at) ? $active->expires_at : ($active->expires_at?->format('Y-m-d H:i:s') ?? null),
                    'stripe_status' => $active->stripe_status ?? null,
                ] : null,
            ]);
        }
    }

    private function syncPendingSubscription(Subscription $localSub): void
    {
        if (!$localSub->stripe_subscription_id) {
            return;
        }

        try {
            Log::info('Syncing pending subscription with Stripe', [
                'subscription_id' => $localSub->id,
                'stripe_subscription_id' => $localSub->stripe_subscription_id,
            ]);

            $stripeSub = StripeSubscription::retrieve($localSub->stripe_subscription_id, [
                'expand' => ['latest_invoice.payment_intent'],
            ]);

            Log::info('Stripe subscription retrieved for sync', [
                'subscription_id' => $localSub->id,
                'stripe_subscription_id' => $stripeSub->id,
                'stripe_status' => $stripeSub->status ?? 'unknown',
            ]);

            if ('active' === $stripeSub->status) {
                DB::beginTransaction();

                try {
                    $currentPeriodEnd = isset($stripeSub->current_period_end)
                        ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                        : null;
                    $currentPeriodStart = isset($stripeSub->current_period_start)
                        ? Carbon::createFromTimestamp($stripeSub->current_period_start)
                        : null;

                    $invoiceId = null;
                    if (isset($stripeSub->latest_invoice)) {
                        if (is_object($stripeSub->latest_invoice)) {
                            $invoiceId = $stripeSub->latest_invoice->id ?? null;
                        } elseif (is_string($stripeSub->latest_invoice)) {
                            $invoiceId = $stripeSub->latest_invoice;
                        }
                    }

                    $localSub->update([
                        'status' => Subscription::STATUS_ACTIVE,
                        'starts_at' => $currentPeriodStart,
                        'expires_at' => $currentPeriodEnd,
                        'stripe_status' => $stripeSub->status,
                        'stripe_latest_invoice_id' => $invoiceId,
                    ]);

                    if ($localSub->transaction_id) {
                        $transaction = Transaction::find($localSub->transaction_id);
                        if ($transaction) {
                            $transaction->update([
                                'status' => 'paid',
                                'paid_at' => now(),
                            ]);
                        }
                    }

                    $user = $localSub->user;
                    if ($user) {
                        $user->update([
                            'has_premium' => true,
                            'premium_expires_at' => $currentPeriodEnd,
                        ]);
                    }

                    DB::commit();

                    Log::info('Pending subscription activated after sync', [
                        'subscription_id' => $localSub->id,
                        'user_id' => $user->id ?? null,
                        'premium_expires_at' => $currentPeriodEnd?->toISOString(),
                    ]);
                } catch (Exception $e) {
                    DB::rollBack();

                    throw $e;
                }
            } else {
                $localSub->update([
                    'stripe_status' => $stripeSub->status,
                ]);

                Log::info('Subscription still pending after sync', [
                    'subscription_id' => $localSub->id,
                    'stripe_status' => $stripeSub->status,
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to sync pending subscription with Stripe', [
                'subscription_id' => $localSub->id,
                'stripe_subscription_id' => $localSub->stripe_subscription_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function syncSubscriptionsFromStripe($user): void
    {
        if (!$user->stripe_customer_id) {
            Log::info('User has no Stripe customer ID, skipping sync', [
                'user_id' => $user->id,
            ]);

            return;
        }

        try {
            Log::info('Syncing subscriptions from Stripe for user', [
                'user_id' => $user->id,
                'stripe_customer_id' => $user->stripe_customer_id,
                'has_premium' => $user->has_premium,
            ]);

            $stripeSubscriptions = StripeSubscription::all([
                'customer' => $user->stripe_customer_id,
                'status' => 'active',
                'limit' => 100,
            ]);

            if (empty($stripeSubscriptions->data)) {
                Log::info('No active subscriptions found in Stripe', [
                    'user_id' => $user->id,
                    'stripe_customer_id' => $user->stripe_customer_id,
                ]);

                return;
            }

            Log::info('Found active subscriptions in Stripe', [
                'user_id' => $user->id,
                'count' => count($stripeSubscriptions->data),
            ]);

            $syncedCount = 0;
            $latestExpiration = null;

            DB::beginTransaction();

            foreach ($stripeSubscriptions->data as $stripeSub) {
                $stripeSubscriptionId = $stripeSub->id;

                $existingSub = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

                if ($existingSub) {
                    Log::info('Subscription already exists in database', [
                        'user_id' => $user->id,
                        'subscription_id' => $existingSub->id,
                        'stripe_subscription_id' => $stripeSubscriptionId,
                    ]);

                    $expiresAt = isset($stripeSub->current_period_end)
                        ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                        : null;

                    if ($expiresAt && (!$latestExpiration || $expiresAt->gt($latestExpiration))) {
                        $latestExpiration = $expiresAt;
                    }

                    continue;
                }

                $priceId = $stripeSub->items->data[0]->price->id ?? null;
                if (!$priceId) {
                    Log::warning('Could not get price ID from Stripe subscription', [
                        'user_id' => $user->id,
                        'stripe_subscription_id' => $stripeSubscriptionId,
                    ]);

                    continue;
                }

                $plan = SubscriptionPlan::where('stripe_price_id', $priceId)->first();
                if (!$plan) {
                    Log::warning('Plan not found for Stripe price', [
                        'user_id' => $user->id,
                        'stripe_subscription_id' => $stripeSubscriptionId,
                        'price_id' => $priceId,
                    ]);

                    continue;
                }

                $paymentSuccessful = false;
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
                    'active' === $stripeSub->status
                    || 'paid' === $invoiceStatus
                    || 'succeeded' === $paymentIntentStatus
                );

                if (!$paymentSuccessful) {
                    Log::info('Subscription payment not confirmed, skipping', [
                        'user_id' => $user->id,
                        'stripe_subscription_id' => $stripeSubscriptionId,
                        'status' => $stripeSub->status,
                    ]);

                    continue;
                }

                $currentPeriodEnd = isset($stripeSub->current_period_end)
                    ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                    : null;
                $currentPeriodStart = isset($stripeSub->current_period_start)
                    ? Carbon::createFromTimestamp($stripeSub->current_period_start)
                    : null;

                $premiumExpiresAt = $currentPeriodEnd;
                if (!$premiumExpiresAt) {
                    $premiumExpiresAt = Carbon::now()->addMonths($plan->duration_months);
                    Log::info('Using plan duration to calculate expiration', [
                        'plan_id' => $plan->id,
                        'duration_months' => $plan->duration_months,
                        'calculated_expires_at' => $premiumExpiresAt->toISOString(),
                    ]);
                }

                if (!$premiumExpiresAt instanceof Carbon) {
                    $premiumExpiresAt = Carbon::parse($premiumExpiresAt);
                }

                if (!$latestExpiration || $premiumExpiresAt->gt($latestExpiration)) {
                    $latestExpiration = $premiumExpiresAt;
                }

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
                    if (isset($stripeSub->latest_invoice->payment_intent)) {
                        if (is_object($stripeSub->latest_invoice->payment_intent)) {
                            $paymentIntentId = $stripeSub->latest_invoice->payment_intent->id ?? null;
                        } elseif (is_string($stripeSub->latest_invoice->payment_intent)) {
                            $paymentIntentId = $stripeSub->latest_invoice->payment_intent;
                        }
                    }
                }

                $transactionId = $paymentIntentId ?? $invoiceId ?? 'stripe_'.$stripeSubscriptionId;

                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'stripe_payment_intent_id' => $transactionId,
                    'status' => 'paid',
                    'amount' => $plan->price,
                    'payment_method' => 'stripe',
                    'payment_data' => [
                        'invoice' => $invoiceId,
                        'subscription' => $stripeSubscriptionId,
                        'synced_from_stripe' => true,
                    ],
                    'paid_at' => $currentPeriodStart ?? now(),
                ]);

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

                ++$syncedCount;

                Log::info('Subscription synced from Stripe', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'plan_id' => $plan->id,
                    'premium_expires_at' => $premiumExpiresAt->toISOString(),
                ]);
            }

            if ($syncedCount > 0 && $latestExpiration) {
                $user->update([
                    'has_premium' => true,
                    'premium_expires_at' => $latestExpiration->format('Y-m-d H:i:s'),
                ]);

                Log::info('User premium status updated after sync', [
                    'user_id' => $user->id,
                    'synced_count' => $syncedCount,
                    'premium_expires_at' => $latestExpiration->toISOString(),
                ]);
            }

            DB::commit();

            Log::info('Subscription sync completed', [
                'user_id' => $user->id,
                'synced_count' => $syncedCount,
                'total_found' => count($stripeSubscriptions->data),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to sync subscriptions from Stripe', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}

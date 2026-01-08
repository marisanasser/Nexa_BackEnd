<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Models\Payment\Subscription;
use App\Models\Payment\SubscriptionPlan;
use App\Models\Payment\Transaction;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Log;
use Stripe\Checkout\Session;

/**
 * ProcessCheckoutSessionAction handles subscription creation from a completed checkout session.
 *
 * This action:
 * - Retrieves the checkout session from Stripe
 * - Validates payment was successful
 * - Creates local subscription and transaction records
 * - Updates user premium status
 */
class ProcessCheckoutSessionAction
{
    public function __construct(
        private StripeWrapper $stripeWrapper
    ) {
    }

    /**
     * Execute the action.
     *
     * @return array{success: bool, subscription_id?: int, message?: string}
     */
    public function execute(User $user, string $sessionId): array
    {
        Log::info('Processing checkout session', [
            'user_id' => $user->id,
            'session_id' => $sessionId,
        ]);

        try {
            $session = $this->stripeWrapper->retrieveCheckoutSession($sessionId, [
                'expand' => ['subscription', 'subscription.latest_invoice.payment_intent'],
            ]);

            if (!$session->subscription) {
                return [
                    'success' => false,
                    'message' => 'No subscription found in checkout session',
                ];
            }

            $stripeSubscriptionId = is_object($session->subscription)
                ? $session->subscription->id
                : $session->subscription;

            // Check if subscription already exists
            $existingSub = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if ($existingSub) {
                return [
                    'success' => true,
                    'message' => 'Subscription already exists',
                    'subscription_id' => $existingSub->id,
                ];
            }

            // Get full subscription object
            $stripeSub = is_object($session->subscription)
                ? $session->subscription
                : $this->stripeWrapper->retrieveSubscription($stripeSubscriptionId, [
                    'expand' => ['latest_invoice.payment_intent'],
                ]);

            // Find plan
            $plan = $this->findPlanFromSession($session, $stripeSub);
            if (!$plan) {
                return [
                    'success' => false,
                    'message' => 'Could not determine plan for subscription',
                ];
            }

            // Check if payment was successful
            if (!$this->isPaymentSuccessful($stripeSub)) {
                return [
                    'success' => false,
                    'message' => 'Payment not yet confirmed. Subscription will be created when payment is processed.',
                ];
            }

            // Create subscription
            $subscription = $this->createSubscription($user, $plan, $stripeSub, $sessionId);

            return [
                'success' => true,
                'message' => 'Subscription created successfully',
                'subscription_id' => $subscription->id,
            ];
        } catch (Exception $e) {
            Log::error('Failed to process checkout session', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to process checkout: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Find plan from session metadata or stripe subscription.
     */
    private function findPlanFromSession(Session $session, \Stripe\Subscription $stripeSub): ?SubscriptionPlan
    {
        $planId = null;

        // Try metadata first
        if (isset($session->metadata)) {
            if (is_array($session->metadata) && isset($session->metadata['plan_id'])) {
                $planId = (int) $session->metadata['plan_id'];
            } elseif (is_object($session->metadata) && isset($session->metadata->plan_id)) {
                $planId = (int) $session->metadata->plan_id;
            }
        }

        // Try to find from price ID
        if (!$planId) {
            $priceId = $stripeSub->items->data[0]->price->id ?? null;
            if ($priceId) {
                $plan = SubscriptionPlan::where('stripe_price_id', $priceId)->first();
                if ($plan) {
                    return $plan;
                }
            }
        }

        return $planId ? SubscriptionPlan::find($planId) : null;
    }

    /**
     * Check if payment was successful.
     */
    private function isPaymentSuccessful(\Stripe\Subscription $stripeSub): bool
    {
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

        return
            'active' === $stripeSub->status
            || 'paid' === $invoiceStatus
            || 'succeeded' === $paymentIntentStatus;
    }

    /**
     * Create local subscription and transaction records.
     */
    private function createSubscription(
        User $user,
        SubscriptionPlan $plan,
        \Stripe\Subscription $stripeSub,
        string $sessionId
    ): Subscription {
        DB::beginTransaction();

        try {
            $currentPeriodEnd = isset($stripeSub->current_period_end)
                ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                : null;
            $currentPeriodStart = isset($stripeSub->current_period_start)
                ? Carbon::createFromTimestamp($stripeSub->current_period_start)
                : null;

            $invoiceInfo = $this->extractInvoiceInfo($stripeSub);

            $transactionId = $invoiceInfo['payment_intent_id']
                ?? $invoiceInfo['invoice_id']
                ?? 'stripe_' . $stripeSub->id;

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $transactionId,
                'status' => 'paid',
                'amount' => $plan->price,
                'payment_method' => 'stripe',
                'payment_data' => [
                    'invoice' => $invoiceInfo['invoice_id'],
                    'subscription' => $stripeSub->id,
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
                'stripe_subscription_id' => $stripeSub->id,
                'stripe_latest_invoice_id' => $invoiceInfo['invoice_id'],
                'stripe_status' => $stripeSub->status ?? 'active',
                'starts_at' => $currentPeriodStart,
                'expires_at' => $currentPeriodEnd,
            ]);

            // Calculate premium expiration
            $premiumExpiresAt = $currentPeriodEnd ?? Carbon::now()->addMonths($plan->duration_months);

            $user->update([
                'has_premium' => true,
                'premium_expires_at' => $premiumExpiresAt,
            ]);

            DB::commit();

            Log::info('Subscription created from checkout session', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'plan_id' => $plan->id,
            ]);

            return $subscription;
        } catch (Exception $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Extract invoice and payment intent info from subscription.
     */
    private function extractInvoiceInfo(\Stripe\Subscription $stripeSub): array
    {
        $invoiceId = null;
        $paymentIntentId = null;

        if ($stripeSub->latest_invoice) {
            if (is_object($stripeSub->latest_invoice)) {
                $invoiceId = $stripeSub->latest_invoice->id ?? null;

                if (isset($stripeSub->latest_invoice->payment_intent)) {
                    if (is_object($stripeSub->latest_invoice->payment_intent)) {
                        $paymentIntentId = $stripeSub->latest_invoice->payment_intent->id ?? null;
                    } elseif (is_string($stripeSub->latest_invoice->payment_intent)) {
                        $paymentIntentId = $stripeSub->latest_invoice->payment_intent;
                    }
                }
            } elseif (is_string($stripeSub->latest_invoice)) {
                $invoiceId = $stripeSub->latest_invoice;
            }
        }

        return [
            'invoice_id' => $invoiceId,
            'payment_intent_id' => $paymentIntentId,
        ];
    }
}

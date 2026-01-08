<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

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
use Throwable;

/**
 * SubscriptionService handles subscription-related operations.
 *
 * Responsibilities:
 * - Creating subscriptions
 * - Syncing subscriptions from Stripe
 * - Managing subscription status
 * - Handling subscription payments
 */
class SubscriptionService
{
    public function __construct(
        private StripeWrapper $stripeWrapper,
        private StripeCustomerService $customerService
    ) {
    }

    /**
     * Create a checkout session for a subscription.
     */
    public function createSubscriptionCheckoutSession(
        User $user,
        SubscriptionPlan $plan,
        string $successUrl,
        string $cancelUrl,
        ?string $couponCode = null
    ): Session {
        $customerId = $this->customerService->ensureStripeCustomer($user);

        $params = [
            'customer' => $customerId,
            'mode' => 'subscription',
            'line_items' => [
                [
                    'price' => $plan->stripe_price_id,
                    'quantity' => 1,
                ]
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'type' => 'subscription',
            ],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'plan_id' => (string) $plan->id,
                ],
            ],
        ];

        if ($couponCode) {
            $params['discounts'] = [['coupon' => $couponCode]];
        }

        return $this->stripeWrapper->createCheckoutSession($params);
    }

    /**
     * Handle successful subscription checkout.
     */
    public function handleSubscriptionCheckoutCompleted(Session $session): void
    {
        $userId = $session->metadata->user_id ?? null;
        $planId = $session->metadata->plan_id ?? null;
        $stripeSubscriptionId = $session->subscription;

        if (!$userId || !$stripeSubscriptionId) {
            Log::error('Missing data in subscription checkout session', [
                'session_id' => $session->id,
            ]);

            return;
        }

        $user = User::find($userId);
        $plan = $planId ? SubscriptionPlan::find($planId) : null;

        if (!$user) {
            Log::error('User not found for subscription checkout', ['user_id' => $userId]);

            return;
        }

        $this->syncSubscription($stripeSubscriptionId, $user, $plan);
    }

    /**
     * Sync a subscription from Stripe.
     */
    public function syncSubscription(
        string $stripeSubscriptionId,
        ?User $user = null,
        ?SubscriptionPlan $plan = null
    ): ?Subscription {
        try {
            $stripeSubscription = $this->stripeWrapper->retrieveSubscription($stripeSubscriptionId);

            if (!$user) {
                $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
                $user = $subscription?->user;
            }

            if (!$user) {
                Log::error('Cannot sync subscription: user not found', [
                    'stripe_subscription_id' => $stripeSubscriptionId,
                ]);

                return null;
            }

            return $this->updateOrCreateSubscription($stripeSubscription, $user, $plan);
        } catch (Exception $e) {
            Log::error('Failed to sync subscription', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cancel a subscription.
     */
    public function cancelSubscription(Subscription $subscription, bool $immediately = false): void
    {
        try {
            if ($immediately) {
                $this->stripeWrapper->cancelSubscription($subscription->stripe_subscription_id);
                $subscription->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
            } else {
                $this->stripeWrapper->updateSubscription($subscription->stripe_subscription_id, [
                    'cancel_at_period_end' => true,
                ]);
                $subscription->update(['cancel_at_period_end' => true]);
            }

            Log::info('Cancelled subscription', [
                'subscription_id' => $subscription->id,
                'immediately' => $immediately,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to cancel subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Reactivate a cancelled subscription.
     */
    public function reactivateSubscription(Subscription $subscription): void
    {
        if (!$subscription->cancel_at_period_end) {
            throw new Exception('Subscription is not scheduled for cancellation');
        }

        try {
            $this->stripeWrapper->updateSubscription($subscription->stripe_subscription_id, [
                'cancel_at_period_end' => false,
            ]);

            $subscription->update(['cancel_at_period_end' => false]);

            Log::info('Reactivated subscription', ['subscription_id' => $subscription->id]);
        } catch (Exception $e) {
            Log::error('Failed to reactivate subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get user's active subscription.
     */
    public function getActiveSubscription(User $user): ?Subscription
    {
        return Subscription::where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->first()
        ;
    }

    /**
     * Record a subscription transaction.
     */
    public function recordTransaction(
        Subscription $subscription,
        float $amount,
        string $stripeInvoiceId,
        string $status = 'completed'
    ): Transaction {
        return Transaction::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'type' => 'subscription',
            'amount' => $amount,
            'currency' => 'BRL',
            'status' => $status,
            'stripe_invoice_id' => $stripeInvoiceId,
            'processed_at' => now(),
        ]);
    }

    /**
     * Create subscription from invoice paid webhook.
     *
     * @param mixed $invoice
     */
    public function createSubscriptionFromInvoice($invoice): void
    {
        try {
            $stripeSubscriptionId = $invoice->subscription ?? null;
            if (!$stripeSubscriptionId) {
                return;
            }

            $stripeSub = $this->stripeWrapper->retrieveSubscription($stripeSubscriptionId, [
                'expand' => ['latest_invoice.payment_intent'],
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

            $existingSub = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if ($existingSub) {
                Log::info('Subscription already exists, syncing instead', [
                    'subscription_id' => $existingSub->id,
                ]);
                $this->syncSubscription($stripeSubscriptionId, $user, $plan);

                return;
            }

            DB::beginTransaction();

            try {
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
                    // Missing fields handled by DB defaults or nullable
                ]);

                // Handle fixed duration plans (e.g. 1 year) that don't auto-renew indefinitely logic
                // Assuming plan->duration_months exists and logic is desired
                if (isset($plan->duration_months) && $plan->duration_months > 1) {
                    $cancelAt = Carbon::now()->addMonths($plan->duration_months)->timestamp;

                    try {
                        $this->stripeWrapper->updateSubscription($stripeSubscriptionId, [
                            'cancel_at' => $cancelAt,
                        ]);
                        Log::info('Set cancel_at for subscription from invoice', [
                            'subscription_id' => $stripeSubscriptionId,
                            'cancel_at' => $cancelAt,
                        ]);
                    } catch (Exception $e) {
                        Log::error('Failed to set cancel_at for subscription from invoice', [
                            'subscription_id' => $stripeSubscriptionId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'plan_id' => $plan->id, // column name usually plan_id based on syncSubscription
                    'status' => 'active',
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
                ]);
            } catch (Throwable $e) {
                DB::rollBack();

                throw $e;
            }
        } catch (Throwable $e) {
            Log::error('Failed to create subscription from invoice', [
                'invoice_id' => $invoice->id ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            // Don't throw to avoid loop in webhook
        }
    }

    /**
     * Mark subscription payment as failed.
     */
    public function markSubscriptionPaymentFailed(string $stripeSubscriptionId, ?string $latestInvoiceId = null): void
    {
        try {
            Log::info('Marking subscription payment as failed', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
            ]);

            $localSub = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if (!$localSub) {
                Log::warning('Local subscription not found when marking payment failed', [
                    'stripe_subscription_id' => $stripeSubscriptionId,
                ]);

                return;
            }

            $localSub->update([
                'status' => 'pending', // or past_due
                'stripe_status' => 'past_due',
                'stripe_latest_invoice_id' => $latestInvoiceId,
            ]);

            Log::info('Subscription payment marked as failed', [
                'local_subscription_id' => $localSub->id,
                'user_id' => $localSub->user_id,
                'new_status' => $localSub->status,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to mark subscription payment failed', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update or create a local subscription from Stripe data.
     */
    private function updateOrCreateSubscription(
        \Stripe\Subscription $stripeSubscription,
        User $user,
        ?SubscriptionPlan $plan = null
    ): Subscription {
        $status = $this->mapStripeStatus($stripeSubscription->status);

        $subscription = Subscription::updateOrCreate(
            ['stripe_subscription_id' => $stripeSubscription->id],
            [
                'user_id' => $user->id,
                'plan_id' => $plan?->id,
                'status' => $status,
                'stripe_price_id' => $stripeSubscription->items->data[0]->price->id ?? null,
                'current_period_start' => Carbon::createFromTimestamp($stripeSubscription->current_period_start),
                'current_period_end' => Carbon::createFromTimestamp($stripeSubscription->current_period_end),
                'cancel_at_period_end' => $stripeSubscription->cancel_at_period_end,
            ]
        );

        // Update user premium status based on subscription
        $this->updateUserPremiumStatus($user, $status, $stripeSubscription);

        Log::info('Synced subscription', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'status' => $status,
        ]);

        return $subscription;
    }

    /**
     * Map Stripe subscription status to local status.
     */
    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active' => 'active',
            'past_due' => 'past_due',
            'unpaid' => 'unpaid',
            'canceled' => 'cancelled',
            'incomplete' => 'incomplete',
            'incomplete_expired' => 'expired',
            'trialing' => 'trialing',
            default => 'inactive',
        };
    }

    /**
     * Update user premium status based on subscription.
     */
    private function updateUserPremiumStatus(
        User $user,
        string $status,
        \Stripe\Subscription $stripeSubscription
    ): void {
        $isPremium = in_array($status, ['active', 'trialing']);

        $user->update([
            'has_premium' => $isPremium,
            'premium_expires_at' => $isPremium
                ? Carbon::createFromTimestamp($stripeSubscription->current_period_end)
                : null,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Services\StripeCustomerService;
use App\Models\Payment\Subscription;
use App\Models\Payment\SubscriptionPlan;
use App\Models\Payment\Transaction;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Log;

/**
 * CreateSubscriptionAction handles the complex process of creating a subscription.
 *
 * This action encapsulates all the business logic needed to:
 * - Ensure the user has a Stripe customer
 * - Attach payment method to customer
 * - Create Stripe subscription
 * - Create local subscription and transaction records
 * - Handle 3D Secure authentication if required
 */
class CreateSubscriptionAction
{
    public function __construct(
        private StripeWrapper $stripeWrapper,
        private StripeCustomerService $customerService
    ) {}

    /**
     * Execute the subscription creation.
     *
     * @return array{success: bool, requires_action?: bool, client_secret?: string, subscription_id?: int, message?: string}
     */
    public function execute(User $user, SubscriptionPlan $plan, string $paymentMethodId): array
    {
        Log::info('Stripe subscription creation started', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'stripe_price_id' => $plan->stripe_price_id,
            'payment_method_id' => $paymentMethodId,
        ]);

        DB::beginTransaction();

        try {
            // Ensure customer exists
            $customerId = $this->customerService->ensureStripeCustomer($user);

            // Attach and set default payment method
            $this->attachPaymentMethod($user, $customerId, $paymentMethodId);

            // Create Stripe subscription
            $stripeSub = $this->createStripeSubscription($customerId, $plan);

            // Extract invoice and payment intent info
            $invoiceInfo = $this->extractInvoiceInfo($stripeSub);

            // Create local records
            $localRecords = $this->createLocalRecords($user, $plan, $stripeSub, $invoiceInfo);

            DB::commit();

            // Refresh subscription to get latest status
            $stripeSub = $this->stripeWrapper->retrieveSubscription($stripeSub->id, [
                'expand' => ['latest_invoice.payment_intent'],
            ]);

            // Check if 3D Secure is required
            return $this->checkPaymentStatus($stripeSub, $localRecords, $user, $plan);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Stripe createSubscription error', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Subscription creation failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Attach payment method to customer and set as default.
     */
    private function attachPaymentMethod(User $user, string $customerId, string $paymentMethodId): void
    {
        $pm = $this->stripeWrapper->attachPaymentMethodToCustomer($paymentMethodId, $customerId);

        $this->stripeWrapper->updateCustomer($customerId, [
            'invoice_settings' => ['default_payment_method' => $pm->id],
        ]);

        if ($user->stripe_payment_method_id !== $pm->id) {
            $user->update(['stripe_payment_method_id' => $pm->id]);
        }

        Log::info('Payment method attached', [
            'user_id' => $user->id,
            'payment_method_id' => $pm->id,
        ]);
    }

    /**
     * Create the Stripe subscription.
     */
    private function createStripeSubscription(string $customerId, SubscriptionPlan $plan): \Stripe\Subscription
    {
        $cancelAt = null;
        if ($plan->duration_months > 1) {
            $cancelAt = Carbon::now()->addMonths($plan->duration_months)->timestamp;
        }

        $params = [
            'customer' => $customerId,
            'items' => [['price' => $plan->stripe_price_id]],
            'payment_behavior' => 'default_incomplete',
            'expand' => ['latest_invoice.payment_intent'],
        ];

        if ($cancelAt) {
            $params['cancel_at'] = $cancelAt;
        }

        return \Stripe\Subscription::create($params);
    }

    /**
     * Extract invoice and payment intent information from subscription.
     */
    private function extractInvoiceInfo(\Stripe\Subscription $stripeSub): array
    {
        $invoiceId = null;
        $paymentIntentId = null;

        if (isset($stripeSub->latest_invoice)) {
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

    /**
     * Create local subscription and transaction records.
     */
    private function createLocalRecords(
        User $user,
        SubscriptionPlan $plan,
        \Stripe\Subscription $stripeSub,
        array $invoiceInfo
    ): array {
        $transaction = Transaction::create([
            'user_id' => $user->id,
            'stripe_payment_intent_id' => $invoiceInfo['payment_intent_id'],
            'status' => 'pending',
            'amount' => $plan->price,
            'payment_method' => 'stripe',
            'payment_data' => ['invoice' => $invoiceInfo['invoice_id']],
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
            'amount_paid' => $plan->price,
            'payment_method' => 'stripe',
            'transaction_id' => $transaction->id,
            'auto_renew' => true,
            'stripe_subscription_id' => $stripeSub->id,
            'stripe_latest_invoice_id' => $invoiceInfo['invoice_id'],
            'stripe_status' => $stripeSub->status ?? 'incomplete',
        ]);

        return [
            'subscription' => $subscription,
            'transaction' => $transaction,
        ];
    }

    /**
     * Check payment status and activate if successful.
     */
    private function checkPaymentStatus(
        \Stripe\Subscription $stripeSub,
        array $localRecords,
        User $user,
        SubscriptionPlan $plan
    ): array {
        $subscription = $localRecords['subscription'];
        $transaction = $localRecords['transaction'];

        // Extract payment info
        $invoice = null;
        $paymentIntent = null;

        if (isset($stripeSub->latest_invoice) && is_object($stripeSub->latest_invoice)) {
            $invoice = $stripeSub->latest_invoice;
            if (isset($invoice->payment_intent) && is_object($invoice->payment_intent)) {
                $paymentIntent = $invoice->payment_intent;
            }
        }

        // Check if 3D Secure is required
        if ($paymentIntent && isset($paymentIntent->status) && 'requires_action' === $paymentIntent->status) {
            return [
                'success' => true,
                'requires_action' => true,
                'client_secret' => $paymentIntent->client_secret ?? null,
                'subscription_id' => $subscription->id,
            ];
        }

        // Check if payment succeeded
        $paymentSucceeded = $this->isPaymentSuccessful($stripeSub, $invoice, $paymentIntent);

        if ($paymentSucceeded) {
            $this->activateSubscription($subscription, $transaction, $user, $stripeSub);

            return [
                'success' => true,
                'requires_action' => false,
                'subscription_id' => $subscription->id,
                'subscription_status' => 'active',
                'activated' => true,
            ];
        }

        return [
            'success' => true,
            'requires_action' => false,
            'subscription_id' => $subscription->id,
            'subscription_status' => 'pending',
            'activated' => false,
        ];
    }

    /**
     * Check if payment was successful.
     */
    private function isPaymentSuccessful(
        \Stripe\Subscription $stripeSub,
        ?object $invoice,
        ?object $paymentIntent
    ): bool {
        if ($invoice && isset($invoice->status) && 'paid' === $invoice->status) {
            return true;
        }

        if ($paymentIntent && isset($paymentIntent->status) && 'succeeded' === $paymentIntent->status) {
            return true;
        }

        if (isset($stripeSub->status) && 'active' === $stripeSub->status) {
            return true;
        }

        return false;
    }

    /**
     * Activate the subscription and update user premium status.
     */
    private function activateSubscription(
        Subscription $subscription,
        Transaction $transaction,
        User $user,
        \Stripe\Subscription $stripeSub
    ): void {
        DB::beginTransaction();

        try {
            $currentPeriodEnd = isset($stripeSub->current_period_end)
                ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                : null;
            $currentPeriodStart = isset($stripeSub->current_period_start)
                ? Carbon::createFromTimestamp($stripeSub->current_period_start)
                : null;

            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => $currentPeriodStart,
                'expires_at' => $currentPeriodEnd,
                'stripe_status' => $stripeSub->status ?? 'active',
            ]);

            $transaction->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $user->update([
                'has_premium' => true,
                'premium_expires_at' => $currentPeriodEnd,
            ]);

            DB::commit();

            Log::info('Subscription activated', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to activate subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

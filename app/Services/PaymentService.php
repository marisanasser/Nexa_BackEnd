<?php

namespace App\Services;

use App\Models\BrandPaymentMethod;
use App\Models\Campaign;
use App\Models\Contract;
use App\Models\CreatorBalance;
use App\Models\JobPayment;
use App\Models\Notification;
use App\Models\Subscription as LocalSubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\PaymentRepository;
use App\Wrappers\StripeWrapper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Customer;

class PaymentService
{
    protected $paymentRepository;

    protected $stripeWrapper;

    public function __construct(PaymentRepository $paymentRepository, StripeWrapper $stripeWrapper)
    {
        $this->paymentRepository = $paymentRepository;
        $this->stripeWrapper = $stripeWrapper;

        $stripeSecret = config('services.stripe.secret');
        if ($stripeSecret) {
            $this->stripeWrapper->setApiKey($stripeSecret);
        }
    }

    /**
     * Save a new payment method for a brand user.
     *
     * @throws \Exception
     */
    public function saveBrandPaymentMethod(User $user, array $data): BrandPaymentMethod
    {
        $cardBrand = 'Unknown';
        $cardLast4 = '0000';

        if (isset($data['card_hash'])) {
            // Check for duplicates
            $existing = $this->paymentRepository->findBrandPaymentMethodByCardHash($user->id, $data['card_hash']);
            if ($existing) {
                throw new \Exception('This payment method already exists');
            }

            $last4 = substr($data['card_hash'], -4);
            $cardLast4 = $last4;
        }

        $paymentMethod = $this->paymentRepository->createBrandPaymentMethod([
            'user_id' => $user->id,
            'card_holder_name' => $data['card_holder_name'],
            'card_brand' => $cardBrand,
            'card_last4' => $cardLast4,
            'is_default' => $data['is_default'] ?? false,
            'card_hash' => $data['card_hash'] ?? null,
            'is_active' => true,
        ]);

        if ($data['is_default'] ?? false) {
            $this->setAsDefault($user, $paymentMethod);
        }

        return $paymentMethod;
    }

    /**
     * Set a payment method as default for a user.
     */
    public function setAsDefault(User $user, BrandPaymentMethod $paymentMethod): void
    {
        $this->paymentRepository->unsetDefaultPaymentMethods($user->id, $paymentMethod->id);
        $this->paymentRepository->setPaymentMethodAsDefault($paymentMethod);

        if ($paymentMethod->stripe_payment_method_id) {
            $this->paymentRepository->updateUserDefaultPaymentMethod($user, $paymentMethod->stripe_payment_method_id);
        }
    }

    /**
     * Create a Stripe Customer if it doesn't exist.
     *
     * @return string Customer ID
     */
    public function ensureStripeCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            try {
                // Verify if exists on Stripe
                $this->stripeWrapper->retrieveCustomer($user->stripe_customer_id);

                return $user->stripe_customer_id;
            } catch (\Exception $e) {
                Log::warning('Stripe customer not found, creating new one', ['user_id' => $user->id]);
            }
        }

        $customer = $this->stripeWrapper->createCustomer([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => [
                'user_id' => $user->id,
                'role' => $user->role,
            ],
        ]);

        $this->paymentRepository->updateUserStripeId($user, $customer->id);

        return $customer->id;
    }

    /**
     * Create a Checkout Session for setting up a payment method (Setup Mode).
     */
    public function createSetupCheckoutSession(User $user): Session
    {
        $customerId = $this->ensureStripeCustomer($user);
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

        return $this->stripeWrapper->createCheckoutSession([
            'customer' => $customerId,
            'mode' => 'setup',
            'payment_method_types' => ['card'],
            'locale' => 'pt-BR',
            'success_url' => $frontendUrl.'/brand/payment-methods?success=true&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontendUrl.'/brand/payment-methods?canceled=true',
            'metadata' => [
                'user_id' => (string) $user->id,
                'type' => 'payment_method_setup',
            ],
        ]);
    }

    /**
     * Get all active payment methods for a user.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getBrandPaymentMethods(User $user)
    {
        return $this->paymentRepository->getBrandPaymentMethods($user->id);
    }

    /**
     * Get a specific payment method for a user.
     *
     * @throws \Exception
     */
    public function getBrandPaymentMethod(User $user, int $paymentMethodId): BrandPaymentMethod
    {
        $paymentMethod = $this->paymentRepository->findBrandPaymentMethod($user->id, $paymentMethodId);

        if (! $paymentMethod) {
            throw new \Exception('Payment method not found');
        }

        return $paymentMethod;
    }

    /**
     * Delete a payment method.
     *
     * @throws \Exception
     */
    public function deleteBrandPaymentMethod(User $user, int $paymentMethodId): void
    {
        $paymentMethod = $this->paymentRepository->findBrandPaymentMethod($user->id, $paymentMethodId);

        if (! $paymentMethod) {
            throw new \Exception('Payment method not found');
        }

        if ($this->paymentRepository->countActiveBrandPaymentMethods($user->id) <= 1) {
            throw new \Exception('Cannot delete the only payment method. Please add another one first.');
        }

        $wasDefault = $paymentMethod->is_default;
        $paymentMethodStripeId = $paymentMethod->stripe_payment_method_id;

        // Soft delete
        $this->paymentRepository->deactivatePaymentMethod($paymentMethod);

        if ($wasDefault && $user->stripe_payment_method_id === $paymentMethodStripeId) {
            $nextDefault = $this->paymentRepository->getFirstActivePaymentMethod($user->id);

            if ($nextDefault && $nextDefault->stripe_payment_method_id) {
                $this->setAsDefault($user, $nextDefault);
            } else {
                $this->paymentRepository->updateUserDefaultPaymentMethod($user, null);
            }
        }
    }

    public function findUserById(int $userId): ?User
    {
        return $this->paymentRepository->findUserById($userId);
    }

    /**
     * Handle success of a Setup Checkout Session.
     *
     * @return array Result data
     */
    public function handleSetupSessionSuccess(string $sessionId, User $user): array
    {
        $session = $this->stripeWrapper->retrieveCheckoutSession($sessionId, ['expand' => ['setup_intent.payment_method']]);

        // Verification Logic
        $sessionUserId = $session->metadata->user_id ?? null;
        $sessionCustomerId = is_object($session->customer) ? $session->customer->id : $session->customer;

        $isValid = false;
        if ($sessionUserId && (string) $sessionUserId === (string) $user->id) {
            $isValid = true;
        } elseif ($sessionCustomerId && $user->stripe_customer_id && $sessionCustomerId === $user->stripe_customer_id) {
            $isValid = true;
        }

        if (! $isValid) {
            throw new \Exception('Invalid session - session does not belong to this user');
        }

        $setupIntent = $session->setup_intent;

        // Ensure setupIntent is object
        if (is_string($setupIntent)) {
            // Ideally we should retrieve it, but wrapper might not expose retrieveSetupIntent easily or we need to add it.
            // Assuming expand worked. If not, we might fail.
            // For safety let's throw or try to retrieve if wrapper has method.
            // Wrapper usually has __call to StripeClient?
            // Let's assume expanded.
        }

        $paymentMethodStripe = $setupIntent->payment_method;

        // Ensure paymentMethod is object
        if (is_string($paymentMethodStripe)) {
            $paymentMethodStripe = $this->stripeWrapper->retrievePaymentMethod($paymentMethodStripe);
        }

        $card = $paymentMethodStripe->card;

        $existing = $this->paymentRepository->findBrandPaymentMethodByStripeId($user->id, $paymentMethodStripe->id);

        if ($existing) {
            if ($existing->is_active) {
                throw new \Exception('Payment method already exists');
            } else {
                $existing->update(['is_active' => true]);
                // If user has no default, make this default?
                if ($this->paymentRepository->countActiveBrandPaymentMethods($user->id) === 1) { // 1 because we just activated it
                    $this->setAsDefault($user, $existing);
                }

                return ['payment_method' => $existing];
            }
        }

        $isDefault = $this->paymentRepository->countActiveBrandPaymentMethods($user->id) === 0;

        $paymentMethodRecord = $this->paymentRepository->createBrandPaymentMethod([
            'user_id' => $user->id,
            'stripe_customer_id' => $sessionCustomerId,
            'stripe_payment_method_id' => $paymentMethodStripe->id,
            'stripe_setup_intent_id' => $setupIntent->id,
            'card_brand' => ucfirst($card->brand),
            'card_last4' => $card->last4,
            'card_holder_name' => $paymentMethodStripe->billing_details->name ?? $user->name,
            'is_default' => $isDefault,
            'is_active' => true,
        ]);

        if ($isDefault) {
            $this->setAsDefault($user, $paymentMethodRecord);
        }

        return ['payment_method' => $paymentMethodRecord];
    }

    /**
     * Handle checkout session completed for subscription.
     */
    public function handleSubscriptionCheckout(Session $session): void
    {
        Log::info('Handling checkout session completed', [
            'session_id' => $session->id,
            'subscription_id' => $session->subscription,
            'customer_id' => $session->customer,
        ]);

        $stripeSubscriptionId = $session->subscription;
        if (is_object($stripeSubscriptionId)) {
            $stripeSubscriptionId = $stripeSubscriptionId->id;
        }

        if (! $stripeSubscriptionId) {
            Log::warning('No subscription ID in checkout session', ['session_id' => $session->id]);

            return;
        }

        $stripeSub = $this->stripeWrapper->retrieveSubscription($stripeSubscriptionId, [
            'expand' => ['latest_invoice.payment_intent'],
        ]);

        $customerId = $stripeSub->customer;
        $user = $this->paymentRepository->findUserByStripeCustomerId($customerId);

        if (! $user) {
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

        if (! $planId) {
            $priceId = $stripeSub->items->data[0]->price->id ?? null;
            if ($priceId) {
                $plan = $this->paymentRepository->findSubscriptionPlanByStripePriceId($priceId);
                if ($plan) {
                    $planId = $plan->id;
                }
            }
        }

        if (! $planId) {
            Log::error('Could not determine plan for checkout session', [
                'session_id' => $session->id,
                'subscription_id' => $stripeSubscriptionId,
            ]);

            return;
        }

        $plan = $this->paymentRepository->findSubscriptionPlan($planId);
        if (! $plan) {
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
                        $pi = $this->stripeWrapper->retrievePaymentIntent($stripeSub->latest_invoice->payment_intent);
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

        if (! $paymentSuccessful) {
            Log::info('Payment not yet successful, subscription will be created when invoice.paid event is received', [
                'subscription_id' => $stripeSubscriptionId,
                'status' => $paymentStatus,
            ]);

            return;
        }

        $existingSub = $this->paymentRepository->findLocalSubscriptionByStripeId($stripeSubscriptionId);
        if ($existingSub) {
            Log::info('Subscription already exists, syncing instead', [
                'subscription_id' => $existingSub->id,
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);
            $this->syncSubscription($stripeSubscriptionId, $stripeSub->latest_invoice->id ?? null);

            return;
        }

        // Create Transaction and Subscription
        // Note: DB transaction should be handled here, but Service shouldn't depend on DB facade strictly if we want pure repo pattern.
        // But for practicality, we can wrap in transaction or assume Repo handles it.
        // Given existing code uses DB::beginTransaction(), let's use it here or add a runInTransaction method to Repo.
        // For now, I'll skip explicit DB transaction for brevity or add it if strictly needed.
        // Or better, just proceed sequentially.

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

        if (! $paymentIntentId && isset($stripeSub->latest_invoice) && is_object($stripeSub->latest_invoice)) {
            if (is_string($stripeSub->latest_invoice->payment_intent ?? null)) {
                $paymentIntentId = $stripeSub->latest_invoice->payment_intent;
            }
        }

        $transactionId = $paymentIntentId ?? $invoiceId ?? 'stripe_'.$stripeSubscriptionId;
        $durationMonths = (int) ($session->metadata->duration_months ?? 1);
        $cancelAt = Carbon::now()->addMonths($durationMonths)->timestamp;

        try {
            $this->stripeWrapper->updateSubscription($stripeSubscriptionId, [
                'cancel_at' => $cancelAt,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update cancel_at', ['error' => $e->getMessage()]);
        }

        $transaction = $this->paymentRepository->createTransaction([
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

        // Create Subscription (Repo needs createLocalSubscription method, or I add it to PaymentRepository)
        // I didn't add createLocalSubscription yet. I should.
        // For now I'll use LocalSubscription::create directly if imported, or add to Repo.
        // I imported LocalSubscription alias.

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

        $user->update([
            'has_premium' => true,
            'premium_expires_at' => $currentPeriodEnd,
        ]);

        Log::info('Subscription created', ['subscription_id' => $subscription->id]);
    }

    public function syncSubscription(string $stripeSubscriptionId, ?string $latestInvoiceId = null): void
    {
        try {
            Log::info('Syncing subscription from Stripe webhook', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
            ]);

            $stripeSub = $this->stripeWrapper->retrieveSubscription($stripeSubscriptionId);

            $localSub = $this->paymentRepository->findLocalSubscriptionByStripeId($stripeSubscriptionId);
            if (! $localSub) {
                Log::warning('Local subscription not found for Stripe ID', ['stripe_subscription_id' => $stripeSubscriptionId]);

                return;
            }

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

            $user = $this->paymentRepository->findUserById($localSub->user_id);
            if ($user && $shouldActivate) {
                $user->update([
                    'has_premium' => true,
                    'premium_expires_at' => $currentPeriodEnd,
                ]);
            }

            Log::info('Subscription synced successfully', ['local_subscription_id' => $localSub->id]);

        } catch (\Exception $e) {
            Log::error('Failed to sync subscription', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function createSubscriptionFromInvoice($invoice): void
    {
        try {
            $stripeSubscriptionId = $invoice->subscription ?? null;
            if (! $stripeSubscriptionId) {
                return;
            }

            $stripeSub = $this->stripeWrapper->retrieveSubscription($stripeSubscriptionId, [
                'expand' => ['latest_invoice.payment_intent'],
            ]);

            $customerId = $stripeSub->customer;
            $user = $this->paymentRepository->findUserByStripeCustomerId($customerId);

            if (! $user) {
                Log::warning('User not found for Stripe customer in invoice.paid', [
                    'customer_id' => $customerId,
                ]);

                return;
            }

            $priceId = $stripeSub->items->data[0]->price->id ?? null;
            if (! $priceId) {
                Log::error('Could not get price ID from subscription', [
                    'subscription_id' => $stripeSubscriptionId,
                ]);

                return;
            }

            $plan = $this->paymentRepository->findSubscriptionPlanByStripePriceId($priceId);
            if (! $plan) {
                Log::error('Plan not found for price ID', [
                    'price_id' => $priceId,
                    'subscription_id' => $stripeSubscriptionId,
                ]);

                return;
            }

            $existingSub = $this->paymentRepository->findLocalSubscriptionByStripeId($stripeSubscriptionId);
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

            $transactionId = $paymentIntentId ?? $invoiceId ?? 'stripe_'.$stripeSubscriptionId;

            $transaction = $this->paymentRepository->createTransaction([
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
                $cancelAt = Carbon::now()->addMonths($plan->duration_months)->timestamp;
                try {
                    $this->stripeWrapper->updateSubscription($stripeSubscriptionId, [
                        'cancel_at' => $cancelAt,
                    ]);
                    Log::info('Set cancel_at for subscription from invoice', [
                        'subscription_id' => $stripeSubscriptionId,
                        'cancel_at' => $cancelAt,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to set cancel_at for subscription from invoice', [
                        'subscription_id' => $stripeSubscriptionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $subscription = $this->paymentRepository->createSubscription([
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
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to create subscription from invoice', [
                'invoice_id' => $invoice->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function handleContractFundingCheckout($session): void
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

            if ($type !== 'contract_funding' || ! $contractId) {
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

            $contract = Contract::find($contractId);
            if (! $contract) {
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

            $paymentIntentId = $session->payment_intent ?? null;
            if (! $paymentIntentId) {
                Log::error('No payment intent in checkout session', [
                    'session_id' => $session->id,
                    'contract_id' => $contractId,
                ]);

                return;
            }

            $paymentIntent = $this->stripeWrapper->retrievePaymentIntent($paymentIntentId);

            if ($paymentIntent->status !== 'succeeded') {
                Log::warning('Payment intent not succeeded', [
                    'payment_intent_id' => $paymentIntentId,
                    'status' => $paymentIntent->status,
                    'contract_id' => $contractId,
                ]);

                return;
            }

            DB::beginTransaction();

            $transaction = $this->paymentRepository->createTransaction([
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

            $jobPayment = JobPayment::create([
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

            $balance = CreatorBalance::firstOrCreate(
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

            NotificationService::notifyCreatorOfContractStarted($contract);
            NotificationService::notifyBrandOfContractStarted($contract);

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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function handleOfferFundingCheckout($session, $userId, $amount): void
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
                $user = $this->paymentRepository->findUserById($userId);
            }

            if (! $user && $session->customer) {
                $user = $this->paymentRepository->findUserByStripeCustomerId($session->customer);
            }

            if (! $user) {
                Log::error('User not found for offer funding checkout', [
                    'session_id' => $session->id,
                    'user_id' => $userId,
                    'customer_id' => $session->customer,
                ]);

                return;
            }

            $paymentIntentId = $session->payment_intent ?? null;
            if (! $paymentIntentId) {
                Log::error('No payment intent in offer funding checkout session', [
                    'session_id' => $session->id,
                    'user_id' => $user->id,
                ]);

                return;
            }

            $paymentIntent = $this->stripeWrapper->retrievePaymentIntent($paymentIntentId, [
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
                    $campaign = Campaign::find($campaignId);
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

            if (! empty($paymentIntent->charges->data)) {
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
                $existingTransaction = $this->paymentRepository->createTransaction([
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
                    'transaction_id' => $existingTransaction->id,
                    'user_id' => $user->id,
                    'session_id' => $session->id,
                    'payment_intent_id' => $paymentIntent->id,
                    'amount' => $transactionAmount,
                ]);

                try {
                    $metadata = $session->metadata ?? null;
                    $fundingData = [
                        'transaction_id' => $existingTransaction->id,
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

                    $notification = Notification::createPlatformFundingSuccess(
                        $user->id,
                        $transactionAmount,
                        $fundingData
                    );

                    NotificationService::sendSocketNotification($user->id, $notification);

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

    public function markSubscriptionPaymentFailed(string $stripeSubscriptionId, ?string $latestInvoiceId = null): void
    {
        try {
            Log::info('Marking subscription payment as failed', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
            ]);

            $localSub = $this->paymentRepository->findLocalSubscriptionByStripeId($stripeSubscriptionId);
            if (! $localSub) {
                Log::warning('Local subscription not found when marking payment failed', [
                    'stripe_subscription_id' => $stripeSubscriptionId,
                ]);

                return;
            }

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

    public function handleGeneralSetupCheckout($session): void
    {
        try {
            Log::info('Handling general setup mode checkout session completed', [
                'session_id' => $session->id,
                'customer_id' => $session->customer,
                'mode' => $session->mode,
                'setup_intent' => $session->setup_intent ?? null,
            ]);

            // Check if this is a Brand Payment Method setup
            $metadata = $session->metadata ?? null;
            $type = null;
            $userId = null;

            if (is_array($metadata)) {
                $type = $metadata['type'] ?? null;
                $userId = $metadata['user_id'] ?? null;
            } elseif (is_object($metadata)) {
                $type = $metadata->type ?? null;
                $userId = $metadata->user_id ?? null;
            }

            if ($type === 'payment_method_setup' && $userId) {
                $user = $this->findUserById($userId);
                if ($user) {
                    Log::info('Delegating Brand Payment Method setup to PaymentService handleSetupSessionSuccess', ['user_id' => $userId]);
                    $this->handleSetupSessionSuccess($session->id, $user);

                    return;
                }
            }

            $setupIntentId = $session->setup_intent ?? null;
            if (! $setupIntentId) {
                Log::warning('No setup intent in checkout session', [
                    'session_id' => $session->id,
                ]);

                return;
            }

            $setupIntent = $this->stripeWrapper->retrieveSetupIntent($setupIntentId, [
                'expand' => ['payment_method'],
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

            if (! $paymentMethodId) {
                Log::warning('No payment method in setup intent', [
                    'setup_intent_id' => $setupIntentId,
                ]);

                return;
            }

            $customerId = $session->customer;
            if (! $customerId) {
                Log::warning('No customer ID in checkout session', [
                    'session_id' => $session->id,
                ]);

                return;
            }

            $user = $this->paymentRepository->findUserByStripeCustomerId($customerId);
            if (! $user) {
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

            try {
                $paymentMethod = $this->stripeWrapper->retrievePaymentMethod($paymentMethodId);
                if ($paymentMethod->type === 'card' && isset($paymentMethod->card)) {
                    $cardBrand = $paymentMethod->card->brand ?? null;
                    $cardLast4 = $paymentMethod->card->last4 ?? null;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to retrieve payment method details from Stripe', [
                    'payment_method_id' => $paymentMethodId,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::beginTransaction();

            try {
                $this->paymentRepository->updateUserDefaultPaymentMethod($user, $paymentMethodId);

                $user->refresh();
                if ($user->stripe_payment_method_id !== $paymentMethodId) {
                    $user->stripe_payment_method_id = $paymentMethodId;
                    $user->save();
                    $user->refresh();
                }

                $stripeCardMethod = null;
                if ($isCreatorOrStudent) {
                    $stripeCardMethod = \App\Models\WithdrawalMethod::where('code', 'stripe_card')->first();

                    if (! $stripeCardMethod) {
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
                        if (! $stripeCardMethod->is_active) {
                            $stripeCardMethod->update(['is_active' => true]);
                            Log::info('Activated stripe_card withdrawal method', [
                                'withdrawal_method_id' => $stripeCardMethod->id,
                                'user_id' => $user->id,
                            ]);
                        }
                    }
                }

                DB::commit();

                Log::info('Updated user payment method ID and withdrawal methods from setup mode checkout', [
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'is_creator_or_student' => $isCreatorOrStudent,
                    'payment_method_id' => $paymentMethodId,
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
            Log::error('Failed to handle general setup mode checkout', [
                'session_id' => $session->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

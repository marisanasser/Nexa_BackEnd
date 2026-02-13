<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Models\Payment\BrandPaymentMethod;
use App\Models\Payment\WithdrawalMethod;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;

/**
 * PaymentMethodService handles payment method operations.
 *
 * Responsibilities:
 * - Managing brand payment methods
 * - Setting default payment methods
 * - Creating setup checkout sessions
 */
class PaymentMethodService
{
    public function __construct(
        private StripeWrapper $stripeWrapper,
        private StripeCustomerService $customerService
    ) {
    }

    /**
     * Save a new payment method for a brand user.
     */
    public function saveBrandPaymentMethod(User $user, array $data): BrandPaymentMethod
    {
        $customerId = $this->customerService->ensureStripeCustomer($user);

        // Attach the payment method to the customer in Stripe
        $this->stripeWrapper->attachPaymentMethodToCustomer(
            $data['stripe_payment_method_id'],
            $customerId
        );

        // Check if this is the first payment method (make it default)
        $hasExistingMethods = BrandPaymentMethod::where('user_id', $user->id)
            ->where('is_active', true)
            ->exists()
        ;

        $paymentMethod = BrandPaymentMethod::create([
            'user_id' => $user->id,
            'stripe_payment_method_id' => $data['stripe_payment_method_id'],
            'card_brand' => $data['card_brand'] ?? null,
            'card_last4' => $data['card_last_four'] ?? null, // Fixed key mapping if needed, check model
            'card_holder_name' => $data['card_holder_name'] ?? null, // Add this
            // 'card_exp_month' => $data['card_exp_month'] ?? null, // Not in table schema provided?
            // 'card_exp_year' => $data['card_exp_year'] ?? null,
            'is_default' => !$hasExistingMethods,
            'is_active' => true,
        ]);

        Log::info('Saved brand payment method', [
            'user_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
        ]);

        return $paymentMethod;
    }

    /**
     * Set a payment method as default for a user.
     */
    public function setAsDefault(User $user, BrandPaymentMethod $paymentMethod): void
    {
        // Verify ownership
        if ($paymentMethod->user_id !== $user->id) {
            throw new Exception('Payment method does not belong to this user');
        }

        // Unset current default
        BrandPaymentMethod::where('user_id', $user->id)
            ->where('is_default', true)
            ->update(['is_default' => false])
        ;

        // Set new default
        $paymentMethod->update(['is_default' => true]);

        Log::info('Set default payment method', [
            'user_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
    }

    /**
     * Get all active payment methods for a user.
     */
    public function getBrandPaymentMethods(User $user): Collection
    {
        return BrandPaymentMethod::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
        ;
    }

    /**
     * Get a specific payment method for a user.
     */
    public function getBrandPaymentMethod(User $user, int $paymentMethodId): BrandPaymentMethod
    {
        $paymentMethod = BrandPaymentMethod::where('user_id', $user->id)
            ->where('id', $paymentMethodId)
            ->first()
        ;

        if (!$paymentMethod) {
            throw new Exception('Payment method not found');
        }

        return $paymentMethod;
    }

    /**
     * Delete a payment method.
     */
    public function deleteBrandPaymentMethod(User $user, int $paymentMethodId): void
    {
        $paymentMethod = $this->getBrandPaymentMethod($user, $paymentMethodId);

        // Detach from Stripe
        try {
            $this->stripeWrapper->detachPaymentMethod($paymentMethod->stripe_payment_method_id);
        } catch (Exception $e) {
            Log::warning('Failed to detach payment method from Stripe', [
                'payment_method_id' => $paymentMethod->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Soft delete by marking as inactive
        $paymentMethod->update(['is_active' => false]);

        // If it was the default, set another as default
        if ($paymentMethod->is_default) {
            $nextDefault = BrandPaymentMethod::where('user_id', $user->id)
                ->where('is_active', true)
                ->first()
            ;

            if ($nextDefault) {
                $nextDefault->update(['is_default' => true]);
            }
        }

        Log::info('Deleted brand payment method', [
            'user_id' => $user->id,
            'payment_method_id' => $paymentMethodId,
        ]);
    }

    /**
     * Get the default payment method for a user.
     */
    public function getDefaultPaymentMethod(User $user): ?BrandPaymentMethod
    {
        return BrandPaymentMethod::where('user_id', $user->id)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first()
        ;
    }

    /**
     * Create a Checkout Session for setting up a payment method.
     */
    public function createSetupCheckoutSession(User $user, string $successUrl, string $cancelUrl): Session
    {
        $customerId = $this->customerService->ensureStripeCustomer($user);

        return $this->stripeWrapper->createCheckoutSession([
            'customer' => $customerId,
            'mode' => 'setup',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => (string) $user->id,
                'type' => 'payment_method_setup',
            ],
        ]);
    }

    /**
     * Handle the success return from a setup checkout session.
     * Usually called from a controller after user redirection.
     */
    public function handleSetupSessionSuccess(string $sessionId, User $user): array
    {
        $session = $this->stripeWrapper->retrieveCheckoutSession($sessionId, [
            'expand' => ['setup_intent', 'setup_intent.payment_method'],
        ]);

        return $this->processSetupSession($session, $user);
    }

    /**
     * Process a setup checkout session (from webhook or direct return).
     */
    public function processSetupSession(Session $session, ?User $user = null): array
    {
        Log::info('Processing setup session', [
            'session_id' => $session->id,
            'customer_id' => $session->customer,
            'user_id' => $user?->id,
        ]);

        // Attempt to find user if not provided
        if (!$user) {
            $metadata = $session->metadata ?? null;
            $userId = null;

            if (is_array($metadata)) {
                $userId = $metadata['user_id'] ?? null;
            } elseif (is_object($metadata)) {
                $userId = $metadata->user_id ?? null;
            }

            if ($userId) {
                $user = User::find($userId);
            } elseif ($session->customer) {
                // Try finding by stripe customer id - implementation dependent on where this is stored
                $user = User::where('stripe_customer_id', $session->customer)->first();
            }
        }

        if (!$user) {
            Log::warning('User not found for setup session processing', ['session_id' => $session->id]);

            throw new Exception('User not found');
        }

        $setupIntent = $session->setup_intent;
        // Verify if setupIntent is expanded object or ID (it should be expanded if called from handleSetupSessionSuccess, but webhook might vary)
        if (is_string($setupIntent)) {
            $setupIntent = $this->stripeWrapper->retrieveSetupIntent($setupIntent, ['expand' => ['payment_method']]);
        }

        if ('succeeded' !== $setupIntent->status) {
            throw new Exception('Setup intent not succeeded');
        }

        $paymentMethodObj = $setupIntent->payment_method;
        if (is_string($paymentMethodObj)) {
            $paymentMethodObj = $this->stripeWrapper->retrievePaymentMethod($paymentMethodObj);
        }

        if (!$paymentMethodObj) {
            throw new Exception('Payment method not found in setup intent');
        }

        $stripePaymentMethodId = $paymentMethodObj->id;

        // Extract card details
        $cardBrand = null;
        $cardLast4 = null;
        $cardHolderName = null;
        $cardExpMonth = null;
        $cardExpYear = null;

        if ('card' === $paymentMethodObj->type && isset($paymentMethodObj->card)) {
            $cardBrand = $paymentMethodObj->card->brand ?? null;
            $cardLast4 = $paymentMethodObj->card->last4 ?? null;
            $cardExpMonth = $paymentMethodObj->card->exp_month ?? null;
            $cardExpYear = $paymentMethodObj->card->exp_year ?? null;
            // Name might be on billing_details
            $cardHolderName = $paymentMethodObj->billing_details->name ?? null;
        }

        // Logic based on User Role or Type
        $isBrand = $user->isBrand();
        $isCreatorOrStudent = $user->isCreator() || $user->isStudent();

        if ($isBrand) {
            // Save as BrandPaymentMethod
            
            // Ensure card_holder_name is set or null
            if (!$cardHolderName) {
                $cardHolderName = $user->name ?? 'Unknown Holder';
            }

            $data = [
                'stripe_payment_method_id' => $stripePaymentMethodId,
                'card_brand' => $cardBrand,
                'card_last_four' => $cardLast4,
                'card_holder_name' => $cardHolderName, // Add this field
                'card_exp_month' => $cardExpMonth,
                'card_exp_year' => $cardExpYear,
            ];
            
            // ... rest of the code
            $existing = BrandPaymentMethod::where('user_id', $user->id)
                ->where('stripe_payment_method_id', $stripePaymentMethodId)
                ->first()
            ;

            $savedMethod = null;
            if (!$existing) {
                $savedMethod = $this->saveBrandPaymentMethod($user, $data);
            } else {
                $savedMethod = $existing;
                // Maybe ensure it's attached? It should be.
            }

            return ['payment_method' => $savedMethod, 'type' => 'brand_payment_method'];
        }

        if ($isCreatorOrStudent) {
            // Creator logic: Update user stripe_payment_method_id and withdrawal methods
            $user->update(['stripe_payment_method_id' => $stripePaymentMethodId]);

            // Handle Withdrawal Method
            $stripeCardMethod = WithdrawalMethod::where('code', 'stripe_card')->first();
            if (!$stripeCardMethod) {
                $stripeCardMethod = WithdrawalMethod::create([
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
            } else {
                if (!$stripeCardMethod->is_active) {
                    $stripeCardMethod->update(['is_active' => true]);
                }
            }

            return ['payment_method_id' => $stripePaymentMethodId, 'type' => 'user_payment_method'];
        }

        return ['status' => 'processed_no_action'];
    }
}

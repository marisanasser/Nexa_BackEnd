<?php

namespace App\Services;

use App\Models\User;
use App\Models\BrandPaymentMethod;
use App\Repositories\PaymentRepository;
use App\Wrappers\StripeWrapper;
use Illuminate\Support\Facades\Log;
use Stripe\Customer;
use Stripe\Checkout\Session;

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
     * @param User $user
     * @param array $data
     * @return BrandPaymentMethod
     * @throws \Exception
     */
    public function saveBrandPaymentMethod(User $user, array $data): BrandPaymentMethod
    {
        $cardBrand = 'Unknown'; 
        $cardLast4 = '0000';
        
        if (isset($data['card_hash'])) {
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
     *
     * @param User $user
     * @param BrandPaymentMethod $paymentMethod
     * @return void
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
     * @param User $user
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
     *
     * @param User $user
     * @return Session
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
            'success_url' => $frontendUrl . '/brand/payment-methods?success=true&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontendUrl . '/brand/payment-methods?canceled=true',
            'metadata' => [
                'user_id' => (string) $user->id,
                'type' => 'payment_method_setup',
            ],
        ]);
    }

    /**
     * Get all active payment methods for a user.
     *
     * @param User $user
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getBrandPaymentMethods(User $user)
    {
        return $this->paymentRepository->getBrandPaymentMethods($user->id);
    }

    /**
     * Delete a payment method.
     *
     * @param User $user
     * @param int $paymentMethodId
     * @return void
     * @throws \Exception
     */
    public function deleteBrandPaymentMethod(User $user, int $paymentMethodId): void
    {
        $paymentMethod = $this->paymentRepository->findBrandPaymentMethod($user->id, $paymentMethodId);
        
        if (!$paymentMethod) {
            throw new \Exception('Payment method not found');
        }

        if ($this->paymentRepository->countActiveBrandPaymentMethods($user->id) <= 1) {
            throw new \Exception('Cannot delete the only payment method. Please add another one first.');
        }

        $wasDefault = $paymentMethod->is_default;
        $paymentMethodStripeId = $paymentMethod->stripe_payment_method_id;

        // Soft delete
        $paymentMethod->update(['is_active' => false]);
        
        if ($wasDefault && $user->stripe_payment_method_id === $paymentMethodStripeId) {
            $nextDefault = $this->paymentRepository->getFirstActivePaymentMethod($user->id);
            
            if ($nextDefault && $nextDefault->stripe_payment_method_id) {
                $this->setAsDefault($user, $nextDefault);
            } else {
                $this->paymentRepository->updateUserDefaultPaymentMethod($user, null);
            }
        }
    }

    /**
     * Handle success of a Setup Checkout Session.
     * 
     * @param string $sessionId
     * @param User $user
     * @return array Result data
     */
    public function handleSetupSessionSuccess(string $sessionId, User $user): array
    {
        $session = $this->stripeWrapper->retrieveCheckoutSession($sessionId, ['expand' => ['setup_intent.payment_method']]);

        // Verification Logic
        $sessionUserId = $session->metadata->user_id ?? null;
        $sessionCustomerId = is_object($session->customer) ? $session->customer->id : $session->customer;

        $isValid = false;
        if ($sessionUserId && (string)$sessionUserId === (string)$user->id) {
            $isValid = true;
        } elseif ($sessionCustomerId && $user->stripe_customer_id && $sessionCustomerId === $user->stripe_customer_id) {
            $isValid = true;
        }

        if (!$isValid) {
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
}

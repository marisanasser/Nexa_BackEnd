<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Customer;
use Stripe\Exception\InvalidRequestException;

/**
 * StripeCustomerService handles Stripe customer-related operations.
 *
 * Responsibilities:
 * - Ensuring users have Stripe customer IDs
 * - Creating and updating Stripe customers
 * - Managing customer metadata
 */
class StripeCustomerService
{
    public function __construct(
        private StripeWrapper $stripeWrapper
    ) {
    }

    /**
     * Ensure a user has a Stripe customer ID.
     * Creates a new Stripe customer if one doesn't exist.
     */
    public function ensureStripeCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            try {
                $customer = $this->stripeWrapper->retrieveCustomer($user->stripe_customer_id);

                if (!$customer->deleted) {
                    return $user->stripe_customer_id;
                }

                Log::warning('Stored Stripe customer is deleted, creating replacement', [
                    'user_id' => $user->id,
                    'old_customer_id' => $user->stripe_customer_id,
                ]);
            } catch (InvalidRequestException $e) {
                if (!$this->isMissingCustomerException($e)) {
                    throw new Exception('Failed to validate Stripe customer: ' . $e->getMessage(), 0, $e);
                }

                Log::warning('Stored Stripe customer was not found, creating replacement', [
                    'user_id' => $user->id,
                    'old_customer_id' => $user->stripe_customer_id,
                    'error' => $e->getMessage(),
                ]);
            } catch (Exception $e) {
                throw new Exception('Failed to validate Stripe customer: ' . $e->getMessage(), 0, $e);
            }
        }

        return $this->createStripeCustomer($user);
    }

    /**
     * Create a Stripe customer for a user.
     */
    public function createStripeCustomer(User $user): string
    {
        try {
            $customer = $this->stripeWrapper->createCustomer([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'role' => $user->role,
                ],
            ]);

            $user->update(['stripe_customer_id' => $customer->id]);

            Log::info('Created Stripe customer', [
                'user_id' => $user->id,
                'stripe_customer_id' => $customer->id,
            ]);

            return $customer->id;
        } catch (Exception $e) {
            Log::error('Failed to create Stripe customer', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Failed to create Stripe customer: ' . $e->getMessage());
        }
    }

    /**
     * Update Stripe customer metadata.
     */
    public function updateCustomerMetadata(User $user, array $metadata): void
    {
        if (!$user->stripe_customer_id) {
            return;
        }

        try {
            $this->stripeWrapper->updateCustomer($user->stripe_customer_id, [
                'metadata' => $metadata,
            ]);

            Log::info('Updated Stripe customer metadata', [
                'user_id' => $user->id,
                'stripe_customer_id' => $user->stripe_customer_id,
            ]);
        } catch (Exception $e) {
            Log::warning('Failed to update Stripe customer metadata', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get Stripe customer details.
     */
    public function getCustomer(string $customerId): ?Customer
    {
        try {
            return $this->stripeWrapper->retrieveCustomer($customerId);
        } catch (Exception $e) {
            Log::error('Failed to retrieve Stripe customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if a user has a valid Stripe customer.
     */
    public function hasValidStripeCustomer(User $user): bool
    {
        if (!$user->stripe_customer_id) {
            return false;
        }

        $customer = $this->getCustomer($user->stripe_customer_id);

        return null !== $customer && !$customer->deleted;
    }

    private function isMissingCustomerException(InvalidRequestException $exception): bool
    {
        if (404 === $exception->getHttpStatus()) {
            return true;
        }

        if ('resource_missing' === $exception->getStripeCode()) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'no such customer');
    }
}

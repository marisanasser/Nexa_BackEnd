# Example: Charging a Saved Payment Method

This document shows how to charge a payment method that was saved using Stripe Checkout Session (setup mode).

## Overview

After a brand adds a payment method using the Stripe Checkout Session in setup mode, the payment method is saved to the `brand_payment_methods` table with:
- `stripe_customer_id`: The Stripe Customer ID
- `stripe_payment_method_id`: The Stripe Payment Method ID

## Example: Charging a Saved Payment Method

Here's an example of how to charge a saved payment method for a contract payment:

```php
<?php

namespace App\Http\Controllers;

use App\Models\BrandPaymentMethod;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class ContractPaymentController extends Controller
{
    public function __construct()
    {
        $stripeSecret = config('services.stripe.secret');
        if ($stripeSecret) {
            Stripe::setApiKey($stripeSecret);
        }
    }

    /**
     * Charge a saved payment method for a contract payment
     */
    public function chargePaymentMethod(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can process payments',
                ], 403);
            }

            $request->validate([
                'contract_id' => 'required|exists:contracts,id,brand_id,' . $user->id,
                'payment_method_id' => 'required|exists:brand_payment_methods,id,user_id,' . $user->id,
            ]);

            $contract = Contract::findOrFail($request->contract_id);
            $paymentMethod = BrandPaymentMethod::findOrFail($request->payment_method_id);

            // Verify payment method belongs to the brand
            if ($paymentMethod->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method not found',
                ], 404);
            }

            // Verify payment method has Stripe details
            if (!$paymentMethod->stripe_customer_id || !$paymentMethod->stripe_payment_method_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method is not configured for Stripe',
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Calculate amount in cents
                $amount = (int) round($contract->budget * 100); // Convert to cents

                // Create PaymentIntent with the saved payment method
                $paymentIntent = PaymentIntent::create([
                    'amount' => $amount,
                    'currency' => 'brl', // or 'usd' depending on your needs
                    'customer' => $paymentMethod->stripe_customer_id,
                    'payment_method' => $paymentMethod->stripe_payment_method_id,
                    'confirmation_method' => 'automatic',
                    'confirm' => true,
                    'description' => 'Contract #' . $contract->id . ' - ' . ($contract->title ?? 'Campaign'),
                    'metadata' => [
                        'contract_id' => (string) $contract->id,
                        'brand_id' => (string) $user->id,
                        'creator_id' => (string) $contract->creator_id,
                        'payment_method_id' => (string) $paymentMethod->id,
                    ],
                    // Off-session payment (customer is not present)
                    'off_session' => true,
                    // Error handling for 3D Secure
                    'error_on_requires_action' => false,
                ]);

                Log::info('PaymentIntent created for contract payment', [
                    'contract_id' => $contract->id,
                    'payment_intent_id' => $paymentIntent->id,
                    'status' => $paymentIntent->status,
                    'amount' => $amount,
                ]);

                // Handle different payment intent statuses
                if ($paymentIntent->status === 'succeeded') {
                    // Payment succeeded immediately
                    $this->handleSuccessfulPayment($contract, $paymentIntent, $paymentMethod);
                    
                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment processed successfully',
                        'data' => [
                            'payment_intent_id' => $paymentIntent->id,
                            'status' => 'succeeded',
                            'amount' => $contract->budget,
                        ],
                    ]);
                } elseif ($paymentIntent->status === 'requires_action') {
                    // 3D Secure authentication required
                    // The frontend will need to handle this
                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'requires_action' => true,
                        'client_secret' => $paymentIntent->client_secret,
                        'payment_intent_id' => $paymentIntent->id,
                    ]);
                } else {
                    // Payment failed or requires action
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Payment failed: ' . ($paymentIntent->last_payment_error->message ?? 'Unknown error'),
                        'status' => $paymentIntent->status,
                    ], 400);
                }

            } catch (\Stripe\Exception\CardException $e) {
                DB::rollBack();

                // Card was declined
                Log::error('Card declined for contract payment', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                    'decline_code' => $e->getDeclineCode(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Card declined: ' . $e->getMessage(),
                    'decline_code' => $e->getDeclineCode(),
                ], 400);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to charge payment method', [
                'contract_id' => $request->contract_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle successful payment
     */
    private function handleSuccessfulPayment($contract, $paymentIntent, $paymentMethod): void
    {
        // Create transaction record
        $transaction = \App\Models\Transaction::create([
            'user_id' => $contract->brand_id,
            'stripe_payment_intent_id' => $paymentIntent->id,
            'status' => 'paid',
            'amount' => $contract->budget,
            'payment_method' => 'stripe',
            'paid_at' => now(),
            'payment_data' => [
                'contract_id' => $contract->id,
                'payment_method_id' => $paymentMethod->id,
                'stripe_customer_id' => $paymentMethod->stripe_customer_id,
            ],
        ]);

        // Update contract payment status
        if ($contract->payment) {
            $contract->payment->update([
                'status' => 'completed',
                'transaction_id' => $transaction->id,
            ]);
        }

        Log::info('Contract payment processed successfully', [
            'contract_id' => $contract->id,
            'transaction_id' => $transaction->id,
            'payment_intent_id' => $paymentIntent->id,
        ]);
    }

    /**
     * Retry a failed payment with 3D Secure
     */
    public function confirmPaymentWith3DS(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'payment_intent_id' => 'required|string',
            ]);

            $paymentIntent = PaymentIntent::retrieve($request->payment_intent_id);

            // Confirm the payment intent (after 3D Secure)
            $paymentIntent->confirm();

            if ($paymentIntent->status === 'succeeded') {
                // Find the contract and update payment status
                $contractId = $paymentIntent->metadata->contract_id ?? null;
                
                if ($contractId) {
                    $contract = Contract::find($contractId);
                    if ($contract) {
                        $paymentMethod = BrandPaymentMethod::where('stripe_payment_method_id', $paymentIntent->payment_method)
                            ->where('user_id', $contract->brand_id)
                            ->first();

                        if ($paymentMethod) {
                            $this->handleSuccessfulPayment($contract, $paymentIntent, $paymentMethod);
                        }
                    }
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Payment confirmed successfully',
                    'status' => 'succeeded',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Payment not confirmed',
                'status' => $paymentIntent->status,
            ], 400);

        } catch (\Exception $e) {
            Log::error('Failed to confirm payment with 3DS', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm payment: ' . $e->getMessage(),
            ], 500);
        }
    }
}
```

## Frontend Example (React/TypeScript)

```typescript
// Example: Charge a saved payment method from frontend
import { brandPaymentApi } from '@/api/payment/brandPayment';

const chargeContractPayment = async (contractId: string, paymentMethodId: string) => {
  try {
    const response = await fetch('/api/contract-payment/charge', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({
        contract_id: contractId,
        payment_method_id: paymentMethodId,
      }),
    });

    const data = await response.json();

    if (data.success) {
      if (data.requires_action) {
        // Handle 3D Secure
        const stripe = await loadStripe(STRIPE_PUBLISHABLE_KEY);
        if (stripe) {
          const { error } = await stripe.confirmCardPayment(data.client_secret);
          
          if (error) {
            console.error('Payment failed:', error);
          } else {
            // Payment succeeded after 3DS
            console.log('Payment confirmed');
          }
        }
      } else {
        // Payment succeeded immediately
        console.log('Payment successful');
      }
    }
  } catch (error) {
    console.error('Payment error:', error);
  }
};
```

## Key Points

1. **Off-Session Payment**: When charging a saved payment method, use `'off_session' => true` in the PaymentIntent creation. This tells Stripe the customer is not present.

2. **3D Secure Handling**: Some payments may require 3D Secure authentication. Check the `status` field:
   - `succeeded`: Payment completed
   - `requires_action`: 3D Secure required
   - `requires_payment_method`: Card declined, retry with different card

3. **Error Handling**: Use try-catch blocks to handle Stripe exceptions, especially `CardException` for declined cards.

4. **Metadata**: Always include metadata in PaymentIntent for tracking and debugging.

5. **Idempotency**: For retries, consider using Stripe's idempotency keys to prevent duplicate charges.

## Testing

Use Stripe test cards:
- Success: `4242 4242 4242 4242`
- 3D Secure: `4000 0025 0000 3155`
- Declined: `4000 0000 0000 0002`

See [Stripe Testing](https://stripe.com/docs/testing) for more test cards.


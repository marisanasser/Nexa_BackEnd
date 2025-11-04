<?php

namespace App\Http\Controllers;

use App\Models\BrandPaymentMethod;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Checkout\Session;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\SetupIntent;

class BrandPaymentController extends Controller
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = env('PAGARME_API_KEY', '');
        $this->baseUrl = 'https://api.pagar.me/core/v5';
        
        // Initialize Stripe
        $stripeSecret = config('services.stripe.secret');
        if ($stripeSecret) {
            Stripe::setApiKey($stripeSecret);
        }
    }

    /**
     * Save brand's payment method (card registration only, no payment)
     */
    public function savePaymentMethod(Request $request): JsonResponse
    {
        $user = auth()->user();

        Log::info('Save payment method request', [
            'user_id' => $user->id,
            'request_data' => $request->all()
        ]);

        // Check if user is a brand
        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can save payment methods',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'card_hash' => 'required|string',
            'card_holder_name' => 'required|string|max:255',
            'cnpj' => 'required|string|regex:/^\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}$/',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Extract card information from the card_hash (simplified approach)
            // In a real implementation, you would decrypt or parse the card_hash
            $cardInfo = $this->parseCardInfo($request->card_hash, $request->card_holder_name);

            // Check if this card already exists for the user
            $existingMethod = BrandPaymentMethod::where('user_id', $user->id)
                ->where('card_hash', $request->card_hash)
                ->where('is_active', true)
                ->first();

            if ($existingMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'This payment method already exists',
                ], 400);
            }

            // Save payment method to database
            $paymentMethod = BrandPaymentMethod::create([
                'user_id' => $user->id,
                'card_holder_name' => $request->card_holder_name,
                'card_brand' => $cardInfo['brand'],
                'card_last4' => $cardInfo['last4'],
                'is_default' => $request->is_default ?? false,
                'card_hash' => $request->card_hash,
                'is_active' => true,
            ]);

            // If this is set as default, unset other default methods
            if ($request->is_default) {
                BrandPaymentMethod::where('user_id', $user->id)
                    ->where('id', '!=', $paymentMethod->id)
                    ->update(['is_default' => false]);
            }

            Log::info('Payment method created successfully', [
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethod->id,
                'card_brand' => $cardInfo['brand'],
                'card_last4' => $cardInfo['last4']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment method added successfully',
                'data' => [
                    'payment_method_id' => $paymentMethod->id,
                    'card_holder_name' => $paymentMethod->card_holder_name,
                    'card_brand' => $paymentMethod->card_brand,
                    'card_last4' => $paymentMethod->card_last4,
                    'is_default' => $paymentMethod->is_default,
                    'created_at' => $paymentMethod->created_at
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create payment method', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment method. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get brand's payment methods
     */
    public function getPaymentMethods(): JsonResponse
    {
        $user = auth()->user();

        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can access payment methods',
            ], 403);
        }

        $paymentMethods = $user->brandPaymentMethods()
            ->active()
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $paymentMethods->map(function ($method) {
                return [
                    'id' => $method->id,
                    'card_info' => $method->formatted_card_info,
                    'card_brand' => $method->card_brand,
                    'card_last4' => $method->card_last4,
                    'card_holder_name' => $method->card_holder_name,
                    'is_default' => $method->is_default,
                    'created_at' => $method->created_at,
                ];
            })
        ]);
    }

    /**
     * Set payment method as default
     */
    public function setDefaultPaymentMethod(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can manage payment methods',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|exists:brand_payment_methods,id,user_id,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $paymentMethod = BrandPaymentMethod::find($request->payment_method_id);
        $paymentMethod->setAsDefault();

        return response()->json([
            'success' => true,
            'message' => 'Default payment method updated successfully',
        ]);
    }

    /**
     * Delete payment method
     */
    public function deletePaymentMethod(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can manage payment methods',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|exists:brand_payment_methods,id,user_id,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $paymentMethod = BrandPaymentMethod::find($request->payment_method_id);
        
        // Don't allow deletion of the only payment method
        if ($user->brandPaymentMethods()->active()->count() <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete the only payment method. Please add another one first.',
            ], 400);
        }

        $paymentMethod->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Payment method deleted successfully',
        ]);
    }

    /**
     * Create Stripe Checkout Session for adding payment method (setup mode)
     */
    public function createCheckoutSession(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can add payment methods',
                ], 403);
            }

            Log::info('Creating Stripe Checkout Session for payment method setup', [
                'user_id' => $user->id,
            ]);

            // Ensure Stripe customer exists for the brand
            $customerId = $user->stripe_customer_id;
            
            if (!$customerId) {
                Log::info('Creating new Stripe customer for brand', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);

                $customer = Customer::create([
                    'email' => $user->email,
                    'name' => $user->name,
                    'metadata' => [
                        'user_id' => $user->id,
                        'role' => 'brand',
                    ],
                ]);

                $customerId = $customer->id;
                $user->update(['stripe_customer_id' => $customerId]);

                Log::info('Stripe customer created for brand', [
                    'user_id' => $user->id,
                    'customer_id' => $customerId,
                ]);
            } else {
                // Verify customer exists
                try {
                    Customer::retrieve($customerId);
                } catch (\Exception $e) {
                    Log::warning('Stripe customer not found, creating new one', [
                        'user_id' => $user->id,
                        'old_customer_id' => $customerId,
                    ]);

                    $customer = Customer::create([
                        'email' => $user->email,
                        'name' => $user->name,
                        'metadata' => [
                            'user_id' => $user->id,
                            'role' => 'brand',
                        ],
                    ]);

                    $customerId = $customer->id;
                    $user->update(['stripe_customer_id' => $customerId]);
                }
            }

            // Get frontend URL from config
            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

            // Create Checkout Session in setup mode
            $session = Session::create([
                'customer' => $customerId,
                'mode' => 'setup',
                'payment_method_types' => ['card'],
                'success_url' => $frontendUrl . '/brand/payment-methods?success=true&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $frontendUrl . '/brand/payment-methods?canceled=true',
                'metadata' => [
                    'user_id' => (string) $user->id, // Ensure it's stored as string
                    'type' => 'payment_method_setup',
                ],
            ]);
            
            Log::info('Checkout session created with metadata', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'metadata' => $session->metadata ?? null,
            ]);

            Log::info('Stripe Checkout Session created', [
                'user_id' => $user->id,
                'session_id' => $session->id,
                'customer_id' => $customerId,
            ]);

            return response()->json([
                'success' => true,
                'url' => $session->url,
                'session_id' => $session->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create Stripe Checkout Session', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle successful Stripe Checkout Session completion
     * This saves the payment method from the setup intent
     */
    public function handleCheckoutSuccess(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can add payment methods',
                ], 403);
            }

            $request->validate([
                'session_id' => 'required|string',
            ]);

            $sessionId = $request->session_id;

            Log::info('Handling Stripe Checkout Session success', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
            ]);

            // Retrieve the checkout session
            $session = Session::retrieve($sessionId, [
                'expand' => ['setup_intent', 'setup_intent.payment_method'],
            ]);

            // Verify session belongs to this user
            // Check metadata first, then fallback to customer ID
            $sessionUserId = null;
            $metadata = $session->metadata ?? null;
            
            Log::info('Checking session metadata', [
                'session_id' => $sessionId,
                'user_id' => $user->id,
                'metadata_type' => gettype($metadata),
                'metadata' => $metadata,
            ]);
            
            if ($metadata) {
                if (is_object($metadata)) {
                    // Access as object property
                    $sessionUserId = $metadata->user_id ?? null;
                    // Also try array access
                    if (!$sessionUserId) {
                        $metadataArray = (array) $metadata;
                        $sessionUserId = $metadataArray['user_id'] ?? null;
                    }
                } elseif (is_array($metadata)) {
                    $sessionUserId = $metadata['user_id'] ?? null;
                }
            }
            
            // Get customer ID from session
            $sessionCustomerId = null;
            if ($session->customer) {
                $sessionCustomerId = is_object($session->customer) ? $session->customer->id : $session->customer;
            }
            
            Log::info('Session verification details', [
                'session_user_id' => $sessionUserId,
                'authenticated_user_id' => $user->id,
                'session_customer_id' => $sessionCustomerId,
                'user_customer_id' => $user->stripe_customer_id,
            ]);
            
            // Verify: Check metadata user_id OR customer ID match
            $isValid = false;
            
            // Check 1: Metadata user_id matches
            if ($sessionUserId && (string)$sessionUserId === (string)$user->id) {
                $isValid = true;
                Log::info('Session verified by metadata user_id');
            }
            // Check 2: Customer ID matches (fallback if metadata missing)
            elseif ($sessionCustomerId && $user->stripe_customer_id && $sessionCustomerId === $user->stripe_customer_id) {
                $isValid = true;
                Log::info('Session verified by customer ID');
            }
            // Check 3: If no metadata but customer IDs match, allow it
            elseif (!$sessionUserId && $sessionCustomerId && $user->stripe_customer_id && $sessionCustomerId === $user->stripe_customer_id) {
                $isValid = true;
                Log::info('Session verified by customer ID (no metadata)');
            }
            
            if (!$isValid) {
                Log::warning('Session verification failed', [
                    'session_user_id' => $sessionUserId,
                    'authenticated_user_id' => $user->id,
                    'session_customer_id' => $sessionCustomerId,
                    'user_customer_id' => $user->stripe_customer_id,
                    'session_id' => $sessionId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid session - session does not belong to this user',
                ], 403);
            }

            // Get setup intent
            $setupIntent = $session->setup_intent;
            
            if (!$setupIntent || $setupIntent->status !== 'succeeded') {
                return response()->json([
                    'success' => false,
                    'message' => 'Setup intent not completed',
                ], 400);
            }

            // Get payment method from setup intent
            $paymentMethodId = $setupIntent->payment_method;
            
            if (!$paymentMethodId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method not found in setup intent',
                ], 400);
            }

            // Retrieve payment method details
            $paymentMethod = StripePaymentMethod::retrieve($paymentMethodId);

            // Get card details
            $card = $paymentMethod->card ?? null;
            
            if (!$card) {
                return response()->json([
                    'success' => false,
                    'message' => 'Card information not found',
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Check if payment method already exists
                $existingMethod = BrandPaymentMethod::where('user_id', $user->id)
                    ->where('stripe_payment_method_id', $paymentMethodId)
                    ->where('is_active', true)
                    ->first();

                if ($existingMethod) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'This payment method already exists',
                    ], 400);
                }

                // Map card brand
                $cardBrand = $card->brand ?? 'unknown';
                $cardBrandMap = [
                    'visa' => 'Visa',
                    'mastercard' => 'Mastercard',
                    'amex' => 'American Express',
                    'discover' => 'Discover',
                    'jcb' => 'JCB',
                    'diners' => 'Diners Club',
                ];
                $cardBrandFormatted = $cardBrandMap[strtolower($cardBrand)] ?? ucfirst($cardBrand);

                // Get customer ID from session
                $sessionCustomerId = is_object($session->customer) ? $session->customer->id : $session->customer;
                
                // Update user's stripe_customer_id if not set or different
                if (!$user->stripe_customer_id || $user->stripe_customer_id !== $sessionCustomerId) {
                    $user->update(['stripe_customer_id' => $sessionCustomerId]);
                    Log::info('Updated user stripe_customer_id', [
                        'user_id' => $user->id,
                        'stripe_customer_id' => $sessionCustomerId,
                    ]);
                }

                // Create payment method record
                $paymentMethodRecord = BrandPaymentMethod::create([
                    'user_id' => $user->id,
                    'stripe_customer_id' => $sessionCustomerId,
                    'stripe_payment_method_id' => $paymentMethodId,
                    'stripe_setup_intent_id' => $setupIntent->id,
                    'card_brand' => $cardBrandFormatted,
                    'card_last4' => $card->last4,
                    'card_holder_name' => $paymentMethod->billing_details->name ?? $user->name,
                    'is_default' => !$user->hasDefaultPaymentMethod(), // Set as default if no default exists
                    'is_active' => true,
                ]);

                // If this is set as default, unset other defaults
                if ($paymentMethodRecord->is_default) {
                    BrandPaymentMethod::where('user_id', $user->id)
                        ->where('id', '!=', $paymentMethodRecord->id)
                        ->update(['is_default' => false]);
                }

                // Refresh user to get updated stripe_customer_id
                $user->refresh();

                DB::commit();

                Log::info('Payment method saved successfully from Stripe Checkout', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodRecord->id,
                    'stripe_payment_method_id' => $paymentMethodId,
                    'card_brand' => $cardBrandFormatted,
                    'card_last4' => $card->last4,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Payment method added successfully',
                    'data' => [
                        'id' => $paymentMethodRecord->id,
                        'card_info' => $paymentMethodRecord->formatted_card_info,
                        'card_brand' => $paymentMethodRecord->card_brand,
                        'card_last4' => $paymentMethodRecord->card_last4,
                        'card_holder_name' => $paymentMethodRecord->card_holder_name,
                        'is_default' => $paymentMethodRecord->is_default,
                        'created_at' => $paymentMethodRecord->created_at,
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to handle checkout success', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save payment method. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse card information from card hash
     * This is a simplified implementation for testing
     */
    private function parseCardInfo(string $cardHash, string $cardHolderName): array
    {
        // Extract last 4 digits from card hash (simplified approach)
        // In a real implementation, you would decrypt the card hash
        $last4 = substr($cardHash, -4);
        
        // Determine card brand based on hash pattern (simplified)
        $brand = 'Visa'; // Default brand
        
        if (strpos($cardHash, 'master') !== false) {
            $brand = 'Mastercard';
        } elseif (strpos($cardHash, 'amex') !== false) {
            $brand = 'American Express';
        } elseif (strpos($cardHash, 'elo') !== false) {
            $brand = 'Elo';
        }

        return [
            'brand' => $brand,
            'last4' => $last4,
            'holder_name' => $cardHolderName
        ];
    }
} 
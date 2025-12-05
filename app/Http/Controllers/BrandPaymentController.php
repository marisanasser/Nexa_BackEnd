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
        
        
        $stripeSecret = config('services.stripe.secret');
        if ($stripeSecret) {
            Stripe::setApiKey($stripeSecret);
        }
    }

    
    public function savePaymentMethod(Request $request): JsonResponse
    {
        $user = auth()->user();

        Log::info('Save payment method request', [
            'user_id' => $user->id,
            'request_data' => $request->all()
        ]);

        
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
            
            
            $cardInfo = $this->parseCardInfo($request->card_hash, $request->card_holder_name);

            
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

            
            $paymentMethod = BrandPaymentMethod::create([
                'user_id' => $user->id,
                'card_holder_name' => $request->card_holder_name,
                'card_brand' => $cardInfo['brand'],
                'card_last4' => $cardInfo['last4'],
                'is_default' => $request->is_default ?? false,
                'card_hash' => $request->card_hash,
                'is_active' => true,
            ]);

            
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

        
        if ($paymentMethod->stripe_payment_method_id) {
            $user->update(['stripe_payment_method_id' => $paymentMethod->stripe_payment_method_id]);
            Log::info('Updated user default payment method', [
                'user_id' => $user->id,
                'stripe_payment_method_id' => $paymentMethod->stripe_payment_method_id,
                'payment_method_id' => $paymentMethod->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Default payment method updated successfully',
        ]);
    }

    
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
        
        
        if ($user->brandPaymentMethods()->active()->count() <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete the only payment method. Please add another one first.',
            ], 400);
        }

        $wasDefault = $paymentMethod->is_default;
        $paymentMethodStripeId = $paymentMethod->stripe_payment_method_id;

        $paymentMethod->update(['is_active' => false]);

        
        if ($wasDefault && $user->stripe_payment_method_id === $paymentMethodStripeId) {
            
            $nextDefault = $user->brandPaymentMethods()
                ->where('is_active', true)
                ->where('is_default', true)
                ->first();
            
            if ($nextDefault && $nextDefault->stripe_payment_method_id) {
                $user->update(['stripe_payment_method_id' => $nextDefault->stripe_payment_method_id]);
                Log::info('Updated user default payment method after deletion', [
                    'user_id' => $user->id,
                    'new_stripe_payment_method_id' => $nextDefault->stripe_payment_method_id,
                ]);
            } else {
                
                $user->update(['stripe_payment_method_id' => null]);
                Log::info('Cleared user payment method ID after deletion', [
                    'user_id' => $user->id,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment method deleted successfully',
        ]);
    }

    
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

            
            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

            
            $session = Session::create([
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

    
    public function handleCheckoutSuccess(Request $request): JsonResponse
    {
        Log::info('=== handleCheckoutSuccess method called ===', [
            'timestamp' => now()->toIso8601String(),
            'request_method' => $request->method(),
            'request_url' => $request->fullUrl(),
            'request_headers' => $request->headers->all(),
            'request_body' => $request->all(),
            'request_query' => $request->query(),
            'auth_user_id' => auth()->id(),
        ]);

        try {
            
            $authUser = auth()->user();
            if (!$authUser) {
                Log::warning('User not authenticated in handleCheckoutSuccess', [
                    'auth_id' => auth()->id(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }
            
            Log::info('Authenticated user found', [
                'auth_user_id' => $authUser->id,
                'auth_user_email' => $authUser->email,
                'auth_user_role' => $authUser->role,
            ]);
            
            
            $user = User::find($authUser->id);
            if (!$user) {
                Log::error('User not found in database after authentication', [
                    'auth_user_id' => $authUser->id,
                    'auth_user_email' => $authUser->email,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            Log::info('User loaded from database', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_role' => $user->role,
                'user_stripe_customer_id' => $user->stripe_customer_id,
                'user_stripe_payment_method_id' => $user->stripe_payment_method_id,
            ]);

            if (!$user->isBrand()) {
                Log::warning('Non-brand user attempted to add payment method', [
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can add payment methods',
                ], 403);
            }

            
            $sessionId = $request->input('session_id') ?? $request->query('session_id');
            
            Log::info('Session ID validation', [
                'user_id' => $user->id,
                'session_id_from_input' => $request->input('session_id'),
                'session_id_from_query' => $request->query('session_id'),
                'final_session_id' => $sessionId,
            ]);
            
            if (!$sessionId) {
                Log::error('Session ID missing in request', [
                    'user_id' => $user->id,
                    'request_body' => $request->all(),
                    'request_query' => $request->query(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Session ID is required',
                    'error' => 'Session ID is missing',
                ], 400);
            }

            Log::info('Handling Stripe Checkout Session success', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'session_id_type' => gettype($sessionId),
            ]);

            
            try {
                Log::info('Retrieving Stripe Checkout Session from Stripe API', [
                    'user_id' => $user->id,
                    'session_id' => $sessionId,
                ]);

                $session = Session::retrieve($sessionId, [
                    'expand' => ['setup_intent', 'setup_intent.payment_method'],
                ]);

                Log::info('Stripe Checkout Session retrieved successfully', [
                    'user_id' => $user->id,
                    'session_id' => $session->id,
                    'session_mode' => $session->mode ?? 'N/A',
                    'session_status' => $session->status ?? 'N/A',
                    'has_setup_intent' => !is_null($session->setup_intent),
                    'has_customer' => !is_null($session->customer),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to retrieve Stripe Checkout Session', [
                    'user_id' => $user->id,
                    'session_id' => $sessionId,
                    'error_message' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                ]);
                throw $e;
            }

            
            
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
                    
                    $sessionUserId = $metadata->user_id ?? null;
                    
                    if (!$sessionUserId) {
                        $metadataArray = (array) $metadata;
                        $sessionUserId = $metadataArray['user_id'] ?? null;
                    }
                } elseif (is_array($metadata)) {
                    $sessionUserId = $metadata['user_id'] ?? null;
                }
            }
            
            
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
            
            
            $isValid = false;
            
            
            if ($sessionUserId && (string)$sessionUserId === (string)$user->id) {
                $isValid = true;
                Log::info('Session verified by metadata user_id');
            }
            
            elseif ($sessionCustomerId && $user->stripe_customer_id && $sessionCustomerId === $user->stripe_customer_id) {
                $isValid = true;
                Log::info('Session verified by customer ID');
            }
            
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

            
            $setupIntent = $session->setup_intent;
            
            Log::info('Setup intent retrieved from session', [
                'setup_intent_type' => gettype($setupIntent),
                'setup_intent' => is_string($setupIntent) ? $setupIntent : (is_object($setupIntent) ? $setupIntent->id ?? 'no_id' : 'unknown'),
            ]);
            
            
            if (is_string($setupIntent)) {
                $setupIntentId = $setupIntent;
                Log::info('Retrieving setup intent by ID', ['setup_intent_id' => $setupIntentId]);
                $setupIntent = SetupIntent::retrieve($setupIntentId, [
                    'expand' => ['payment_method']
                ]);
            } elseif (!is_object($setupIntent)) {
                Log::error('Setup intent is neither string nor object', [
                    'type' => gettype($setupIntent),
                    'value' => $setupIntent,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid setup intent format',
                ], 400);
            }
            
            if (!$setupIntent || !is_object($setupIntent)) {
                Log::error('Setup intent is not an object after retrieval', [
                    'setup_intent' => $setupIntent,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Setup intent not found',
                ], 400);
            }
            
            if (!isset($setupIntent->status) || $setupIntent->status !== 'succeeded') {
                Log::warning('Setup intent not succeeded', [
                    'status' => $setupIntent->status ?? 'no_status',
                    'setup_intent_id' => $setupIntent->id ?? 'no_id',
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Setup intent not completed',
                ], 400);
            }

            
            $paymentMethodId = null;
            if (is_object($setupIntent->payment_method)) {
                $paymentMethodId = $setupIntent->payment_method->id ?? null;
            } elseif (is_string($setupIntent->payment_method)) {
                $paymentMethodId = $setupIntent->payment_method;
            }
            
            if (!$paymentMethodId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method not found in setup intent',
                ], 400);
            }

            
            if (is_object($setupIntent->payment_method)) {
                $paymentMethod = $setupIntent->payment_method;
            } else {
                $paymentMethod = StripePaymentMethod::retrieve($paymentMethodId);
            }

            
            $card = $paymentMethod->card ?? null;
            
            Log::info('Retrieved payment method and card details', [
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethodId,
                'has_card' => !is_null($card),
                'card_brand' => $card->brand ?? 'N/A',
                'card_last4' => $card->last4 ?? 'N/A',
                'card_type' => gettype($card),
            ]);
            
            if (!$card) {
                Log::error('Card information not found in payment method', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodId,
                    'payment_method_type' => $paymentMethod->type ?? 'N/A',
                    'payment_method_object' => json_encode($paymentMethod),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Card information not found',
                ], 400);
            }

            Log::info('Starting database transaction for payment method storage', [
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethodId,
                'session_customer_id' => $session->customer ? (is_object($session->customer) ? $session->customer->id : $session->customer) : null,
            ]);

            DB::beginTransaction();

            try {
                
                Log::info('Checking for existing payment method', [
                    'user_id' => $user->id,
                    'stripe_payment_method_id' => $paymentMethodId,
                ]);

                $existingMethod = BrandPaymentMethod::where('user_id', $user->id)
                    ->where('stripe_payment_method_id', $paymentMethodId)
                    ->where('is_active', true)
                    ->first();

                if ($existingMethod) {
                    Log::warning('Payment method already exists for user', [
                        'user_id' => $user->id,
                        'stripe_payment_method_id' => $paymentMethodId,
                        'existing_record_id' => $existingMethod->id,
                        'existing_is_default' => $existingMethod->is_default,
                    ]);
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'This payment method already exists',
                    ], 400);
                }

                Log::info('No existing payment method found, proceeding with creation', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodId,
                ]);

                
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

                
                $sessionCustomerId = is_object($session->customer) ? $session->customer->id : $session->customer;
                
                Log::info('Processing customer ID and user update data', [
                    'user_id' => $user->id,
                    'session_customer_id' => $sessionCustomerId,
                    'user_current_customer_id' => $user->stripe_customer_id,
                    'customer_id_match' => ($user->stripe_customer_id === $sessionCustomerId),
                ]);
                
                
                $userUpdateData = [];
                if (!$user->stripe_customer_id || $user->stripe_customer_id !== $sessionCustomerId) {
                    $userUpdateData['stripe_customer_id'] = $sessionCustomerId;
                    Log::info('Updating user stripe_customer_id', [
                        'user_id' => $user->id,
                        'old_stripe_customer_id' => $user->stripe_customer_id,
                        'new_stripe_customer_id' => $sessionCustomerId,
                    ]);
                }

                
                $hasDefault = $user->hasDefaultPaymentMethod();
                Log::info('Checking default payment method status', [
                    'user_id' => $user->id,
                    'has_default_payment_method' => $hasDefault,
                ]);

                
                $paymentMethodData = [
                    'user_id' => $user->id,
                    'stripe_customer_id' => $sessionCustomerId,
                    'stripe_payment_method_id' => $paymentMethodId,
                    'stripe_setup_intent_id' => $setupIntent->id,
                    'card_brand' => $cardBrandFormatted,
                    'card_last4' => $card->last4,
                    'card_holder_name' => $paymentMethod->billing_details->name ?? $user->name,
                    'is_default' => !$hasDefault,
                    'is_active' => true,
                ];

                Log::info('Creating BrandPaymentMethod record', [
                    'user_id' => $user->id,
                    'payment_method_data' => $paymentMethodData,
                ]);

                
                $paymentMethodRecord = BrandPaymentMethod::create($paymentMethodData);

                Log::info('BrandPaymentMethod record created successfully', [
                    'user_id' => $user->id,
                    'payment_method_record_id' => $paymentMethodRecord->id,
                    'stripe_payment_method_id' => $paymentMethodRecord->stripe_payment_method_id,
                    'is_default' => $paymentMethodRecord->is_default,
                    'card_brand' => $paymentMethodRecord->card_brand,
                    'card_last4' => $paymentMethodRecord->card_last4,
                ]);

                
                if ($paymentMethodRecord->is_default) {
                    Log::info('New payment method is default, unsetting other defaults', [
                        'user_id' => $user->id,
                        'new_default_id' => $paymentMethodRecord->id,
                    ]);

                    $unsetCount = BrandPaymentMethod::where('user_id', $user->id)
                        ->where('id', '!=', $paymentMethodRecord->id)
                        ->update(['is_default' => false]);

                    Log::info('Unset other default payment methods', [
                        'user_id' => $user->id,
                        'unset_count' => $unsetCount,
                    ]);
                }

                
                
                $userUpdateData['stripe_payment_method_id'] = $paymentMethodId;
                
                
                if (!$user->id || !is_numeric($user->id)) {
                    Log::error('Invalid user ID for payment method update', [
                        'user_id' => $user->id,
                        'user_type' => gettype($user->id),
                    ]);
                    throw new \Exception('Invalid user ID');
                }
                
                
                if (empty($paymentMethodId) || !is_string($paymentMethodId)) {
                    Log::error('Invalid payment method ID', [
                        'payment_method_id' => $paymentMethodId,
                        'payment_method_id_type' => gettype($paymentMethodId),
                    ]);
                    throw new \Exception('Invalid payment method ID');
                }
                
                Log::info('Preparing to update user with payment method ID', [
                    'user_id' => $user->id,
                    'stripe_payment_method_id' => $paymentMethodId,
                    'is_default' => $paymentMethodRecord->is_default,
                    'previous_payment_method_id' => $user->stripe_payment_method_id,
                    'userUpdateData_keys' => array_keys($userUpdateData),
                    'userUpdateData' => $userUpdateData,
                ]);

                
                
                
                Log::info('Verifying user exists in database before update', [
                    'user_id' => $user->id,
                ]);

                $userExists = DB::table('users')->where('id', $user->id)->exists();
                if (!$userExists) {
                    Log::error('User does not exist in database', [
                        'user_id' => $user->id,
                        'user_email' => $user->email ?? 'N/A',
                    ]);
                    throw new \Exception('User not found in database');
                }

                Log::info('User verified, proceeding with users table update', [
                    'user_id' => $user->id,
                    'update_data' => $userUpdateData,
                ]);
                
                
                $updatedRows = DB::table('users')
                    ->where('id', $user->id)
                    ->update($userUpdateData);

                Log::info('Users table update executed', [
                    'user_id' => $user->id,
                    'updated_rows' => $updatedRows,
                    'update_data' => $userUpdateData,
                ]);
                
                
                if (config('app.debug')) {
                    Log::debug('Direct DB update executed', [
                        'user_id' => $user->id,
                        'updated_rows' => $updatedRows,
                        'update_data' => $userUpdateData,
                    ]);
                }
                
                
                $actualStoredId = DB::table('users')
                    ->where('id', $user->id)
                    ->value('stripe_payment_method_id');
                
                Log::info('Updated user billing method information via direct DB update', [
                    'user_id' => $user->id,
                    'updated_fields' => array_keys($userUpdateData),
                    'updated_rows' => $updatedRows,
                    'expected_payment_method_id' => $paymentMethodId,
                    'actual_stored_payment_method_id' => $actualStoredId,
                    'update_successful' => ($actualStoredId === $paymentMethodId),
                ]);
                
                
                if ($actualStoredId !== $paymentMethodId) {
                    Log::warning('Direct DB update failed, trying Eloquent update', [
                        'user_id' => $user->id,
                        'expected' => $paymentMethodId,
                        'actual' => $actualStoredId,
                    ]);
                    
                    
                    $user->refresh();
                    $user->stripe_payment_method_id = $paymentMethodId;
                    if (isset($userUpdateData['stripe_customer_id'])) {
                        $user->stripe_customer_id = $userUpdateData['stripe_customer_id'];
                    }
                    $saved = $user->save();
                    
                    
                    $user->refresh();
                    $actualStoredId = $user->stripe_payment_method_id;
                    
                    Log::info('Eloquent fallback save completed', [
                        'user_id' => $user->id,
                        'stripe_payment_method_id' => $actualStoredId,
                        'save_result' => $saved,
                        'success' => ($actualStoredId === $paymentMethodId),
                    ]);
                    
                    
                    if ($actualStoredId !== $paymentMethodId) {
                        Log::error('Both direct DB and Eloquent updates failed, attempting final direct DB update', [
                            'user_id' => $user->id,
                            'expected' => $paymentMethodId,
                            'actual' => $actualStoredId,
                        ]);
                        
                        $finalUpdate = DB::table('users')
                            ->where('id', $user->id)
                            ->update(['stripe_payment_method_id' => $paymentMethodId]);
                        
                        $finalVerification = DB::table('users')
                            ->where('id', $user->id)
                            ->value('stripe_payment_method_id');
                        
                        Log::info('Final direct DB update attempt', [
                            'user_id' => $user->id,
                            'updated_rows' => $finalUpdate,
                            'final_stored_id' => $finalVerification,
                            'success' => ($finalVerification === $paymentMethodId),
                        ]);
                    }
                } else {
                    
                    $user->refresh();
                }

                DB::commit();

                
                
                $postCommitVerification = DB::table('users')
                    ->where('id', $user->id)
                    ->value('stripe_payment_method_id');
                
                
                if ($postCommitVerification !== $paymentMethodId) {
                    Log::warning('Payment method ID not found after commit, updating now', [
                        'user_id' => $user->id,
                        'expected' => $paymentMethodId,
                        'actual' => $postCommitVerification,
                    ]);
                    
                    
                    $forceUpdate = DB::table('users')
                        ->where('id', $user->id)
                        ->update(['stripe_payment_method_id' => $paymentMethodId]);
                    
                    
                    $finalCheck = DB::table('users')
                        ->where('id', $user->id)
                        ->value('stripe_payment_method_id');
                    
                    if ($finalCheck !== $paymentMethodId) {
                        
                        $user->refresh();
                        $user->stripe_payment_method_id = $paymentMethodId;
                        $saved = $user->save();
                        $user->refresh();
                        
                        Log::error('CRITICAL: Payment method ID still not stored after all attempts', [
                            'user_id' => $user->id,
                            'expected' => $paymentMethodId,
                            'final_value' => $user->stripe_payment_method_id,
                            'eloquent_save_result' => $saved,
                        ]);
                    } else {
                        Log::info('Payment method ID successfully stored after force update', [
                            'user_id' => $user->id,
                            'payment_method_id' => $paymentMethodId,
                        ]);
                    }
                }
                
                
                $finalVerification = DB::table('users')
                    ->where('id', $user->id)
                    ->value('stripe_payment_method_id');
                
                Log::info('Payment method saved successfully from Stripe Checkout', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodRecord->id,
                    'stripe_payment_method_id' => $paymentMethodId,
                    'final_verification' => $finalVerification,
                    'verification_match' => ($finalVerification === $paymentMethodId),
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
                Log::error('Exception occurred during payment method storage transaction', [
                    'user_id' => $user->id ?? null,
                    'payment_method_id' => $paymentMethodId ?? null,
                    'error_message' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'error_trace' => $e->getTraceAsString(),
                    'transaction_rolled_back' => true,
                ]);
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to handle checkout success - Outer catch block', [
                'user_id' => auth()->id(),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_code' => $e->getCode(),
                'error_trace' => $e->getTraceAsString(),
                'request_data' => [
                    'session_id' => $request->input('session_id') ?? $request->query('session_id'),
                    'all_input' => $request->all(),
                ],
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

    
    public function createFundingCheckout(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can create funding checkout sessions',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:10|max:100000',
                'creator_id' => 'required|integer|exists:users,id',
                'chat_room_id' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            
            $chatRoom = \App\Models\ChatRoom::where('room_id', $request->chat_room_id)->first();
            $campaignId = $chatRoom ? $chatRoom->campaign_id : null;

            $stripeSecret = config('services.stripe.secret');
            if (!$stripeSecret) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe is not configured',
                ], 503);
            }

            Stripe::setApiKey($stripeSecret);

            
            $customerId = $user->stripe_customer_id;
            
            if (!$customerId) {
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
            } else {
                try {
                    Customer::retrieve($customerId);
                } catch (\Exception $e) {
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

            $frontendUrl = config('app.frontend_url', 'http://localhost:5000');
            $amount = $request->amount;

            
            $checkoutSession = Session::create([
                'customer' => $customerId,
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'locale' => 'pt-BR',
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'brl',
                        'product_data' => [
                            'name' => 'Platform Funding for Offer',
                            'description' => 'Fund your platform account to send offers',
                        ],
                        'unit_amount' => (int) round($amount * 100), 
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => $frontendUrl . '/brand?component=Pagamentos&offer_funding_success=true&session_id={CHECKOUT_SESSION_ID}&creator_id=' . $request->creator_id . '&chat_room_id=' . urlencode($request->chat_room_id) . '&amount=' . $amount,
                'cancel_url' => $frontendUrl . '/brand?component=Pagamentos&offer_funding_canceled=true&creator_id=' . $request->creator_id . '&chat_room_id=' . urlencode($request->chat_room_id),
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'type' => 'offer_funding',
                    'creator_id' => (string) $request->creator_id,
                    'chat_room_id' => $request->chat_room_id,
                    'amount' => (string) $amount,
                    'campaign_id' => $campaignId ? (string) $campaignId : null,
                ],
            ]);

            Log::info('Platform funding checkout session created', [
                'session_id' => $checkoutSession->id,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'creator_id' => $request->creator_id,
                'amount' => $amount,
            ]);

            return response()->json([
                'success' => true,
                'url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create platform funding checkout session', [
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

    
    private function parseCardInfo(string $cardHash, string $cardHolderName): array
    {
        
        
        $last4 = substr($cardHash, -4);
        
        
        $brand = 'Visa'; 
        
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

    
    public function handleOfferFundingSuccess(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can access this endpoint',
                ], 403);
            }

            $sessionId = $request->input('session_id') ?? $request->query('session_id');
            
            if (!$sessionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session ID is required',
                ], 400);
            }

            Log::info('Handling offer funding success callback', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
            ]);

            Stripe::setApiKey(config('services.stripe.secret'));
            
            
            $session = Session::retrieve($sessionId, [
                'expand' => ['payment_intent', 'payment_intent.charges.data.payment_method_details'],
            ]);

            
            $metadata = $session->metadata ?? null;
            $sessionUserId = null;
            
            if (is_array($metadata)) {
                $sessionUserId = $metadata['user_id'] ?? null;
            } elseif (is_object($metadata)) {
                $sessionUserId = $metadata->user_id ?? null;
            }

            if (!$sessionUserId || (string)$sessionUserId !== (string)$user->id) {
                Log::warning('Session does not belong to user', [
                    'session_user_id' => $sessionUserId,
                    'authenticated_user_id' => $user->id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid session',
                ], 403);
            }

            
            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not completed',
                ], 400);
            }

            
            $amount = null;
            if (is_array($metadata)) {
                $amount = $metadata['amount'] ?? null;
            } elseif (is_object($metadata)) {
                $amount = $metadata->amount ?? null;
            }
            
            $transactionAmount = $amount ? (float) $amount : ($session->amount_total / 100);

            
            $paymentIntentId = $session->payment_intent;
            if (is_object($paymentIntentId)) {
                $paymentIntentId = $paymentIntentId->id;
            }

            if (!$paymentIntentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment intent not found',
                ], 400);
            }

            
            $existingTransaction = \App\Models\Transaction::where('stripe_payment_intent_id', $paymentIntentId)
                ->where('user_id', $user->id)
                ->first();

            if ($existingTransaction) {
                Log::info('Offer funding transaction already exists', [
                    'transaction_id' => $existingTransaction->id,
                    'user_id' => $user->id,
                ]);
                
                
                $this->createOfferFundingNotification($user->id, $transactionAmount, $metadata, $existingTransaction->id);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Transaction already processed',
                    'data' => [
                        'transaction_id' => $existingTransaction->id,
                        'amount' => $transactionAmount,
                    ],
                ]);
            }

            
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId, [
                'expand' => ['charges.data.payment_method_details'],
            ]);

            if ($paymentIntent->status !== 'succeeded') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment intent not succeeded',
                ], 400);
            }

            
            $charge = null;
            $cardBrand = null;
            $cardLast4 = null;
            $cardHolderName = null;

            if (!empty($paymentIntent->charges->data)) {
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
                
                $transaction = \App\Models\Transaction::create([
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
                        'metadata' => $metadata,
                    ],
                    'paid_at' => now(),
                ]);

                
                $campaignId = null;
                if (is_array($metadata)) {
                    $campaignId = $metadata['campaign_id'] ?? null;
                } elseif (is_object($metadata)) {
                    $campaignId = $metadata->campaign_id ?? null;
                }

                if ($campaignId) {
                    try {
                        $campaign = \App\Models\Campaign::find($campaignId);
                        if ($campaign) {
                            $campaign->update([
                                'final_price' => $transactionAmount,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to update campaign final_price', [
                            'campaign_id' => $campaignId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                DB::commit();

                Log::info('Offer funding transaction created successfully', [
                    'transaction_id' => $transaction->id,
                    'user_id' => $user->id,
                    'amount' => $transactionAmount,
                ]);

                
                $this->createOfferFundingNotification($user->id, $transactionAmount, $metadata, $transaction->id);

                return response()->json([
                    'success' => true,
                    'message' => 'Platform funding processed successfully',
                    'data' => [
                        'transaction_id' => $transaction->id,
                        'amount' => $transactionAmount,
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to handle offer funding success', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process funding. Please try again.',
            ], 500);
        }
    }

    
    
    public function checkFundingStatus(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can access this endpoint',
                ], 403);
            }

            
            $hasFunding = \App\Models\Transaction::where('user_id', $user->id)
                ->where('status', 'paid')
                ->where(function($query) {
                    $query->whereJsonContains('payment_data->type', 'offer_funding')
                          ->orWhereJsonContains('payment_data->type', 'platform_funding');
                })
                ->exists();

            
            $brandBalance = \App\Models\BrandBalance::where('brand_id', $user->id)->first();
            $hasBalance = $brandBalance && $brandBalance->total_funded > 0;

            $hasFunded = $hasFunding || $hasBalance;

            return response()->json([
                'success' => true,
                'data' => [
                    'has_funded' => $hasFunded,
                    'has_funding_transactions' => $hasFunding,
                    'has_balance' => $hasBalance,
                    'available_balance' => $brandBalance ? $brandBalance->available_balance : 0,
                    'total_funded' => $brandBalance ? $brandBalance->total_funded : 0,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to check brand funding status', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check funding status',
            ], 500);
        }
    }

    private function createOfferFundingNotification($userId, $amount, $metadata, $transactionId): void
    {
        try {
            $fundingData = [
                'transaction_id' => $transactionId,
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
            
            $notification = \App\Models\Notification::createPlatformFundingSuccess(
                $userId,
                $amount,
                $fundingData
            );
            
            
            \App\Services\NotificationService::sendSocketNotification($userId, $notification);
            
            Log::info('Platform funding success notification created', [
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'amount' => $amount,
            ]);
        } catch (\Exception $e) {
            
            Log::error('Failed to create platform funding success notification', [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }
} 
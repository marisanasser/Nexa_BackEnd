<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Account;
use Stripe\PaymentMethod;
use Stripe\Customer;
use Stripe\SetupIntent;
use Stripe\Exception\CardException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;

class StripeController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create a Stripe Connect account for the user
     */
    public function createAccount(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|string|in:individual,business',
            'country' => 'required|string|size:2',
            'email' => 'required|email',
            'business_type' => 'required|string|in:individual,company',
            'card' => 'required|array',
            'card.number' => 'required|string|min:13|max:19',
            'card.exp_month' => 'required|integer|min:1|max:12',
            'card.exp_year' => 'required|integer|min:2024|max:2030',
            'card.cvc' => 'required|string|min:3|max:4',
            'individual' => 'required_if:type,individual|array',
            'company' => 'required_if:type,business|array',
        ]);

        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Check if user already has a Stripe account
            if ($user->stripe_account_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has a Stripe account'
                ], 422);
            }

            DB::beginTransaction();

            try {
                // First, create a payment method to verify the card
                $paymentMethod = PaymentMethod::create([
                    'type' => 'card',
                    'card' => [
                        'number' => $request->card['number'],
                        'exp_month' => $request->card['exp_month'],
                        'exp_year' => $request->card['exp_year'],
                        'cvc' => $request->card['cvc'],
                    ],
                ]);

                // Prepare account creation data
                $accountData = [
                    'type' => $request->type === 'individual' ? 'express' : 'standard',
                    'country' => $request->country,
                    'email' => $request->email,
                    'business_type' => $request->business_type,
                ];

                // Add individual information
                if ($request->type === 'individual' && $request->individual) {
                    $accountData['individual'] = [
                        'first_name' => $request->individual['first_name'] ?? '',
                        'last_name' => $request->individual['last_name'] ?? '',
                        'email' => $request->individual['email'] ?? $request->email,
                        'phone' => $request->individual['phone'] ?? '',
                        'address' => $request->individual['address'] ?? [],
                        'dob' => $request->individual['dob'] ?? [],
                    ];
                }

                // Add company information
                if ($request->type === 'business' && $request->company) {
                    $accountData['company'] = [
                        'name' => $request->company['name'] ?? '',
                        'structure' => $request->company['structure'] ?? '',
                        'address' => $request->company['address'] ?? [],
                    ];
                }

                // Create Stripe account
                $stripeAccount = Account::create($accountData);

                // Update user with Stripe account ID and payment method ID
                $user->update([
                    'stripe_account_id' => $stripeAccount->id,
                    'stripe_payment_method_id' => $paymentMethod->id,
                    'stripe_verification_status' => 'pending',
                ]);

                DB::commit();

                Log::info('Stripe account created successfully', [
                    'user_id' => $user->id,
                    'stripe_account_id' => $stripeAccount->id,
                    'account_type' => $request->type,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Stripe account created successfully',
                    'stripe_account_id' => $stripeAccount->id,
                    'stripe_payment_method_id' => $paymentMethod->id,
                    'account_type' => $stripeAccount->type,
                    'verification_status' => 'pending',
                    'card_last4' => $paymentMethod->card->last4,
                    'card_brand' => $paymentMethod->card->brand,
                ]);

            } catch (CardException $e) {
                Log::error('Stripe card error during account creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'decline_code' => $e->getDeclineCode(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Account creation failed. Please check your information.',
                    'error' => $e->getMessage(),
                ], 400);

            } catch (RateLimitException $e) {
                Log::error('Stripe rate limit error during account creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please try again later.',
                ], 429);

            } catch (InvalidRequestException $e) {
                Log::error('Stripe invalid request error during account creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid account information. Please check your details.',
                    'error' => $e->getMessage(),
                ], 400);

            } catch (AuthenticationException $e) {
                Log::error('Stripe authentication error during account creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment service authentication error. Please contact support.',
                ], 500);

            } catch (ApiConnectionException $e) {
                Log::error('Stripe API connection error during account creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment service is temporarily unavailable. Please try again later.',
                ], 503);

            } catch (ApiErrorException $e) {
                Log::error('Stripe API error during account creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Account creation error. Please try again.',
                    'error' => $e->getMessage(),
                ], 500);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Unexpected error during Stripe account creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'An unexpected error occurred. Please try again.'
                ], 500);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Stripe account creation error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating your Stripe account. Please try again.'
            ], 500);
        }
    }

    /**
     * Get Stripe account status and verification requirements
     */
    public function getAccountStatus(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            if (!$user->stripe_account_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Stripe account found'
                ], 404);
            }

            // Retrieve account from Stripe
            $stripeAccount = Account::retrieve($user->stripe_account_id);

            return response()->json([
                'success' => true,
                'account' => [
                    'id' => $stripeAccount->id,
                    'type' => $stripeAccount->type,
                    'country' => $stripeAccount->country,
                    'email' => $stripeAccount->email,
                    'charges_enabled' => $stripeAccount->charges_enabled,
                    'payouts_enabled' => $stripeAccount->payouts_enabled,
                    'details_submitted' => $stripeAccount->details_submitted,
                    'requirements' => $stripeAccount->requirements,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving Stripe account status', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve account status'
            ], 500);
        }
    }

    /**
     * Verify payment method
     */
    public function verifyPaymentMethod(Request $request): JsonResponse
    {
        $request->validate([
            'payment_method_id' => 'required|string',
        ]);

        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Retrieve payment method from Stripe
            $paymentMethod = PaymentMethod::retrieve($request->payment_method_id);

            // Check if payment method belongs to user or is valid
            if (!$paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method not found'
                ], 404);
            }

            // Update user with verified payment method
            $user->update([
                'stripe_payment_method_id' => $paymentMethod->id,
                'stripe_verification_status' => 'verified',
            ]);

            Log::info('Payment method verified successfully', [
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethod->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment method verified successfully',
                'payment_method' => [
                    'id' => $paymentMethod->id,
                    'type' => $paymentMethod->type,
                    'card' => [
                        'brand' => $paymentMethod->card->brand,
                        'last4' => $paymentMethod->card->last4,
                        'exp_month' => $paymentMethod->card->exp_month,
                        'exp_year' => $paymentMethod->card->exp_year,
                    ],
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error verifying payment method', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify payment method'
            ], 500);
        }
    }

    /**
     * Create account link for onboarding
     */
    public function createAccountLink(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            if (!$user->stripe_account_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Stripe account found'
                ], 404);
            }

            // Create account link for onboarding
            $accountLink = \Stripe\AccountLink::create([
                'account' => $user->stripe_account_id,
                'refresh_url' => config('app.frontend_url') . '/creator/verification?refresh=true',
                'return_url' => config('app.frontend_url') . '/creator/dashboard',
                'type' => 'account_onboarding',
            ]);

            return response()->json([
                'success' => true,
                'url' => $accountLink->url,
                'expires_at' => $accountLink->expires_at,
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating Stripe account link', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create account link'
            ], 500);
        }
    }

    /**
     * Create SetupIntent for student verification
     */
    public function setupIntent(Request $request): JsonResponse
    {
        try {
            Log::info('SetupIntent request received', [
                'username' => $request->username,
                'email' => $request->email,
                'user_id' => auth()->id(),
            ]);

            // Validate input
            $request->validate([
                'username' => 'required|string|min:3|max:50',
                'email' => 'required|email|max:255',
            ]);

        // Rate limiting check
        $rateLimitKey = 'setup_intent_' . $request->ip();
        $rateLimitCount = cache()->get($rateLimitKey, 0);
        
        if ($rateLimitCount >= 10) { // Max 10 requests per hour per IP
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.'
            ], 429);
        }

            // Increment rate limit counter
            cache()->put($rateLimitKey, $rateLimitCount + 1, 3600); // 1 hour

            // Check if Stripe is configured
            $stripeSecret = config('services.stripe.secret');
            $stripePublishableKey = config('services.stripe.publishable_key');
            
            Log::info('Stripe configuration check', [
                'has_secret' => !empty($stripeSecret),
                'has_publishable' => !empty($stripePublishableKey),
                'secret_length' => $stripeSecret ? strlen($stripeSecret) : 0,
                'publishable_length' => $stripePublishableKey ? strlen($stripePublishableKey) : 0,
            ]);
            
            if (!$stripeSecret) {
                Log::error('Stripe secret key not configured', [
                    'config_value' => config('services.stripe.secret'),
                    'env_value' => env('STRIPE_SECRET'),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe secret key not configured. Please set STRIPE_SECRET in your .env file.',
                    'debug' => 'Check your .env file for STRIPE_SECRET variable'
                ], 500);
            }

            if (!$stripePublishableKey) {
                Log::error('Stripe publishable key not configured', [
                    'config_value' => config('services.stripe.publishable_key'),
                    'env_value' => env('STRIPE_PUBLISHABLE_KEY'),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe publishable key not configured. Please set STRIPE_PUBLISHABLE_KEY in your .env file.',
                    'debug' => 'Check your .env file for STRIPE_PUBLISHABLE_KEY variable'
                ], 500);
            }

            try {
                $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            DB::beginTransaction();

            try {
                // Check if user already has a Stripe customer ID
                $customer = null;
                if ($user->stripe_customer_id) {
                    // Retrieve existing customer
                    $customer = Customer::retrieve($user->stripe_customer_id);
                    
                    // Update customer email if it has changed
                    if ($customer->email !== $request->email) {
                        $customer = Customer::update($user->stripe_customer_id, [
                            'email' => $request->email,
                            'metadata' => [
                                'username' => $request->username,
                                'user_id' => $user->id,
                            ]
                        ]);
                    }
                } else {
                    // Create new Stripe customer
                    $customer = Customer::create([
                        'email' => $request->email,
                        'metadata' => [
                            'username' => $request->username,
                            'user_id' => $user->id,
                        ]
                    ]);

                    // Update user with Stripe customer ID
                    $user->update([
                        'stripe_customer_id' => $customer->id,
                    ]);
                }

                // Create SetupIntent
                $setupIntent = SetupIntent::create([
                    'customer' => $customer->id,
                    'payment_method_types' => ['card'],
                    'usage' => 'off_session', // For future payments
                ]);

                DB::commit();

                Log::info('SetupIntent created successfully', [
                    'user_id' => $user->id,
                    'customer_id' => $customer->id,
                    'setup_intent_id' => $setupIntent->id,
                ]);

                return response()->json([
                    'success' => true,
                    'client_secret' => $setupIntent->client_secret,
                    'customer_id' => $customer->id,
                    'setup_intent_id' => $setupIntent->id,
                    'public_key' => config('services.stripe.publishable_key'),
                ]);

            } catch (CardException $e) {
                Log::error('Stripe card error during SetupIntent creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'decline_code' => $e->getDeclineCode(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Card error. Please check your card information.',
                    'error' => $e->getMessage(),
                ], 400);

            } catch (RateLimitException $e) {
                Log::error('Stripe rate limit error during SetupIntent creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests to payment service. Please try again later.',
                ], 429);

            } catch (InvalidRequestException $e) {
                Log::error('Stripe invalid request error during SetupIntent creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid request. Please check your information.',
                    'error' => $e->getMessage(),
                ], 400);

            } catch (AuthenticationException $e) {
                Log::error('Stripe authentication error during SetupIntent creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment service authentication error. Please contact support.',
                ], 500);

            } catch (ApiConnectionException $e) {
                Log::error('Stripe API connection error during SetupIntent creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment service is temporarily unavailable. Please try again later.',
                ], 503);

            } catch (ApiErrorException $e) {
                Log::error('Stripe API error during SetupIntent creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment service error. Please try again.',
                    'error' => $e->getMessage(),
                ], 500);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Unexpected error during SetupIntent creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'An unexpected error occurred. Please try again.'
                ], 500);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SetupIntent creation error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating SetupIntent. Please try again.'
            ], 500);
        }

        } catch (\Exception $e) {
            Log::error('SetupIntent creation error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating SetupIntent. Please try again.'
            ], 500);
        }
    }

    /**
     * Check Stripe configuration status
     */
    public function checkConfiguration(): JsonResponse
    {
        $stripeSecret = config('services.stripe.secret');
        $stripePublishableKey = config('services.stripe.publishable_key');
        
        return response()->json([
            'success' => true,
            'configuration' => [
                'stripe_secret_configured' => !empty($stripeSecret),
                'stripe_publishable_configured' => !empty($stripePublishableKey),
                'environment' => app()->environment(),
                'required_env_vars' => [
                    'STRIPE_SECRET' => !empty($stripeSecret) ? 'Set' : 'Missing',
                    'STRIPE_PUBLISHABLE_KEY' => !empty($stripePublishableKey) ? 'Set' : 'Missing',
                ]
            ],
            'instructions' => [
                '1' => 'Create a .env file in the Backend directory',
                '2' => 'Add STRIPE_SECRET=sk_test_your_key_here',
                '3' => 'Add STRIPE_PUBLISHABLE_KEY=pk_test_your_key_here',
                '4' => 'Restart your Laravel server'
            ]
        ]);
    }
}

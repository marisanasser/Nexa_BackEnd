<?php

namespace App\Http\Controllers;

use App\Models\User;
use Aws\Sns\SnsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\Customer;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\Stripe;

class StripeController extends Controller
{
    protected $sns;

    public function __construct()
    {
        // Initialize Stripe from config (uses environment variables)
        $stripeKey = config('services.stripe.secret');
        if ($stripeKey) {
            Stripe::setApiKey($stripeKey);
        }
        
        $awsKey = env('AWS_ACCESS_KEY_ID');
        $awsSecret = env('AWS_SECRET_ACCESS_KEY');
        if (! empty($awsKey) && ! empty($awsSecret)) {
            try {
                $this->sns = new SnsClient([
                    'version' => 'latest',
                    'region' => env('AWS_DEFAULT_REGION', 'sa-east-1'),
                    'credentials' => [
                        'key' => $awsKey,
                        'secret' => $awsSecret,
                    ],
                ]);
            } catch (\Aws\Exception\AwsException $e) {
                Log::warning('Failed to initialize SNS client', ['error' => $e->getMessage()]);
                $this->sns = null;
            } catch (\Exception $e) {
                Log::warning('Failed to initialize SNS client', ['error' => $e->getMessage()]);
                $this->sns = null;
            }
        } else {
            $this->sns = null;
        }
    }

    public function createAccount(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            assert($user instanceof User);

            Log::info('Stripe account creation request started', [
                'user_id' => $user?->id,
                'has_user' => ! is_null($user),
                'request_type' => $request->type ?? 'not_provided',
                'request_country' => $request->country ?? 'not_provided',
            ]);

            if (! $user) {
                Log::warning('Stripe account creation failed: User not authenticated');

                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if ($user->stripe_account_id) {
                Log::warning('Stripe account creation failed: User already has account', [
                    'user_id' => $user->id,
                    'existing_stripe_account_id' => $user->stripe_account_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'User already has a Stripe account',
                ], 422);
            }

            DB::beginTransaction();

            try {
                Log::info('Creating Stripe payment method for account creation', [
                    'user_id' => $user->id,
                    'card_exp_month' => $request->card['exp_month'] ?? 'not_provided',
                    'card_exp_year' => $request->card['exp_year'] ?? 'not_provided',
                    'card_last4_expected' => substr($request->card['number'] ?? '', -4),
                ]);

                $paymentMethod = PaymentMethod::create([
                    'type' => 'card',
                    'card' => [
                        'number' => $request->card['number'],
                        'exp_month' => $request->card['exp_month'],
                        'exp_year' => $request->card['exp_year'],
                        'cvc' => $request->card['cvc'],
                    ],
                ]);

                Log::info('Stripe payment method created successfully', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethod->id,
                    'card_brand' => $paymentMethod->card->brand ?? 'unknown',
                    'card_last4' => $paymentMethod->card->last4 ?? 'unknown',
                ]);

                $accountData = [
                    'type' => $request->type === 'individual' ? 'express' : 'standard',
                    'country' => $request->country,
                    'email' => $request->email,
                    'business_type' => $request->business_type,
                ];

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

                if ($request->type === 'business' && $request->company) {
                    $accountData['company'] = [
                        'name' => $request->company['name'] ?? '',
                        'structure' => $request->company['structure'] ?? '',
                        'address' => $request->company['address'] ?? [],
                    ];
                }

                Log::info('Creating Stripe Connect account', [
                    'user_id' => $user->id,
                    'account_type' => $accountData['type'],
                    'country' => $accountData['country'],
                    'email' => $accountData['email'],
                    'business_type' => $accountData['business_type'],
                ]);

                $stripeAccount = Account::create($accountData);

                Log::info('Stripe Connect account created successfully', [
                    'user_id' => $user->id,
                    'stripe_account_id' => $stripeAccount->id,
                    'account_type' => $stripeAccount->type,
                    'charges_enabled' => $stripeAccount->charges_enabled ?? false,
                    'payouts_enabled' => $stripeAccount->payouts_enabled ?? false,
                ]);

                $updatedRows = DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'stripe_account_id' => $stripeAccount->id,
                        'stripe_payment_method_id' => $paymentMethod->id,
                        'stripe_verification_status' => 'pending',
                    ]);

                $user->refresh();

                Log::info('Updated user with Stripe account and payment method via direct DB update', [
                    'user_id' => $user->id,
                    'updated_rows' => $updatedRows,
                    'stripe_account_id' => $user->stripe_account_id,
                    'stripe_payment_method_id' => $user->stripe_payment_method_id,
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
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'An unexpected error occurred. Please try again.',
                ], 500);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Stripe account creation error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating your Stripe account. Please try again.',
            ], 500);
        }
    }

    public function getAccountStatus(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            assert($user instanceof User);

            Log::info('Stripe account status check requested', [
                'user_id' => $user?->id,
                'has_user' => ! is_null($user),
                'has_stripe_account_id' => ! is_null($user?->stripe_account_id),
            ]);

            if (! $user) {
                Log::warning('Stripe account status check failed: User not authenticated');

                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if (! $user->stripe_account_id) {
                Log::info('Stripe account status: No account found', [
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'has_account' => false,
                    'message' => 'No Stripe account found',
                ]);
            }

            Log::info('Retrieving Stripe account status from Stripe API', [
                'user_id' => $user->id,
                'stripe_account_id' => $user->stripe_account_id,
            ]);

            $stripeAccount = Account::retrieve($user->stripe_account_id);

            Log::info('Stripe account status retrieved', [
                'user_id' => $user->id,
                'stripe_account_id' => $stripeAccount->id,
                'charges_enabled' => $stripeAccount->charges_enabled ?? false,
                'payouts_enabled' => $stripeAccount->payouts_enabled ?? false,
                'details_submitted' => $stripeAccount->details_submitted ?? false,
                'verification_status' => $stripeAccount->charges_enabled && $stripeAccount->payouts_enabled ? 'enabled' : 'pending',
            ]);

            return response()->json([
                'success' => true,
                'has_account' => true,
                'account_id' => $stripeAccount->id,
                'verification_status' => $stripeAccount->charges_enabled && $stripeAccount->payouts_enabled ? 'enabled' : 'pending',
                'charges_enabled' => $stripeAccount->charges_enabled,
                'payouts_enabled' => $stripeAccount->payouts_enabled,
                'details_submitted' => $stripeAccount->details_submitted,
                'requirements' => $stripeAccount->requirements,
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving Stripe account status', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve account status',
            ], 500);
        }
    }

    public function verifyPaymentMethod(Request $request): JsonResponse
    {
        $request->validate([
            'payment_method_id' => 'required|string',
        ]);

        try {
            $user = auth()->user();
            assert($user instanceof User);

            Log::info('Payment method verification requested', [
                'user_id' => $user?->id,
                'payment_method_id' => $request->payment_method_id,
            ]);

            if (! $user) {
                Log::warning('Payment method verification failed: User not authenticated');

                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            Log::info('Retrieving payment method from Stripe', [
                'user_id' => $user->id,
                'payment_method_id' => $request->payment_method_id,
            ]);

            $paymentMethod = PaymentMethod::retrieve($request->payment_method_id);

            Log::info('Payment method retrieved from Stripe', [
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethod->id,
                'type' => $paymentMethod->type ?? 'unknown',
                'card_brand' => $paymentMethod->card->brand ?? 'unknown',
                'card_last4' => $paymentMethod->card->last4 ?? 'unknown',
            ]);

            if (! $paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method not found',
                ], 404);
            }

            $updatedRows = DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'stripe_payment_method_id' => $paymentMethod->id,
                    'stripe_verification_status' => 'verified',
                ]);

            $actualStoredId = DB::table('users')
                ->where('id', $user->id)
                ->value('stripe_payment_method_id');

            if ($actualStoredId !== $paymentMethod->id) {

                $user->refresh();
                $user->stripe_payment_method_id = $paymentMethod->id;
                $user->stripe_verification_status = 'verified';
                $user->save();
                $user->refresh();
            } else {
                $user->refresh();
            }

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
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error verifying payment method', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify payment method',
            ], 500);
        }
    }

    public function createAccountLink(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            assert($user instanceof User);

            Log::info('Stripe account link creation requested', [
                'user_id' => $user?->id,
                'has_user' => ! is_null($user),
                'has_stripe_account_id' => ! is_null($user?->stripe_account_id),
            ]);

            if (! $user) {
                Log::warning('Stripe account link creation failed: User not authenticated');

                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if (! $user->stripe_account_id) {
                Log::info('Creating new Stripe Express account for onboarding', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);

                try {

                    $stripeAccount = Account::create([
                        'type' => 'express',
                        'country' => 'BR',
                        'email' => $user->email,
                        'capabilities' => [
                            'card_payments' => ['requested' => true],
                            'transfers' => ['requested' => true],
                        ],
                    ]);

                    $user->update([
                        'stripe_account_id' => $stripeAccount->id,
                    ]);

                    Log::info('Stripe Express account created for user', [
                        'user_id' => $user->id,
                        'stripe_account_id' => $stripeAccount->id,
                        'account_type' => $stripeAccount->type,
                    ]);
                } catch (InvalidRequestException $e) {
                    $errorMessage = $e->getMessage();

                    if (stripos($errorMessage, 'Connect') !== false || stripos($errorMessage, 'connect') !== false) {
                        Log::error('Stripe Connect not enabled', [
                            'user_id' => $user->id,
                            'error' => $errorMessage,
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => 'Stripe Connect is not enabled on your Stripe account. Please enable Stripe Connect in your Stripe Dashboard settings to use this feature. Visit https://dashboard.stripe.com/settings/connect to enable it.',
                            'error' => $errorMessage,
                            'help_url' => 'https://stripe.com/docs/connect',
                        ], 400);
                    }

                    throw $e;
                } catch (ApiErrorException $e) {
                    $errorMessage = $e->getMessage();

                    if (stripos($errorMessage, 'Connect') !== false || stripos($errorMessage, 'connect') !== false) {
                        Log::error('Stripe Connect not enabled', [
                            'user_id' => $user->id,
                            'error' => $errorMessage,
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => 'Stripe Connect is not enabled on your Stripe account. Please enable Stripe Connect in your Stripe Dashboard settings to use this feature. Visit https://dashboard.stripe.com/settings/connect to enable it.',
                            'error' => $errorMessage,
                            'help_url' => 'https://stripe.com/docs/connect',
                        ], 400);
                    }

                    throw $e;
                }
            }

            $refreshUrl = config('app.frontend_url').'/dashboard/payment-methods?stripe_connect_refresh=1';
            $returnUrl = config('app.frontend_url').'/dashboard/payment-methods?stripe_connect_return=1';

            Log::info('Creating Stripe account link for onboarding', [
                'user_id' => $user->id,
                'stripe_account_id' => $user->stripe_account_id,
                'refresh_url' => $refreshUrl,
                'return_url' => $returnUrl,
            ]);

            try {

                $accountLink = \Stripe\AccountLink::create([
                    'account' => $user->stripe_account_id,
                    'refresh_url' => $refreshUrl,
                    'return_url' => $returnUrl,
                    'type' => 'account_onboarding',
                ]);

                Log::info('Stripe account link created successfully', [
                    'user_id' => $user->id,
                    'stripe_account_id' => $user->stripe_account_id,
                    'link_expires_at' => $accountLink->expires_at ?? 'not_provided',
                ]);

                return response()->json([
                    'success' => true,
                    'url' => $accountLink->url,
                    'expires_at' => $accountLink->expires_at,
                ]);
            } catch (InvalidRequestException $e) {
                $errorMessage = $e->getMessage();

                Log::error('Stripe account link creation failed', [
                    'user_id' => $user->id,
                    'error' => $errorMessage,
                ]);

                if (stripos($errorMessage, 'Connect') !== false || stripos($errorMessage, 'connect') !== false) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Stripe Connect is not enabled on your Stripe account. Please enable Stripe Connect in your Stripe Dashboard settings to use this feature. Visit https://dashboard.stripe.com/settings/connect to enable it.',
                        'error' => $errorMessage,
                        'help_url' => 'https://stripe.com/docs/connect',
                    ], 400);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create account link: '.$errorMessage,
                    'error' => $errorMessage,
                ], 400);
            } catch (ApiErrorException $e) {
                $errorMessage = $e->getMessage();

                Log::error('Stripe API error during account link creation', [
                    'user_id' => $user->id,
                    'error' => $errorMessage,
                ]);

                if (stripos($errorMessage, 'Connect') !== false || stripos($errorMessage, 'connect') !== false) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Stripe Connect is not enabled on your Stripe account. Please enable Stripe Connect in your Stripe Dashboard settings to use this feature. Visit https://dashboard.stripe.com/settings/connect to enable it.',
                        'error' => $errorMessage,
                        'help_url' => 'https://stripe.com/docs/connect',
                    ], 400);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Payment service error: '.$errorMessage,
                    'error' => $errorMessage,
                ], 500);
            }

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            Log::error('Error creating Stripe account link', [
                'user_id' => auth()->id(),
                'error' => $errorMessage,
                'trace' => $e->getTraceAsString(),
            ]);

            if (stripos($errorMessage, 'Connect') !== false || stripos($errorMessage, 'connect') !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe Connect is not enabled on your Stripe account. Please enable Stripe Connect in your Stripe Dashboard settings to use this feature. Visit https://dashboard.stripe.com/settings/connect to enable it.',
                    'error' => $errorMessage,
                    'help_url' => 'https://stripe.com/docs/connect',
                ], 400);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create account link: '.$errorMessage,
                'error' => $errorMessage,
            ], 500);
        }
    }

    public function setupIntent(Request $request): JsonResponse
    {
        try {
            Log::info('SetupIntent request received', [
                'username' => $request->username,
                'email' => $request->email,
                'user_id' => auth()->id(),
            ]);

            $request->validate([
                'username' => 'required|string|min:3|max:50',
                'email' => 'required|email|max:255',
            ]);

            $rateLimitKey = 'setup_intent_'.$request->ip();
            $rateLimitCount = cache()->get($rateLimitKey, 0);

            if ($rateLimitCount >= 10) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please try again later.',
                ], 429);
            }

            cache()->put($rateLimitKey, $rateLimitCount + 1, 3600);

            $stripeSecret = config('services.stripe.secret');
            $stripePublishableKey = config('services.stripe.publishable_key');

            Log::info('Stripe configuration check', [
                'has_secret' => ! empty($stripeSecret),
                'has_publishable' => ! empty($stripePublishableKey),
                'secret_length' => $stripeSecret ? strlen($stripeSecret) : 0,
                'publishable_length' => $stripePublishableKey ? strlen($stripePublishableKey) : 0,
            ]);

            if (! $stripeSecret) {
                Log::error('Stripe secret key not configured', [
                    'config_value' => config('services.stripe.secret'),
                    'env_value' => env('STRIPE_SECRET'),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Stripe secret key not configured. Please set STRIPE_SECRET in your .env file.',
                    'debug' => 'Check your .env file for STRIPE_SECRET variable',
                ], 500);
            }

            if (! $stripePublishableKey) {
                Log::error('Stripe publishable key not configured', [
                    'config_value' => config('services.stripe.publishable_key'),
                    'env_value' => env('STRIPE_PUBLISHABLE_KEY'),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Stripe publishable key not configured. Please set STRIPE_PUBLISHABLE_KEY in your .env file.',
                    'debug' => 'Check your .env file for STRIPE_PUBLISHABLE_KEY variable',
                ], 500);
            }

            try {

                $user = auth()->user();
                assert($user instanceof User);

                if (! $user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User not authenticated',
                    ], 401);
                }

                DB::beginTransaction();

                try {

                    $customer = null;
                    if ($user->stripe_customer_id) {
                        Log::info('Retrieving existing Stripe customer', [
                            'user_id' => $user->id,
                            'stripe_customer_id' => $user->stripe_customer_id,
                        ]);

                        $customer = Customer::retrieve($user->stripe_customer_id);

                        Log::info('Existing Stripe customer retrieved', [
                            'user_id' => $user->id,
                            'customer_id' => $customer->id,
                            'customer_email' => $customer->email,
                            'request_email' => $request->email,
                        ]);

                        if ($customer->email !== $request->email) {
                            Log::info('Updating Stripe customer email', [
                                'user_id' => $user->id,
                                'customer_id' => $customer->id,
                                'old_email' => $customer->email,
                                'new_email' => $request->email,
                            ]);

                            $customer = Customer::update($user->stripe_customer_id, [
                                'email' => $request->email,
                                'metadata' => [
                                    'username' => $request->username,
                                    'user_id' => $user->id,
                                ],
                            ]);
                        }
                    } else {
                        Log::info('Creating new Stripe customer', [
                            'user_id' => $user->id,
                            'email' => $request->email,
                            'username' => $request->username,
                        ]);

                        $customer = Customer::create([
                            'email' => $request->email,
                            'metadata' => [
                                'username' => $request->username,
                                'user_id' => $user->id,
                            ],
                        ]);

                        $user->update([
                            'stripe_customer_id' => $customer->id,
                        ]);

                        Log::info('New Stripe customer created and linked to user', [
                            'user_id' => $user->id,
                            'customer_id' => $customer->id,
                        ]);
                    }

                    Log::info('Creating Stripe SetupIntent', [
                        'user_id' => $user->id,
                        'customer_id' => $customer->id,
                        'usage' => 'off_session',
                    ]);

                    $setupIntent = SetupIntent::create([
                        'customer' => $customer->id,
                        'payment_method_types' => ['card'],
                        'usage' => 'off_session',
                    ]);

                    Log::info('Stripe SetupIntent created successfully', [
                        'user_id' => $user->id,
                        'setup_intent_id' => $setupIntent->id,
                        'status' => $setupIntent->status ?? 'unknown',
                        'client_secret_exists' => ! empty($setupIntent->client_secret),
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
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'An unexpected error occurred. Please try again.',
                    ], 500);
                }

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('SetupIntent creation error', [
                    'user_id' => auth()->id(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'An error occurred while creating SetupIntent. Please try again.',
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('SetupIntent creation error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating SetupIntent. Please try again.',
            ], 500);
        }
    }

    public function checkConfiguration(): JsonResponse
    {
        $stripeSecret = config('services.stripe.secret');
        $stripePublishableKey = config('services.stripe.publishable_key');

        return response()->json([
            'success' => true,
            'configuration' => [
                'stripe_secret_configured' => ! empty($stripeSecret),
                'stripe_publishable_configured' => ! empty($stripePublishableKey),
                'environment' => app()->environment(),
                'required_env_vars' => [
                    'STRIPE_SECRET' => ! empty($stripeSecret) ? 'Set' : 'Missing',
                    'STRIPE_PUBLISHABLE_KEY' => ! empty($stripePublishableKey) ? 'Set' : 'Missing',
                ],
            ],
            'instructions' => [
                '1' => 'Create a .env file in the Backend directory',
                '2' => 'Add STRIPE_SECRET=sk_test_your_key_here',
                '3' => 'Add STRIPE_PUBLISHABLE_KEY=pk_test_your_key_here',
                '4' => 'Restart your Laravel server',
            ],
        ]);
    }
}

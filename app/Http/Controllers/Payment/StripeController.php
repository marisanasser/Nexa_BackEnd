<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Domain\Shared\Traits\HasAuthenticatedUser;
use Aws\Exception\AwsException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Base\Controller;
use App\Models\User\User;
use Aws\Sns\SnsClient;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Customer;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\Stripe;


class StripeController extends Controller
{
    protected $sns;

    use HasAuthenticatedUser;
    public function __construct()
    {
        // Initialize Stripe from config (uses environment variables)
        $stripeKey = config('services.stripe.secret');
        if ($stripeKey) {
            Stripe::setApiKey(trim($stripeKey));
        }

        $awsKey = env('AWS_ACCESS_KEY_ID');
        $awsSecret = env('AWS_SECRET_ACCESS_KEY');
        if (!empty($awsKey) && !empty($awsSecret)) {
            try {
                $this->sns = new SnsClient([
                    'version' => 'latest',
                    'region' => env('AWS_DEFAULT_REGION', 'sa-east-1'),
                    'credentials' => [
                        'key' => $awsKey,
                        'secret' => $awsSecret,
                    ],
                ]);
            } catch (AwsException $e) {
                Log::warning('Failed to initialize SNS client', ['error' => $e->getMessage()]);
                $this->sns = null;
            } catch (Exception $e) {
                Log::warning('Failed to initialize SNS client', ['error' => $e->getMessage()]);
                $this->sns = null;
            }
        } else {
            $this->sns = null;
        }
    }

    public function createAccount(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        // Validate user authentication
        $authError = $this->validateUserForAccountCreation($user);
        if ($authError) {
            return $authError;
        }

        assert($user instanceof User);

        Log::info('Stripe account creation request started', [
            'user_id' => $user->id,
            'request_type' => $request->type ?? 'not_provided',
            'request_country' => $request->country ?? 'not_provided',
        ]);

        DB::beginTransaction();

        try {
            // Create payment method
            $paymentMethod = $this->createPaymentMethodForAccount($request, $user);

            // Build and create Stripe account
            $accountData = $this->buildAccountData($request);
            $stripeAccount = $this->createStripeConnectAccount($accountData, $user);

            // Update user record
            $this->updateUserStripeDetails($user, $stripeAccount->id, $paymentMethod->id);

            DB::commit();

            return $this->buildAccountCreationSuccessResponse($stripeAccount, $paymentMethod, $request->type);
        } catch (CardException $e) {
            return $this->handleCardException($e, $user->id);
        } catch (RateLimitException $e) {
            return $this->handleRateLimitException($e, $user->id);
        } catch (InvalidRequestException $e) {
            return $this->handleInvalidRequestException($e, $user->id);
        } catch (AuthenticationException $e) {
            return $this->handleAuthenticationException($e, $user->id);
        } catch (ApiConnectionException $e) {
            return $this->handleApiConnectionException($e, $user->id);
        } catch (ApiErrorException $e) {
            return $this->handleApiErrorException($e, $user->id);
        } catch (Exception $e) {
            DB::rollBack();

            return $this->handleGenericAccountCreationException($e);
        }
    }

    public function getAccountStatus(Request $request): JsonResponse
    {
        // Check for Stripe configuration before attempting to use the SDK
        if (!config('services.stripe.secret')) {
            Log::error('Stripe secret key missing in getAccountStatus');
            return response()->json([
                'success' => false,
                'message' => 'Stripe configuration is missing. Please check server logs.',
                'error' => 'STRIPE_SECRET_KEY is not set in .env file'
            ], 500);
        }

        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            Log::info('Stripe account status check requested', [
                'user_id' => $user?->id,
                'has_user' => $user !== null,
                'has_stripe_account_id' => !is_null($user?->stripe_account_id),
            ]);

            if (!$user) {
                Log::warning('Stripe account status check failed: User not authenticated');

                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if (!$user->stripe_account_id) {
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
        } catch (InvalidRequestException $e) {
            $errorMessage = $e->getMessage();

            // Checks if the error is "No such account"
            if (false !== stripos($errorMessage, 'No such account')) {
                Log::warning('Stripe account not found. Resetting user stripe_account_id', [
                    'user_id' => auth()->id(),
                    'invalid_id' => $user->stripe_account_id,
                ]);

                // Clear valid ID from database
                $user->stripe_account_id = null;
                $user->stripe_verification_status = 'unverified';
                $user->save();

                return response()->json([
                    'success' => true,
                    'has_account' => false,
                    'message' => 'Stripe account ID was invalid and has been reset.',
                ]);
            }

            Log::error('Stripe invalid request during status check', [
                'user_id' => auth()->id(),
                'error' => $errorMessage,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid request: '.$errorMessage,
            ], 400);
        } catch (Exception $e) {
            Log::error('Error retrieving Stripe account status', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve account status: '.$e->getMessage(),
            ], 500);
        }
    }

    public function verifyPaymentMethod(Request $request): JsonResponse
    {
        $request->validate([
            'payment_method_id' => 'required|string',
        ]);

        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            Log::info('Payment method verification requested', [
                'user_id' => $user?->id,
                'payment_method_id' => $request->payment_method_id,
            ]);

            if (!$user) {
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

            if (!$paymentMethod) {
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
                ])
            ;

            $actualStoredId = DB::table('users')
                ->where('id', $user->id)
                ->value('stripe_payment_method_id')
            ;

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
        } catch (Exception $e) {
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
        $user = $this->getAuthenticatedUser();

        // Validate user authentication
        if (!$user) {
            Log::warning('Stripe account link creation failed: User not authenticated');

            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        assert($user instanceof User);

        Log::info('Stripe account link creation requested', [
            'user_id' => $user->id,
            'has_stripe_account_id' => !is_null($user->stripe_account_id),
        ]);

        try {
            // Create Express account if user doesn't have one
            if (!$user->stripe_account_id) {
                $accountResult = $this->createExpressAccountForOnboarding($user);
                if ($accountResult instanceof JsonResponse) {
                    return $accountResult;
                }
            }

            // Create and return account link
            return $this->generateAccountLink($user);
        } catch (Exception $e) {
            return $this->handleAccountLinkException($e);
        }
    }

    public function setupIntent(Request $request): JsonResponse
    {
        Log::info('SetupIntent request received', [
            'username' => $request->username,
            'email' => $request->email,
            'user_id' => auth()->id(),
        ]);

        // Validate request
        $request->validate([
            'username' => 'required|string|min:3|max:50',
            'email' => 'required|email|max:255',
        ]);

        // Check rate limit
        $rateLimitResponse = $this->checkSetupIntentRateLimit($request);
        if ($rateLimitResponse) {
            return $rateLimitResponse;
        }

        // Validate Stripe configuration
        $configError = $this->validateStripeConfiguration();
        if ($configError) {
            return $configError;
        }

        // Validate user authentication
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        assert($user instanceof User);

        DB::beginTransaction();

        try {
            // Get or create Stripe customer
            $customer = $this->getOrCreateStripeCustomer($user, $request);

            // Create SetupIntent
            $setupIntent = $this->createStripeSetupIntent($user, $customer);

            DB::commit();

            return $this->buildSetupIntentSuccessResponse($user, $customer, $setupIntent);
        } catch (CardException $e) {
            return $this->handleSetupIntentCardException($e, $user->id);
        } catch (RateLimitException $e) {
            return $this->handleSetupIntentRateLimitException($e, $user->id);
        } catch (InvalidRequestException $e) {
            return $this->handleSetupIntentInvalidRequestException($e, $user->id);
        } catch (AuthenticationException $e) {
            return $this->handleSetupIntentAuthenticationException($e, $user->id);
        } catch (ApiConnectionException $e) {
            return $this->handleSetupIntentApiConnectionException($e, $user->id);
        } catch (ApiErrorException $e) {
            return $this->handleSetupIntentApiErrorException($e, $user->id);
        } catch (Exception $e) {
            DB::rollBack();

            return $this->handleSetupIntentGenericException($e);
        }
    }

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

    /**
     * Validate user for account creation.
     *
     * @param mixed $user
     */
    private function validateUserForAccountCreation($user): ?JsonResponse
    {
        if (!$user) {
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

        return null;
    }

    /**
     * Create a payment method for account creation.
     */
    private function createPaymentMethodForAccount(Request $request, User $user): PaymentMethod
    {
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

        return $paymentMethod;
    }

    /**
     * Build account data array for Stripe account creation.
     */
    private function buildAccountData(Request $request): array
    {
        $accountData = [
            'type' => 'individual' === $request->type ? 'express' : 'standard',
            'country' => $request->country,
            'email' => $request->email,
            'business_type' => $request->business_type,
        ];

        if ('individual' === $request->type && $request->individual) {
            $accountData['individual'] = [
                'first_name' => $request->individual['first_name'] ?? '',
                'last_name' => $request->individual['last_name'] ?? '',
                'email' => $request->individual['email'] ?? $request->email,
                'phone' => $request->individual['phone'] ?? '',
                'address' => $request->individual['address'] ?? [],
                'dob' => $request->individual['dob'] ?? [],
            ];
        }

        if ('business' === $request->type && $request->company) {
            $accountData['company'] = [
                'name' => $request->company['name'] ?? '',
                'structure' => $request->company['structure'] ?? '',
                'address' => $request->company['address'] ?? [],
            ];
        }

        return $accountData;
    }

    /**
     * Create Stripe Connect account.
     */
    private function createStripeConnectAccount(array $accountData, User $user): Account
    {
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

        return $stripeAccount;
    }

    /**
     * Update user with Stripe account details.
     */
    private function updateUserStripeDetails(User $user, string $accountId, string $paymentMethodId): void
    {
        $updatedRows = DB::table('users')
            ->where('id', $user->id)
            ->update([
                'stripe_account_id' => $accountId,
                'stripe_payment_method_id' => $paymentMethodId,
                'stripe_verification_status' => 'pending',
            ])
        ;

        $user->refresh();

        Log::info('Updated user with Stripe account and payment method via direct DB update', [
            'user_id' => $user->id,
            'updated_rows' => $updatedRows,
            'stripe_account_id' => $user->stripe_account_id,
            'stripe_payment_method_id' => $user->stripe_payment_method_id,
        ]);
    }

    /**
     * Build success response for account creation.
     */
    private function buildAccountCreationSuccessResponse(Account $stripeAccount, PaymentMethod $paymentMethod, ?string $requestType): JsonResponse
    {
        Log::info('Stripe account created successfully', [
            'stripe_account_id' => $stripeAccount->id,
            'account_type' => $requestType,
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
    }

    /**
     * Handle CardException during account creation.
     */
    private function handleCardException(CardException $e, int $userId): JsonResponse
    {
        Log::error('Stripe card error during account creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
            'decline_code' => $e->getDeclineCode(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Account creation failed. Please check your information.',
            'error' => $e->getMessage(),
        ], 400);
    }

    /**
     * Handle RateLimitException during account creation.
     */
    private function handleRateLimitException(RateLimitException $e, int $userId): JsonResponse
    {
        Log::error('Stripe rate limit error during account creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
        ], 429);
    }

    /**
     * Handle InvalidRequestException during account creation.
     */
    private function handleInvalidRequestException(InvalidRequestException $e, int $userId): JsonResponse
    {
        Log::error('Stripe invalid request error during account creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Invalid account information. Please check your details.',
            'error' => $e->getMessage(),
        ], 400);
    }

    /**
     * Handle AuthenticationException during account creation.
     */
    private function handleAuthenticationException(AuthenticationException $e, int $userId): JsonResponse
    {
        Log::error('Stripe authentication error during account creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Payment service authentication error. Please contact support.',
        ], 500);
    }

    /**
     * Handle ApiConnectionException during account creation.
     */
    private function handleApiConnectionException(ApiConnectionException $e, int $userId): JsonResponse
    {
        Log::error('Stripe API connection error during account creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Payment service is temporarily unavailable. Please try again later.',
        ], 503);
    }

    /**
     * Handle ApiErrorException during account creation.
     */
    private function handleApiErrorException(ApiErrorException $e, int $userId): JsonResponse
    {
        Log::error('Stripe API error during account creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Account creation error. Please try again.',
            'error' => $e->getMessage(),
        ], 500);
    }

    /**
     * Handle generic exception during account creation.
     */
    private function handleGenericAccountCreationException(Exception $e): JsonResponse
    {
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

    /**
     * Create Express account for user onboarding.
     */
    private function createExpressAccountForOnboarding(User $user): ?JsonResponse
    {
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

            return null;
        } catch (ApiErrorException|InvalidRequestException $e) {
            return $this->handleStripeConnectException($e, $user->id);
        }
    }

    /**
     * Generate account link for onboarding.
     */
    private function generateAccountLink(User $user): JsonResponse
    {
        $refreshUrl = config('app.frontend_url').'/dashboard/payment-methods?stripe_connect_refresh=1';
        $returnUrl = config('app.frontend_url').'/dashboard/payment-methods?stripe_connect_return=1';

        Log::info('Creating Stripe account link for onboarding', [
            'user_id' => $user->id,
            'stripe_account_id' => $user->stripe_account_id,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
        ]);

        try {
            $accountLink = AccountLink::create([
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
        } catch (ApiErrorException|InvalidRequestException $e) {
            $errorMessage = $e->getMessage();

            Log::error('Stripe account link creation failed', [
                'user_id' => $user->id,
                'error' => $errorMessage,
            ]);

            if ($this->isStripeConnectError($errorMessage)) {
                return $this->buildStripeConnectErrorResponse($errorMessage);
            }

            $statusCode = $e instanceof InvalidRequestException ? 400 : 500;
            $messagePrefix = $e instanceof InvalidRequestException ? 'Failed to create account link: ' : 'Payment service error: ';

            return response()->json([
                'success' => false,
                'message' => $messagePrefix.$errorMessage,
                'error' => $errorMessage,
            ], $statusCode);
        }
    }

    /**
     * Handle Stripe Connect exception.
     */
    private function handleStripeConnectException(Exception $e, int $userId): ?JsonResponse
    {
        $errorMessage = $e->getMessage();

        if ($this->isStripeConnectError($errorMessage)) {
            Log::error('Stripe Connect not enabled', [
                'user_id' => $userId,
                'error' => $errorMessage,
            ]);

            return $this->buildStripeConnectErrorResponse($errorMessage);
        }

        throw $e;
    }

    /**
     * Check if error is related to Stripe Connect.
     */
    private function isStripeConnectError(string $errorMessage): bool
    {
        return false !== stripos($errorMessage, 'Connect') || false !== stripos($errorMessage, 'connect');
    }

    /**
     * Build Stripe Connect error response.
     */
    private function buildStripeConnectErrorResponse(string $errorMessage): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Stripe Connect is not enabled on your Stripe account. Please enable Stripe Connect in your Stripe Dashboard settings to use this feature. Visit https://dashboard.stripe.com/settings/connect to enable it.',
            'error' => $errorMessage,
            'help_url' => 'https://stripe.com/docs/connect',
        ], 400);
    }

    /**
     * Handle generic exception during account link creation.
     */
    private function handleAccountLinkException(Exception $e): JsonResponse
    {
        $errorMessage = $e->getMessage();

        Log::error('Error creating Stripe account link', [
            'user_id' => auth()->id(),
            'error' => $errorMessage,
            'trace' => $e->getTraceAsString(),
        ]);

        if ($this->isStripeConnectError($errorMessage)) {
            return $this->buildStripeConnectErrorResponse($errorMessage);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to create account link: '.$errorMessage,
            'error' => $errorMessage,
        ], 500);
    }

    /**
     * Check rate limit for SetupIntent creation.
     */
    private function checkSetupIntentRateLimit(Request $request): ?JsonResponse
    {
        $rateLimitKey = 'setup_intent_'.$request->ip();
        $rateLimitCount = cache()->get($rateLimitKey, 0);

        if ($rateLimitCount >= 10) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
            ], 429);
        }

        cache()->put($rateLimitKey, $rateLimitCount + 1, 3600);

        return null;
    }

    /**
     * Validate Stripe configuration.
     */
    private function validateStripeConfiguration(): ?JsonResponse
    {
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
                'debug' => 'Check your .env file for STRIPE_SECRET variable',
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
                'debug' => 'Check your .env file for STRIPE_PUBLISHABLE_KEY variable',
            ], 500);
        }

        return null;
    }

    /**
     * Get or create Stripe customer for user.
     */
    private function getOrCreateStripeCustomer(User $user, Request $request): Customer
    {
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

            return $customer;
        }

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

        return $customer;
    }

    /**
     * Create Stripe SetupIntent.
     */
    private function createStripeSetupIntent(User $user, Customer $customer): SetupIntent
    {
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
            'client_secret_exists' => !empty($setupIntent->client_secret),
        ]);

        return $setupIntent;
    }

    /**
     * Build success response for SetupIntent creation.
     */
    private function buildSetupIntentSuccessResponse(User $user, Customer $customer, SetupIntent $setupIntent): JsonResponse
    {
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
    }

    /**
     * Handle CardException during SetupIntent creation.
     */
    private function handleSetupIntentCardException(CardException $e, int $userId): JsonResponse
    {
        Log::error('Stripe card error during SetupIntent creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
            'decline_code' => $e->getDeclineCode(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Card error. Please check your card information.',
            'error' => $e->getMessage(),
        ], 400);
    }

    /**
     * Handle RateLimitException during SetupIntent creation.
     */
    private function handleSetupIntentRateLimitException(RateLimitException $e, int $userId): JsonResponse
    {
        Log::error('Stripe rate limit error during SetupIntent creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Too many requests to payment service. Please try again later.',
        ], 429);
    }

    /**
     * Handle InvalidRequestException during SetupIntent creation.
     */
    private function handleSetupIntentInvalidRequestException(InvalidRequestException $e, int $userId): JsonResponse
    {
        Log::error('Stripe invalid request error during SetupIntent creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Invalid request. Please check your information.',
            'error' => $e->getMessage(),
        ], 400);
    }

    /**
     * Handle AuthenticationException during SetupIntent creation.
     */
    private function handleSetupIntentAuthenticationException(AuthenticationException $e, int $userId): JsonResponse
    {
        Log::error('Stripe authentication error during SetupIntent creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Payment service authentication error. Please contact support.',
        ], 500);
    }

    /**
     * Handle ApiConnectionException during SetupIntent creation.
     */
    private function handleSetupIntentApiConnectionException(ApiConnectionException $e, int $userId): JsonResponse
    {
        Log::error('Stripe API connection error during SetupIntent creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Payment service is temporarily unavailable. Please try again later.',
        ], 503);
    }

    /**
     * Handle ApiErrorException during SetupIntent creation.
     */
    private function handleSetupIntentApiErrorException(ApiErrorException $e, int $userId): JsonResponse
    {
        Log::error('Stripe API error during SetupIntent creation', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Payment service error. Please try again.',
            'error' => $e->getMessage(),
        ], 500);
    }

    /**
     * Handle generic exception during SetupIntent creation.
     */
    private function handleSetupIntentGenericException(Exception $e): JsonResponse
    {
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

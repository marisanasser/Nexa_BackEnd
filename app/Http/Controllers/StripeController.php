<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Account;
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
                        'id_number' => $request->individual['id_number'] ?? '',
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

                // Update user with Stripe account ID
                $user->update([
                    'stripe_account_id' => $stripeAccount->id,
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
                    'account_type' => $stripeAccount->type,
                    'verification_status' => 'pending',
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
}

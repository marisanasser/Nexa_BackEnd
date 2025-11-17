<?php

namespace App\Http\Controllers;

use App\Models\CreatorBalance;
use App\Models\Withdrawal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Stripe\Stripe as StripeClient;
use Stripe\Customer;
use Stripe\Checkout\Session;

class CreatorBalanceController extends Controller
{
    /**
     * Get creator balance and earnings
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        // Check if user is a creator or student
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can access balance information',
            ], 403);
        }

        try {
            $balance = CreatorBalance::where('creator_id', $user->id)->first();

            if (!$balance) {
                // Create balance record if it doesn't exist
                $balance = CreatorBalance::create([
                    'creator_id' => $user->id,
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'total_earned' => 0,
                    'total_withdrawn' => 0,
                ]);
            }

            // Check if balance needs recalculation
            // Check for any payments (completed or pending) for this creator
            $hasAnyPayments = \App\Models\JobPayment::where('creator_id', $user->id)->exists();
            $completedPaymentsTotal = \App\Models\JobPayment::where('creator_id', $user->id)
                ->where('status', 'completed')
                ->sum('creator_amount');

            // Recalculate if:
            // 1. There are any payments but balance is all zeros, OR
            // 2. There are completed payments but total_earned doesn't match
            $shouldRecalculate = false;
            $recalculateReason = '';

            if ($hasAnyPayments && 
                $balance->total_earned == 0 && 
                $balance->available_balance == 0 && 
                $balance->pending_balance == 0) {
                $shouldRecalculate = true;
                $recalculateReason = 'balance_is_zero_but_payments_exist';
            } elseif ($completedPaymentsTotal > 0 && abs($balance->total_earned - $completedPaymentsTotal) > 0.01) {
                $shouldRecalculate = true;
                $recalculateReason = 'total_earned_mismatch';
            }

            if ($shouldRecalculate) {
                // Store previous values for logging
                $previousTotalEarned = $balance->total_earned;
                $previousAvailableBalance = $balance->available_balance;
                $previousPendingBalance = $balance->pending_balance;
                
                // Recalculate balance from existing payments
                $balance->recalculateFromPayments();
                $balance->refresh();
                
                Log::info('Recalculated creator balance on API access', [
                    'creator_id' => $user->id,
                    'reason' => $recalculateReason,
                    'previous_total_earned' => $previousTotalEarned,
                    'previous_available_balance' => $previousAvailableBalance,
                    'previous_pending_balance' => $previousPendingBalance,
                    'calculated_total_earned' => $completedPaymentsTotal,
                    'new_total_earned' => $balance->total_earned,
                    'new_available_balance' => $balance->available_balance,
                    'new_pending_balance' => $balance->pending_balance,
                ]);
            }

            // Get recent transactions
            $recentTransactions = $balance->getRecentTransactions(5);
            $recentWithdrawals = $balance->getWithdrawalHistory(5);

            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => [
                        'available_balance' => (float) $balance->available_balance,
                        'pending_balance' => (float) $balance->pending_balance,
                        'total_balance' => (float) $balance->total_balance,
                        'total_earned' => (float) $balance->total_earned,
                        'total_withdrawn' => (float) $balance->total_withdrawn,
                        'formatted_available_balance' => $balance->formatted_available_balance,
                        'formatted_pending_balance' => $balance->formatted_pending_balance,
                        'formatted_total_balance' => $balance->formatted_total_balance,
                        'formatted_total_earned' => $balance->formatted_total_earned,
                        'formatted_total_withdrawn' => $balance->formatted_total_withdrawn,
                    ],
                    'earnings' => [
                        'this_month' => (float) $balance->earnings_this_month,
                        'this_year' => (float) $balance->earnings_this_year,
                        'formatted_this_month' => $balance->formatted_earnings_this_month,
                        'formatted_this_year' => $balance->formatted_earnings_this_year,
                    ],
                    'withdrawals' => [
                        'pending_count' => (int) $balance->pending_withdrawals_count,
                        'pending_amount' => (float) $balance->pending_withdrawals_amount,
                        'formatted_pending_amount' => $balance->formatted_pending_withdrawals_amount,
                    ],
                    'recent_transactions' => $recentTransactions->map(function ($payment) {
                        return [
                            'id' => $payment->id,
                            'contract_title' => $payment->contract->title,
                            'amount' => $payment->formatted_creator_amount,
                            'status' => $payment->status,
                            'processed_at' => $payment->processed_at?->format('Y-m-d H:i:s'),
                        ];
                    }),
                    'recent_withdrawals' => $recentWithdrawals->map(function ($withdrawal) {
                        return [
                            'id' => $withdrawal->id,
                            'amount' => $withdrawal->formatted_amount,
                            'method' => $withdrawal->withdrawal_method_label,
                            'status' => $withdrawal->status,
                            'created_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                        ];
                    }),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching creator balance', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch balance information',
            ], 500);
        }
    }

    /**
     * Get detailed balance history
     */
    public function history(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'nullable|integer|min:1|max:365',
            'type' => 'nullable|in:earnings,withdrawals,all',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();

        // Check if user is a creator or student
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can access balance history',
            ], 403);
        }

        // Students don't have balance history, return empty data
        if ($user->isStudent()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'earnings' => [],
                    'withdrawals' => [],
                    'summary' => [
                        'total_earnings' => 0,
                        'total_withdrawals' => 0,
                        'net_balance' => 0,
                    ]
                ]
            ]);
        }

        try {
            $balance = CreatorBalance::where('creator_id', $user->id)->first();
            
            if (!$balance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Balance not found',
                ], 404);
            }

            $days = $request->get('days', 30);
            $type = $request->get('type', 'all');

            $history = [];

            if ($type === 'all' || $type === 'earnings') {
                $earnings = $balance->payments()
                    ->with('contract:id,title')
                    ->where('status', 'completed')
                    ->when($days < 365, function ($query) use ($days) {
                        return $query->where('processed_at', '>=', now()->subDays($days));
                    })
                    ->orderBy('processed_at', 'desc')
                    ->get()
                    ->map(function ($payment) {
                        return [
                            'type' => 'earning',
                            'id' => $payment->id,
                            'amount' => $payment->creator_amount,
                            'formatted_amount' => $payment->formatted_creator_amount,
                            'description' => 'Payment for: ' . $payment->contract->title,
                            'date' => $payment->processed_at->format('Y-m-d H:i:s'),
                            'status' => $payment->status,
                        ];
                    });

                $history = array_merge($history, $earnings->toArray());
            }

            if ($type === 'all' || $type === 'withdrawals') {
                $withdrawals = $balance->withdrawals()
                    ->when($days < 365, function ($query) use ($days) {
                        return $query->where('created_at', '>=', now()->subDays($days));
                    })
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(function ($withdrawal) {
                        return [
                            'type' => 'withdrawal',
                            'id' => $withdrawal->id,
                            'amount' => -$withdrawal->amount, // Negative for withdrawals
                            'formatted_amount' => '-' . $withdrawal->formatted_amount,
                            'description' => 'Withdrawal via ' . $withdrawal->withdrawal_method_label,
                            'date' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                            'status' => $withdrawal->status,
                        ];
                    });

                $history = array_merge($history, $withdrawals->toArray());
            }

            // Sort by date (newest first)
            usort($history, function ($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });

            // Calculate running balance
            $runningBalance = 0;
            foreach (array_reverse($history) as &$item) {
                $runningBalance += $item['amount'];
                $item['running_balance'] = $runningBalance;
                $item['formatted_running_balance'] = 'R$ ' . number_format($runningBalance, 2, ',', '.');
            }

            // Reverse back to newest first
            $history = array_reverse($history);

            return response()->json([
                'success' => true,
                'data' => [
                    'history' => $history,
                    'summary' => [
                        'total_earnings' => $balance->total_earned,
                        'total_withdrawals' => $balance->total_withdrawn,
                        'current_balance' => $balance->total_balance,
                        'formatted_total_earnings' => $balance->formatted_total_earned,
                        'formatted_total_withdrawals' => $balance->formatted_total_withdrawn,
                        'formatted_current_balance' => $balance->formatted_total_balance,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching balance history', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch balance history',
            ], 500);
        }
    }

    /**
     * Get available withdrawal methods
     */
    public function withdrawalMethods(): JsonResponse
    {
        $user = Auth::user();

        // Check if user is a creator or student
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can access withdrawal methods',
            ], 403);
        }

    
        try {
            // Get withdrawal methods from user model
            $methods = $user->getWithdrawalMethods();

            // Convert collection to array to ensure proper JSON serialization
            $methodsArray = $methods->values()->toArray();

            Log::info('Withdrawal methods returned', [
                'user_id' => $user->id,
                'methods_count' => count($methodsArray),
                'has_stripe_payment_method' => $user->stripe_payment_method_id ? 'yes' : 'no',
                'stripe_payment_method_id' => $user->stripe_payment_method_id,
                'method_ids' => array_column($methodsArray, 'id'),
            ]);

            return response()->json([
                'success' => true,
                'data' => $methodsArray,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching withdrawal methods', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch withdrawal methods',
            ], 500);
        }
    }

    /**
     * Get creator's work history
     */
    public function workHistory(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Check if user is a creator or student
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can access work history',
            ], 403);
        }

        // Students don't have work history, return empty data
        if ($user->isStudent()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'data' => [],
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 10,
                    'total' => 0,
                    'from' => null,
                    'to' => null,
                ]
            ]);
        }

        try {
            $contracts = $user->creatorContracts()
                ->with(['brand:id,name,avatar_url', 'payment'])
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            $contracts->getCollection()->transform(function ($contract) use ($user) {
                return [
                    'id' => $contract->id,
                    'title' => $contract->title,
                    'description' => $contract->description,
                    'budget' => $contract->formatted_budget,
                    'creator_amount' => $contract->formatted_creator_amount,
                    'status' => $contract->status,
                    'started_at' => $contract->started_at->format('Y-m-d H:i:s'),
                    'completed_at' => $contract->completed_at?->format('Y-m-d H:i:s'),
                    'brand' => [
                        'id' => $contract->brand->id,
                        'name' => $contract->brand->name,
                        'avatar_url' => $contract->brand->avatar_url,
                    ],
                    'payment' => $contract->payment ? [
                        'status' => $contract->payment->status,
                        'amount' => $contract->payment->formatted_creator_amount,
                        'processed_at' => $contract->payment->processed_at?->format('Y-m-d H:i:s'),
                    ] : null,
                    'review' => ($userReview = $contract->userReview($user->id)->first()) ? [
                        'rating' => $userReview->rating,
                        'comment' => $userReview->comment,
                        'created_at' => $userReview->created_at->format('Y-m-d H:i:s'),
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $contracts,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching work history', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch work history',
            ], 500);
        }
    }

    /**
     * Create Stripe Checkout Session for creator to connect payment method (for withdrawals)
     */
    public function createStripePaymentMethodCheckout(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            // Check if user is a creator or student
            if (!$user->isCreator() && !$user->isStudent()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only creators and students can connect payment methods',
                ], 403);
            }

            Log::info('Creating Stripe Checkout Session for creator payment method setup', [
                'user_id' => $user->id,
            ]);

            $stripeSecret = config('services.stripe.secret');
            if (!$stripeSecret) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe is not configured',
                ], 500);
            }

            StripeClient::setApiKey($stripeSecret);

            // Ensure Stripe customer exists for the creator
            $customerId = $user->stripe_customer_id;
            
            if (!$customerId) {
                Log::info('Creating new Stripe customer for creator', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);

                $customer = Customer::create([
                    'email' => $user->email,
                    'name' => $user->name,
                    'metadata' => [
                        'user_id' => $user->id,
                        'role' => $user->role,
                    ],
                ]);

                $customerId = $customer->id;
                $user->update(['stripe_customer_id' => $customerId]);

                Log::info('Stripe customer created for creator', [
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
                            'role' => $user->role,
                        ],
                    ]);

                    $customerId = $customer->id;
                    $user->update(['stripe_customer_id' => $customerId]);
                }
            }

            // Get frontend URL from config
            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

            // Create Checkout Session in setup mode (for payment method collection only)
            $session = Session::create([
                'customer' => $customerId,
                'mode' => 'setup', // Setup mode for payment method collection only
                'payment_method_types' => ['card'],
                'locale' => 'pt-BR',
                'success_url' => $frontendUrl . '/creator/payment-method?payment_method=connected&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $frontendUrl . '/creator/payment-method?payment_method=cancelled',
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'type' => 'creator_payment_method_setup',
                    'purpose' => 'withdrawal',
                ],
            ]);

            Log::info('Stripe Checkout Session created for creator payment method setup', [
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
            Log::error('Error creating Stripe Checkout Session for creator payment method', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment method setup session: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle successful Stripe Checkout Session completion for creator payment method
     * This is called from the frontend after returning from Stripe checkout
     * It ensures the payment method ID is stored even if webhook fails
     */
    public function handleCheckoutSuccess(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            // Check if user is a creator or student
            if (!$user->isCreator() && !$user->isStudent()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only creators and students can connect payment methods',
                ], 403);
            }

            // Validate session_id - check both body and query parameters
            $sessionId = $request->input('session_id') ?? $request->query('session_id');
            
            if (!$sessionId) {
                Log::error('Session ID missing in creator checkout success request', [
                    'user_id' => $user->id,
                    'request_body' => $request->all(),
                    'request_query' => $request->query(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Session ID is required',
                ], 400);
            }

            Log::info('Handling creator Stripe Checkout Session success', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
            ]);

            $stripeSecret = config('services.stripe.secret');
            if (!$stripeSecret) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe is not configured',
                ], 500);
            }

            \Stripe\Stripe::setApiKey($stripeSecret);

            // Retrieve the checkout session
            try {
                $session = \Stripe\Checkout\Session::retrieve($sessionId, [
                    'expand' => ['setup_intent', 'setup_intent.payment_method'],
                ]);

                Log::info('Stripe Checkout Session retrieved for creator', [
                    'user_id' => $user->id,
                    'session_id' => $session->id,
                    'session_mode' => $session->mode ?? 'N/A',
                    'session_status' => $session->status ?? 'N/A',
                    'has_setup_intent' => !is_null($session->setup_intent),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to retrieve Stripe Checkout Session for creator', [
                    'user_id' => $user->id,
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to retrieve checkout session: ' . $e->getMessage(),
                ], 400);
            }

            // Verify session belongs to this user
            $sessionCustomerId = $session->customer;
            if (is_object($sessionCustomerId)) {
                $sessionCustomerId = $sessionCustomerId->id;
            }

            if (!$sessionCustomerId || $sessionCustomerId !== $user->stripe_customer_id) {
                Log::warning('Session customer ID does not match user customer ID', [
                    'user_id' => $user->id,
                    'user_customer_id' => $user->stripe_customer_id,
                    'session_customer_id' => $sessionCustomerId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid session - session does not belong to this user',
                ], 403);
            }

            // Get setup intent
            $setupIntent = $session->setup_intent;
            
            if (is_string($setupIntent)) {
                $setupIntent = \Stripe\SetupIntent::retrieve($setupIntent, [
                    'expand' => ['payment_method']
                ]);
            }

            if (!$setupIntent || $setupIntent->status !== 'succeeded') {
                Log::warning('Setup intent not succeeded for creator', [
                    'user_id' => $user->id,
                    'setup_intent_status' => $setupIntent->status ?? 'no_status',
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Setup intent not completed',
                ], 400);
            }

            // Get payment method ID from setup intent
            $paymentMethodId = null;
            if (is_object($setupIntent->payment_method)) {
                $paymentMethodId = $setupIntent->payment_method->id ?? null;
            } elseif (is_string($setupIntent->payment_method)) {
                $paymentMethodId = $setupIntent->payment_method;
            }

            if (!$paymentMethodId) {
                Log::error('No payment method ID in setup intent for creator', [
                    'user_id' => $user->id,
                    'setup_intent_id' => $setupIntent->id ?? 'no_id',
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method not found in setup intent',
                ], 400);
            }

            // Check if payment method is already stored (idempotency check)
            // If already stored, return success immediately without updating
            $user->refresh();
            if ($user->stripe_payment_method_id === $paymentMethodId) {
                Log::info('Payment method already stored for creator, returning success', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodId,
                    'session_id' => $sessionId,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Payment method already connected',
                    'data' => [
                        'payment_method_id' => $paymentMethodId,
                        'already_stored' => true,
                    ],
                ]);
            }

            // Retrieve payment method details
            try {
                $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
                $cardBrand = $paymentMethod->card->brand ?? null;
                $cardLast4 = $paymentMethod->card->last4 ?? null;
                $cardExpMonth = $paymentMethod->card->exp_month ?? null;
                $cardExpYear = $paymentMethod->card->exp_year ?? null;

                Log::info('Retrieved payment method details for creator', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodId,
                    'card_brand' => $cardBrand,
                    'card_last4' => $cardLast4,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to retrieve payment method details, continuing anyway', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodId,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::beginTransaction();

            try {
                // Update user's stripe_payment_method_id using direct DB update
                $updatedRows = DB::table('users')
                    ->where('id', $user->id)
                    ->update(['stripe_payment_method_id' => $paymentMethodId]);

                // Verify the update
                $actualStoredId = DB::table('users')
                    ->where('id', $user->id)
                    ->value('stripe_payment_method_id');

                if ($actualStoredId !== $paymentMethodId) {
                    // Fallback to Eloquent if direct DB update failed
                    $user->refresh();
                    $user->stripe_payment_method_id = $paymentMethodId;
                    $user->save();
                    $user->refresh();
                }

                // Ensure 'stripe_card' withdrawal method exists in withdrawal_methods table
                $stripeCardMethod = \App\Models\WithdrawalMethod::where('code', 'stripe_card')->first();
                
                if (!$stripeCardMethod) {
                    $stripeCardMethod = \App\Models\WithdrawalMethod::create([
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
                    
                    Log::info('Created stripe_card withdrawal method in withdrawal_methods table', [
                        'withdrawal_method_id' => $stripeCardMethod->id,
                        'user_id' => $user->id,
                    ]);
                } else {
                    if (!$stripeCardMethod->is_active) {
                        $stripeCardMethod->update(['is_active' => true]);
                    }
                }

                DB::commit();

                Log::info('Successfully stored creator payment method from checkout success callback', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodId,
                    'updated_rows' => $updatedRows,
                    'verified' => ($user->stripe_payment_method_id === $paymentMethodId),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Payment method connected successfully',
                    'data' => [
                        'payment_method_id' => $paymentMethodId,
                        'card_brand' => $cardBrand ?? null,
                        'card_last4' => $cardLast4 ?? null,
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to store creator payment method in transaction', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to handle creator checkout success', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment method: ' . $e->getMessage(),
            ], 500);
        }
    }
} 
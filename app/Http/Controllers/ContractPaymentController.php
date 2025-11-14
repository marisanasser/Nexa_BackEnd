<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\BrandPaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;
use App\Services\PaymentSimulator;

class ContractPaymentController extends Controller
{
    private ?string $stripeKey = null;

    public function __construct()
    {
        $this->stripeKey = config('services.stripe.secret');
        
        // Log simulation mode status
        if (PaymentSimulator::isSimulationMode()) {
            Log::info('Contract payment simulation mode is ENABLED - All contract payments will be simulated');
        }
    }

    /**
     * Process payment when contract is started
     */
    public function processContractPayment(Request $request): JsonResponse
    {
        $user = auth()->user();

        // Check if user is a brand
        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can process contract payments',
            ], 403);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'contract_id' => 'required|exists:contracts,id,brand_id,' . $user->id,
            // When using Stripe directly, expect a Stripe PaymentMethod id
            'stripe_payment_method_id' => 'nullable|string',
            // Legacy brand payment method (Pagar.me) will be ignored when Stripe is configured
            'payment_method_id' => 'nullable|exists:brand_payment_methods,id,brand_id,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $contract = Contract::with(['brand', 'creator'])->find($request->contract_id);

        // Check if contract can be paid
        if ($contract->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Contract is not in active status',
            ], 400);
        }

        // Check if payment was already processed
        if ($contract->payment && $contract->payment->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Payment for this contract has already been processed',
            ], 400);
        }

        // If Stripe is configured, require a Stripe payment method id from frontend
        if ($this->stripeKey) {
            if (!$request->filled('stripe_payment_method_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'stripe_payment_method_id is required when using Stripe',
                ], 422);
            }
        }

        // Check if simulation mode is enabled
        if (PaymentSimulator::isSimulationMode()) {
            Log::info('Processing contract payment in SIMULATION mode', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
                'simulation_mode' => true
            ]);
            
            try {
                DB::beginTransaction();

                // Use PaymentSimulator to process the contract payment
                $simulationResult = PaymentSimulator::simulateContractPayment([
                    'amount' => $contract->budget,
                    'contract_id' => $contract->id,
                    'description' => 'Contract: ' . $contract->title,
                ], $user);
                
                if (!$simulationResult['success']) {
                    throw new \Exception($simulationResult['message'] ?? 'Simulation failed');
                }

                // Create transaction record
                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'stripe_payment_intent_id' => $simulationResult['transaction_id'],
                    'status' => 'paid',
                    'amount' => $contract->budget,
                    'payment_method' => 'credit_card',
                    'card_brand' => null,
                    'card_last4' => null,
                    'card_holder_name' => null,
                    'payment_data' => [
                        'simulation' => true,
                        'contract_id' => $contract->id,
                        'processed_at' => now()->toISOString(),
                    ],
                    'paid_at' => now(),
                    'contract_id' => $contract->id,
                ]);

                // Create job payment record
                $jobPayment = \App\Models\JobPayment::create([
                    'contract_id' => $contract->id,
                    'brand_id' => $contract->brand_id,
                    'creator_id' => $contract->creator_id,
                    'total_amount' => $contract->budget,
                    'platform_fee' => $contract->budget * 0.05, // 5% platform fee
                    'creator_amount' => $contract->budget * 0.95, // 95% for creator
                    'payment_method' => 'credit_card',
                    'status' => 'paid',
                    'transaction_id' => $transaction->id,
                ]);

                // Update contract status
                $contract->update([
                    'status' => 'active',
                    'workflow_status' => 'active',
                    'started_at' => now(),
                ]);

                DB::commit();

                // Notify both parties
                NotificationService::notifyCreatorOfContractStarted($contract);
                NotificationService::notifyBrandOfContractStarted($contract);

                Log::info('SIMULATION: Contract payment processed successfully', [
                    'contract_id' => $contract->id,
                    'brand_id' => $user->id,
                    'creator_id' => $contract->creator_id,
                    'amount' => $contract->budget,
                    'transaction_id' => $transaction->id,
                    'simulation_mode' => true
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Contract payment processed successfully (SIMULATION)',
                    'simulation' => true,
                    'data' => [
                        'contract_id' => $contract->id,
                        'amount' => $contract->budget,
                        'payment_status' => 'paid',
                        'transaction_id' => $transaction->id,
                    ]
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                
                Log::error('SIMULATION: Contract payment processing error', [
                    'contract_id' => $contract->id,
                    'brand_id' => $user->id,
                    'error' => $e->getMessage(),
                    'simulation_mode' => true
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Contract payment failed: ' . $e->getMessage(),
                ], 500);
            }
        }

        // Use Stripe for real processing
        if (!$this->stripeKey) {
            Log::error('Stripe secret not configured for contract payment', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Payment gateway not configured. Please contact support.',
            ], 503);
        }

        try {
            Log::info('Processing contract payment with Stripe', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
                'creator_id' => $contract->creator_id,
                'amount' => $contract->budget,
                'payment_method_id' => $request->stripe_payment_method_id,
            ]);
            
            DB::beginTransaction();
            \Stripe\Stripe::setApiKey($this->stripeKey);

            // Ensure Stripe customer for brand
            if (!$user->stripe_customer_id) {
                Log::info('Creating Stripe customer for brand', [
                    'brand_id' => $user->id,
                    'email' => $user->email,
                ]);
                
                $customer = \Stripe\Customer::create([
                    'email' => $user->email,
                    'metadata' => [ 'user_id' => $user->id, 'role' => 'brand' ],
                ]);
                $user->update(['stripe_customer_id' => $customer->id]);
                
                Log::info('Stripe customer created for brand', [
                    'brand_id' => $user->id,
                    'customer_id' => $customer->id,
                ]);
            } else {
                Log::info('Retrieving existing Stripe customer for brand', [
                    'brand_id' => $user->id,
                    'customer_id' => $user->stripe_customer_id,
                ]);
                
                $customer = \Stripe\Customer::retrieve($user->stripe_customer_id);
            }

            // Attach PM if needed and set as default for invoices
            $pmId = $request->string('stripe_payment_method_id')->toString();
            
            Log::info('Attaching payment method to customer', [
                'customer_id' => $customer->id,
                'payment_method_id' => $pmId,
            ]);
            
            \Stripe\PaymentMethod::attach($pmId, ['customer' => $customer->id]);

            // Store payment method ID in user model for quick access using direct DB update
            if ($user->stripe_payment_method_id !== $pmId) {
                $updatedRows = DB::table('users')
                    ->where('id', $user->id)
                    ->update(['stripe_payment_method_id' => $pmId]);
                
                // Verify the update
                $actualStoredId = DB::table('users')
                    ->where('id', $user->id)
                    ->value('stripe_payment_method_id');
                
                if ($actualStoredId !== $pmId) {
                    // Fallback to Eloquent if direct DB update failed
                    $user->refresh();
                    $user->stripe_payment_method_id = $pmId;
                    $user->save();
                    $user->refresh();
                } else {
                    $user->refresh();
                }
                
                Log::info('Updated user stripe_payment_method_id after attaching payment method', [
                    'user_id' => $user->id,
                    'stripe_payment_method_id' => $pmId,
                    'updated_rows' => $updatedRows,
                    'verified' => ($user->stripe_payment_method_id === $pmId),
                ]);
            }

            Log::info('Creating Stripe PaymentIntent for contract', [
                'contract_id' => $contract->id,
                'customer_id' => $customer->id,
                'payment_method_id' => $pmId,
                'amount_cents' => (int) round($contract->budget * 100),
                'amount_decimal' => $contract->budget,
                'currency' => 'brl',
            ]);

            // Create PaymentIntent and confirm
            $intent = \Stripe\PaymentIntent::create([
                'amount' => (int) round($contract->budget * 100),
                'currency' => 'brl',
                'customer' => $customer->id,
                'payment_method' => $pmId,
                'confirm' => true,
                'confirmation_method' => 'automatic',
                'description' => 'Contract #' . $contract->id . ' - ' . ($contract->title ?? 'Campaign'),
                'metadata' => [
                    'contract_id' => (string) $contract->id,
                    'brand_id' => (string) $user->id,
                    'creator_id' => (string) $contract->creator_id,
                ],
            ]);
            
            Log::info('Stripe PaymentIntent created', [
                'payment_intent_id' => $intent->id,
                'status' => $intent->status ?? 'unknown',
                'amount' => $intent->amount ?? 0,
                'currency' => $intent->currency ?? 'unknown',
                'contract_id' => $contract->id,
            ]);

            if (!in_array($intent->status, ['succeeded', 'requires_action', 'processing'])) {
                Log::error('Stripe PaymentIntent failed', [
                    'contract_id' => $contract->id,
                    'payment_intent_id' => $intent->id,
                    'status' => $intent->status,
                    'failure_code' => $intent->last_payment_error->code ?? 'unknown',
                    'failure_message' => $intent->last_payment_error->message ?? 'unknown',
                ]);
                
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Payment failed',
                    'stripe_status' => $intent->status,
                ], 400);
            }
            
            Log::info('Stripe PaymentIntent status acceptable', [
                'contract_id' => $contract->id,
                'payment_intent_id' => $intent->id,
                'status' => $intent->status,
            ]);

            // Create transaction record
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $intent->id,
                'stripe_charge_id' => $intent->latest_charge ?? null,
                'status' => $intent->status === 'succeeded' ? 'paid' : $intent->status,
                'amount' => $contract->budget,
                'payment_method' => 'stripe',
                'payment_data' => ['intent' => $intent->toArray()],
                'paid_at' => $intent->status === 'succeeded' ? now() : null,
                'contract_id' => $contract->id,
            ]);

            // Create job payment record
            $jobPayment = \App\Models\JobPayment::create([
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'creator_id' => $contract->creator_id,
                'total_amount' => $contract->budget,
                'platform_fee' => $contract->budget * 0.05, // 5% platform fee
                'creator_amount' => $contract->budget * 0.95, // 95% for creator
                'payment_method' => 'stripe_escrow',
                // Brand was charged, but creator's payment is pending (escrow)
                'status' => 'pending',
                'transaction_id' => $transaction->id,
            ]);

            // Credit pending balance to creator escrow
            $balance = \App\Models\CreatorBalance::firstOrCreate(
                ['creator_id' => $contract->creator_id],
                [
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'total_earned' => 0,
                    'total_withdrawn' => 0,
                ]
            );
            $balance->increment('pending_balance', $jobPayment->creator_amount);

            // Update contract status
            $contract->update([
                'status' => 'active',
                'workflow_status' => 'active',
                'started_at' => now(),
            ]);

            DB::commit();

            // Notify both parties
            NotificationService::notifyCreatorOfContractStarted($contract);
            NotificationService::notifyBrandOfContractStarted($contract);

            Log::info('Contract payment processed successfully', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
                'creator_id' => $contract->creator_id,
                'amount' => $contract->budget,
                'transaction_id' => $transaction->id,
                'payment_status' => $transaction->status
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Contract payment processed successfully',
                'data' => [
                    'contract_id' => $contract->id,
                    'amount' => $contract->budget,
                    'payment_status' => $transaction->status,
                    'transaction_id' => $transaction->id,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error processing contract payment', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing the payment. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get contract payment status
     */
    public function getContractPaymentStatus(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'contract_id' => 'required|exists:contracts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $contract = Contract::with(['payment', 'payment.transaction'])->find($request->contract_id);

        // Check if user has access to this contract
        if ($contract->brand_id !== $user->id && $contract->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied',
            ], 403);
        }

        $paymentData = null;
        if ($contract->payment) {
            $paymentData = [
                'status' => $contract->payment->status,
                'total_amount' => $contract->payment->total_amount,
                'platform_fee' => $contract->payment->platform_fee,
                'creator_amount' => $contract->payment->creator_amount,
                'payment_method' => $contract->payment->payment_method,
                'created_at' => $contract->payment->created_at,
                'transaction' => $contract->payment->transaction ? [
                    'id' => $contract->payment->transaction->id,
                    'status' => $contract->payment->transaction->status,
                    'paid_at' => $contract->payment->transaction->paid_at,
                ] : null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'contract_id' => $contract->id,
                'contract_status' => $contract->status,
                'workflow_status' => $contract->workflow_status,
                'budget' => $contract->budget,
                'payment' => $paymentData,
            ]
        ]);
    }

    /**
     * Get available payment methods for contract
     */
    public function getAvailablePaymentMethods(Request $request): JsonResponse
    {
        $user = auth()->user();

        // Check if user is a brand
        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can access payment methods',
            ], 403);
        }

        $paymentMethods = BrandPaymentMethod::where('brand_id', $user->id)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $paymentMethods->map(function ($method) {
                return [
                    'id' => $method->id,
                    'card_brand' => $method->card_brand,
                    'card_last4' => $method->card_last4,
                    'card_holder_name' => $method->card_holder_name,
                    'is_default' => $method->is_default,
                    'formatted_info' => $method->formatted_card_info,
                ];
            }),
        ]);
    }

    /**
     * Retry payment for failed contract
     */
    public function retryPayment(Request $request): JsonResponse
    {
        $user = auth()->user();

        // Check if user is a brand
        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can retry payments',
            ], 403);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'contract_id' => 'required|exists:contracts,id,brand_id,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $contract = Contract::with(['brand', 'creator'])->find($request->contract_id);

        // Check if contract payment failed
        if (!$contract->isPaymentFailed()) {
            return response()->json([
                'success' => false,
                'message' => 'Contract is not in payment failed status',
            ], 400);
        }

        // Retry payment
        $success = $contract->retryPayment();

        if ($success) {
            return response()->json([
                'success' => true,
                'message' => 'Payment retry successful',
                'data' => [
                    'contract_id' => $contract->id,
                    'status' => $contract->status,
                    'workflow_status' => $contract->workflow_status,
                ],
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Payment retry failed. Please check your payment method.',
            ], 400);
        }
    }

    /**
     * Create Stripe Checkout Session for contract funding (escrow deposit)
     */
    public function createContractCheckoutSession(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can create contract checkout sessions',
                ], 403);
            }

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'contract_id' => 'required|exists:contracts,id,brand_id,' . $user->id,
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $contract = Contract::with(['brand', 'creator'])->find($request->contract_id);

            // Check if contract needs payment
            if ($contract->status !== 'pending' || $contract->workflow_status !== 'payment_pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Contract is not in a state that requires payment',
                ], 400);
            }

            // Check if payment was already processed
            if ($contract->payment && $contract->payment->status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment for this contract has already been processed',
                ], 400);
            }

            if (!$this->stripeKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe is not configured',
                ], 503);
            }

            // Set Stripe API key
            \Stripe\Stripe::setApiKey($this->stripeKey);

            // Ensure Stripe customer exists for the brand
            $customerId = $user->stripe_customer_id;
            
            if (!$customerId) {
                Log::info('Creating new Stripe customer for contract funding', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);

                $customer = \Stripe\Customer::create([
                    'email' => $user->email,
                    'name' => $user->name,
                    'metadata' => [
                        'user_id' => $user->id,
                        'role' => 'brand',
                    ],
                ]);

                $customerId = $customer->id;
                $user->update(['stripe_customer_id' => $customerId]);

                Log::info('Stripe customer created for contract funding', [
                    'user_id' => $user->id,
                    'customer_id' => $customerId,
                ]);
            } else {
                // Verify customer exists
                try {
                    \Stripe\Customer::retrieve($customerId);
                } catch (\Exception $e) {
                    Log::warning('Stripe customer not found, creating new one', [
                        'user_id' => $user->id,
                        'old_customer_id' => $customerId,
                    ]);

                    $customer = \Stripe\Customer::create([
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
            $frontendUrl = config('app.frontend_url', 'http://localhost:5000');

            // Create Checkout Session in payment mode (not setup mode)
            $checkoutSession = \Stripe\Checkout\Session::create([
                'customer' => $customerId,
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'locale' => 'pt-BR',
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'brl',
                        'product_data' => [
                            'name' => 'Contract Funding: ' . $contract->title,
                            'description' => 'Escrow deposit for contract #' . $contract->id,
                        ],
                        'unit_amount' => (int) round($contract->budget * 100), // Convert to cents
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => $frontendUrl . '/brand?component=Pagamentos&funding_success=true&session_id={CHECKOUT_SESSION_ID}&contract_id=' . $contract->id,
                'cancel_url' => $frontendUrl . '/brand?component=Pagamentos&funding_canceled=true&contract_id=' . $contract->id,
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'type' => 'contract_funding',
                    'contract_id' => (string) $contract->id,
                ],
            ]);

            Log::info('Contract funding checkout session created', [
                'session_id' => $checkoutSession->id,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'contract_id' => $contract->id,
                'amount' => $contract->budget,
            ]);

            return response()->json([
                'success' => true,
                'url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create contract funding checkout session', [
                'user_id' => auth()->id(),
                'contract_id' => $request->contract_id ?? null,
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
     * Get transaction history for the authenticated user
     */
    public function getTransactionHistory(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $perPage = min($request->get('per_page', 10), 100);
            $page = $request->get('page', 1);

            $transactions = $user->transactions()
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $transactions->getCollection()->transform(function ($transaction) {
                // Get pagarme_transaction_id from payment_data or use stripe_payment_intent_id as fallback
                $paymentData = $transaction->payment_data ?? [];
                $pagarmeTransactionId = $paymentData['pagarme_transaction_id'] 
                    ?? $paymentData['transaction_id'] 
                    ?? $transaction->stripe_payment_intent_id 
                    ?? $transaction->stripe_charge_id 
                    ?? null;

                return [
                    'id' => $transaction->id,
                    'pagarme_transaction_id' => $pagarmeTransactionId ?? '',
                    'status' => $transaction->status,
                    'amount' => (string) $transaction->amount, // Ensure it's a string
                    'payment_method' => $transaction->payment_method ?? '',
                    'card_brand' => $transaction->card_brand ?? '',
                    'card_last4' => $transaction->card_last4 ?? '',
                    'card_holder_name' => $transaction->card_holder_name ?? '',
                    'payment_data' => $transaction->payment_data ?? [],
                    'paid_at' => $transaction->paid_at?->format('Y-m-d H:i:s') ?? '',
                    'expires_at' => $transaction->expires_at?->format('Y-m-d H:i:s') ?? '',
                    'created_at' => $transaction->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $transaction->updated_at->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'transactions' => $transactions->items(),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                    'from' => $transactions->firstItem(),
                    'to' => $transactions->lastItem(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching transaction history', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transaction history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
} 
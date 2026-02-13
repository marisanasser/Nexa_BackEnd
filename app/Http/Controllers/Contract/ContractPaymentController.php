<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contract;

use App\Domain\Notification\Services\ContractNotificationService;
use App\Domain\Payment\Services\ContractPaymentService;
use App\Domain\Payment\Services\PaymentSimulator;
use App\Domain\Payment\Services\StripeCustomerService;
use App\Http\Controllers\Base\Controller;
use App\Models\Contract\Contract;
use App\Models\Payment\BrandPaymentMethod;
use App\Models\Payment\JobPayment;
use App\Models\Payment\Transaction;
use App\Models\User\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Stripe\Stripe;

use function in_array;

class ContractPaymentController extends Controller
{
    private ?string $stripeKey = null;

    public function __construct(
        private readonly ContractPaymentService $paymentService,
        private readonly StripeCustomerService $customerService
    ) {
        $this->stripeKey = config('services.stripe.secret');

        if (PaymentSimulator::isSimulationMode()) {
            Log::info('Contract payment simulation mode is ENABLED - All contract payments will be simulated');
        }
    }

    public function processContractPayment(Request $request): JsonResponse
    {
        $userId = auth()->id();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $user = User::findOrFail($userId);

        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can process contract payments',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'contract_id' => "required|exists:contracts,id,brand_id,{$user->id}",

            'stripe_payment_method_id' => 'nullable|string',

            'payment_method_id' => "nullable|exists:brand_payment_methods,id,brand_id,{$user->id}",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $contract = Contract::with(['brand', 'creator'])->find($request->integer('contract_id'));

        if ('active' !== $contract->status) {
            return response()->json([
                'success' => false,
                'message' => 'Contract is not in active status',
            ], 400);
        }

        if ($contract->payment && 'completed' === $contract->payment->status) {
            return response()->json([
                'success' => false,
                'message' => 'Payment for this contract has already been processed',
            ], 400);
        }

        if (PaymentSimulator::isSimulationMode()) {
            return $this->handleSimulatedContractPayment($user, $contract);
        }

        return $this->handleStripeContractPayment($user, $contract, $request);
    }

    public function getContractPaymentStatus(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        $validator = Validator::make($request->all(), [
            'contract_id' => 'required|exists:contracts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $contract = Contract::with(['payment', 'payment.transaction'])->find($request->integer('contract_id'));

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
            ],
        ]);
    }

    public function getAvailablePaymentMethods(Request $request): JsonResponse
    {
        $userId = auth()->id();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $user = User::findOrFail($userId);

        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can access payment methods',
            ], 403);
        }

        $paymentMethods = BrandPaymentMethod::where('brand_id', $user->id)
            ->where('is_active', true)
            ->get()
        ;

        return response()->json([
            'success' => true,
            'data' => $paymentMethods->map(fn($method) => [
                'id' => $method->id,
                'card_brand' => $method->card_brand,
                'card_last4' => $method->card_last4,
                'card_holder_name' => $method->card_holder_name,
                'is_default' => $method->is_default,
                'formatted_info' => $method->formatted_card_info,
            ]),
        ]);
    }

    public function retryPayment(Request $request): JsonResponse
    {
        $userId = auth()->id();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $user = User::findOrFail($userId);

        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can retry payments',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'contract_id' => 'required|exists:contracts,id,brand_id,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $contract = Contract::with(['brand', 'creator'])->find($request->integer('contract_id'));

        if (!$contract->isPaymentFailed()) {
            return response()->json([
                'success' => false,
                'message' => 'Contract is not in payment failed status',
            ], 400);
        }

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
        }

        return response()->json([
            'success' => false,
            'message' => 'Payment retry failed. Please check your payment method.',
        ], 400);
    }

    public function getBrandTransactionHistory(Request $request): JsonResponse
    {
        $userId = auth()->id();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $user = User::findOrFail($userId);

        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can access transaction history',
            ], 403);
        }

        $query = Transaction::where('user_id', $userId)
            ->whereNotNull('contract_id') // Only contract transactions
            ->with(['contract', 'contract.creator']);

        $perPage = $request->integer('per_page', 10);
        
        $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'transactions' => collect($transactions->items())->map(function ($transaction) {
               return [
                   'id' => $transaction->id,
                   'contract_id' => $transaction->contract_id,
                   'contract_title' => $transaction->contract?->title ?? 'Contrato Removido',
                   'contract_budget' => $transaction->contract?->budget ?? 0,
                   'creator' => $transaction->contract?->creator ? [
                       'id' => $transaction->contract->creator->id,
                       'name' => $transaction->contract->creator->name,
                       'email' => $transaction->contract->creator->email,
                   ] : null,
                   'pagarme_transaction_id' => $transaction->pagarme_transaction_id,
                   'stripe_payment_intent_id' => $transaction->stripe_payment_intent_id,
                   'stripe_charge_id' => $transaction->stripe_charge_id,
                   'status' => $transaction->status,
                   'amount' => $transaction->amount,
                   'payment_method' => $transaction->payment_method,
                   'card_brand' => $transaction->card_brand,
                   'card_last4' => $transaction->card_last4,
                   'card_holder_name' => $transaction->card_holder_name,
                   'paid_at' => $transaction->paid_at?->toISOString(),
                   'created_at' => $transaction->created_at?->toISOString(),
               ];
            }),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'from' => $transactions->firstItem(),
                'to' => $transactions->lastItem(),
            ],
        ]);
    }

    /**
     * Handle contract payment in simulation mode.
     */
    private function handleSimulatedContractPayment(User $user, Contract $contract): JsonResponse
    {
        Log::info('Processing contract payment in SIMULATION mode', [
            'contract_id' => $contract->id,
            'brand_id' => $user->id,
            'simulation_mode' => true,
        ]);

        try {
            DB::beginTransaction();

            $simulationResult = PaymentSimulator::simulateContractPayment([
                'amount' => $contract->budget,
                'contract_id' => $contract->id,
                'description' => 'Contract: ' . $contract->title,
            ], $user);

            if (!$simulationResult['success']) {
                throw new Exception($simulationResult['message'] ?? 'Simulation failed');
            }

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

            $platformFee = $contract->budget * ContractPaymentService::PLATFORM_FEE_PERCENTAGE;
            $creatorAmount = $contract->budget * (1 - ContractPaymentService::PLATFORM_FEE_PERCENTAGE);

            $jobPayment = JobPayment::create([
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'creator_id' => $contract->creator_id,
                'total_amount' => $contract->budget,
                'platform_fee' => $platformFee,
                'creator_amount' => $creatorAmount,
                'payment_method' => 'credit_card',
                'status' => 'paid',
                'transaction_id' => $transaction->id,
            ]);

            $contract->update([
                'status' => 'active',
                'workflow_status' => 'active',
                'started_at' => now(),
            ]);

            DB::commit();

            ContractNotificationService::notifyCreatorOfContractStarted($contract);
            ContractNotificationService::notifyBrandOfContractStarted($contract);

            Log::info('SIMULATION: Contract payment processed successfully', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
                'creator_id' => $contract->creator_id,
                'amount' => $contract->budget,
                'transaction_id' => $transaction->id,
                'simulation_mode' => true,
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
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('SIMULATION: Contract payment processing error', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
                'error' => $e->getMessage(),
                'simulation_mode' => true,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Contract payment failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function handleStripeContractPayment(User $user, Contract $contract, Request $request): JsonResponse
    {
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
            $stripePaymentMethodId = $request->string('stripe_payment_method_id')->toString();

            Log::info('Processing contract payment with Stripe', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
                'creator_id' => $contract->creator_id,
                'amount' => $contract->budget,
                'payment_method_id' => $stripePaymentMethodId,
            ]);

            DB::beginTransaction();

            // Use services instead of direct Stripe and private methods
            $customerId = $this->customerService->ensureStripeCustomer($user);
            $this->paymentService->attachPaymentMethodToCustomer($user, $customerId, $stripePaymentMethodId);

            $intent = $this->paymentService->createPaymentIntent($contract, $customerId, $stripePaymentMethodId, $user);

            if (!in_array($intent->status, ['succeeded', 'requires_action', 'processing'])) {
                return $this->handleFailedPaymentIntent($contract, $intent);
            }

            $transaction = $this->paymentService->recordContractTransaction($user, $contract, $intent);
            $this->paymentService->recordContractJobPayment($contract, $transaction);
            $this->paymentService->updateCreatorBalance($contract);
            $this->paymentService->activateContract($contract);

            DB::commit();

            Log::info('Contract payment processed successfully', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
                'creator_id' => $contract->creator_id,
                'amount' => $contract->budget,
                'transaction_id' => $transaction->id,
                'payment_status' => $transaction->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Contract payment processed successfully',
                'data' => [
                    'contract_id' => $contract->id,
                    'amount' => $contract->budget,
                    'payment_status' => $transaction->status,
                    'transaction_id' => $transaction->id,
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error processing contract payment', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing the payment. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle a failed PaymentIntent response.
     *
     * @param mixed $intent
     */
    private function handleFailedPaymentIntent(Contract $contract, $intent): JsonResponse
    {
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

            $perPage = min($request->integer('per_page', 10), 100);
            $page = $request->integer('page', 1);

            $transactions = Transaction::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $transformedTransactions = collect($transactions->items())->map(function ($transaction) {
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
                'transactions' => $transformedTransactions,
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                    'from' => $transactions->firstItem(),
                    'to' => $transactions->lastItem(),
                ],
            ]);

        } catch (Exception $e) {
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

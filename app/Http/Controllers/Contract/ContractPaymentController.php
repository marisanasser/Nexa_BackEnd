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
use App\Models\Payment\Subscription;
use App\Models\Payment\Transaction;
use App\Models\User\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

        if ('pending' !== $contract->status || 'payment_pending' !== $contract->workflow_status) {
            return response()->json([
                'success' => false,
                'message' => 'Contract is not in a state that requires funding',
            ], 400);
        }

        if ($contract->payment && in_array($contract->payment->status, ['pending', 'processing', 'completed'], true)) {
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
            ->with(['contract', 'contract.creator']);

        $perPage = $request->integer('per_page', 10);
        
        $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'transactions' => collect($transactions->items())->map(function ($transaction) {
               $paymentData = is_array($transaction->payment_data) ? $transaction->payment_data : [];
               $transactionReference = $paymentData['pagarme_transaction_id']
                   ?? $paymentData['transaction_id']
                   ?? $transaction->pagarme_transaction_id
                   ?? $transaction->stripe_payment_intent_id
                   ?? $transaction->stripe_charge_id
                   ?? null;
               $category = $this->resolveTransactionCategory($transaction);

               return [
                   'id' => $transaction->id,
                   'contract_id' => $transaction->contract_id,
                   'contract_title' => $transaction->contract?->title
                       ?? $this->resolveBrandTransactionTitle($transaction, $category),
                   'contract_budget' => $transaction->contract?->budget ?? $transaction->amount,
                   'creator' => $transaction->contract?->creator ? [
                       'id' => $transaction->contract->creator->id,
                       'name' => $transaction->contract->creator->name,
                       'email' => $transaction->contract->creator->email,
                   ] : null,
                   'transaction_category' => $category,
                   'transaction_label' => $this->resolveBrandTransactionTitle($transaction, $category),
                   'transaction_reference' => $transactionReference ?? '',
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
                   'processed_at' => $transaction->paid_at?->toISOString() ?? $transaction->created_at?->toISOString(),
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

    private function resolveBrandTransactionTitle(Transaction $transaction, string $category): string
    {
        if (null !== $transaction->contract?->title) {
            return $transaction->contract->title;
        }

        if (str_contains($category, 'contract')) {
            return 'Pagamento de contrato';
        }

        if (str_contains($category, 'offer_funding') || str_contains($category, 'platform_funding')) {
            return 'Recarga de saldo da marca';
        }

        if (str_contains($category, 'subscription')) {
            return 'Assinatura';
        }

        if (str_contains($category, 'withdrawal')) {
            return 'Saque';
        }

        return "Transação #{$transaction->id}";
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
                'status' => 'pending',
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

            $transactionItems = collect($transactions->items());
            $subscriptionByTransactionId = collect();

            if ($transactionItems->isNotEmpty()) {
                try {
                    if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'transaction_id')) {
                        $subscriptionByTransactionId = Subscription::query()
                            ->whereIn('transaction_id', $transactionItems->pluck('id')->filter()->values()->all())
                            ->get()
                            ->keyBy('transaction_id');
                    }
                } catch (\Throwable $subscriptionError) {
                    Log::warning('Unable to load subscriptions for transaction history enrichment', [
                        'user_id' => $user->id,
                        'error' => $subscriptionError->getMessage(),
                    ]);
                }
            }

            $transformedTransactions = $transactionItems
                ->map(function ($transaction) use ($user, $subscriptionByTransactionId) {
                    try {
                        // Resolve generic transaction reference using Stripe identifiers.
                        $paymentData = is_array($transaction->payment_data) ? $transaction->payment_data : [];
                        $subscription = $subscriptionByTransactionId->get($transaction->id);
                        $transactionReference = $paymentData['pagarme_transaction_id']
                            ?? $paymentData['transaction_id']
                            ?? $transaction->stripe_payment_intent_id
                            ?? $transaction->stripe_charge_id
                            ?? null;
                        $transactionCategory = $this->resolveTransactionCategory($transaction);

                        $cardHolderName = $this->pickFirstNonEmptyString([
                            $transaction->card_holder_name,
                            $paymentData['card_holder_name'] ?? null,
                            data_get($paymentData, 'billing_details.name'),
                            data_get($paymentData, 'payment_method.billing_details.name'),
                            data_get($paymentData, 'charges.data.0.billing_details.name'),
                            'subscription' === $transactionCategory ? $user->name : null,
                        ]);

                        $cardBrand = $this->pickFirstNonEmptyString([
                            $transaction->card_brand,
                            $paymentData['card_brand'] ?? null,
                            data_get($paymentData, 'card.brand'),
                            data_get($paymentData, 'payment_method_details.card.brand'),
                            data_get($paymentData, 'charges.data.0.payment_method_details.card.brand'),
                        ]);

                        $cardLast4 = $this->pickFirstNonEmptyString([
                            $transaction->card_last4,
                            $paymentData['card_last4'] ?? null,
                            data_get($paymentData, 'card.last4'),
                            data_get($paymentData, 'payment_method_details.card.last4'),
                            data_get($paymentData, 'charges.data.0.payment_method_details.card.last4'),
                        ]);

                        $expiresAt = $transaction->expires_at;
                        if (!$expiresAt && $subscription?->expires_at) {
                            $expiresAt = $subscription->expires_at;
                        }

                        if (!$expiresAt) {
                            $rawExpiresAt = $paymentData['expires_at'] ?? data_get($paymentData, 'subscription_expires_at');
                            if (is_string($rawExpiresAt) && '' !== trim($rawExpiresAt)) {
                                try {
                                    $expiresAt = Carbon::parse($rawExpiresAt);
                                } catch (\Throwable) {
                                    $expiresAt = null;
                                }
                            }
                        }

                        if (!$expiresAt && 'subscription' === $transactionCategory && $user->premium_expires_at) {
                            $expiresAt = $user->premium_expires_at;
                        }

                        return [
                            'id' => $transaction->id,
                            'contract_id' => $transaction->contract_id,
                            'transaction_category' => $transactionCategory,
                            'transaction_reference' => (string) ($transactionReference ?? ''),
                            'status' => (string) ($transaction->status ?? ''),
                            'amount' => (string) ($transaction->amount ?? '0'),
                            'payment_method' => (string) ($transaction->payment_method ?? ''),
                            'card_brand' => $cardBrand,
                            'card_last4' => $cardLast4,
                            'card_holder_name' => $cardHolderName,
                            'payment_data' => $paymentData,
                            'paid_at' => $transaction->paid_at?->format('Y-m-d H:i:s') ?? '',
                            'expires_at' => $expiresAt?->format('Y-m-d H:i:s') ?? '',
                            'created_at' => $transaction->created_at?->format('Y-m-d H:i:s') ?? '',
                            'updated_at' => $transaction->updated_at?->format('Y-m-d H:i:s') ?? '',
                        ];
                    } catch (\Throwable $itemError) {
                        Log::warning('Skipping malformed transaction in history response', [
                            'user_id' => $user->id,
                            'transaction_id' => $transaction->id ?? null,
                            'error' => $itemError->getMessage(),
                        ]);

                        return null;
                    }
                })
                ->filter()
                ->values();

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

    private function resolveTransactionCategory(Transaction $transaction): string
    {
        $paymentData = is_array($transaction->payment_data) ? $transaction->payment_data : [];
        $metadata = isset($paymentData['metadata']) && is_array($paymentData['metadata'])
            ? $paymentData['metadata']
            : [];

        $declaredType = $paymentData['type'] ?? $metadata['type'] ?? null;
        if (is_string($declaredType) && '' !== trim($declaredType)) {
            return $declaredType;
        }

        if (null !== $transaction->contract_id) {
            return 'contract_funding';
        }

        $reference = (string) ($transaction->stripe_payment_intent_id ?? '');
        if (str_starts_with($reference, 'in_')
            || array_key_exists('subscription', $paymentData)
            || array_key_exists('invoice', $paymentData)
        ) {
            return 'subscription';
        }

        if ((float) $transaction->amount < 0) {
            return 'withdrawal';
        }

        return 'payment';
    }

    /**
     * @param array<int, mixed> $values
     */
    private function pickFirstNonEmptyString(array $values): string
    {
        foreach ($values as $value) {
            if (is_string($value)) {
                $normalized = trim($value);
                if ('' !== $normalized) {
                    return $normalized;
                }

                continue;
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return '';
    }
}

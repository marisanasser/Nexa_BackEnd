<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Notification\Services\ContractNotificationService;
use App\Models\Contract\Contract;
use App\Models\Payment\CreatorBalance;
use App\Models\Payment\JobPayment;
use App\Models\Payment\Transaction;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;

/**
 * ContractPaymentService handles contract payment operations.
 *
 * Responsibilities:
 * - Creating contract funding checkout sessions
 * - Processing contract payments
 * - Managing creator balances
 * - Handling payment completion
 */
class ContractPaymentService
{
    public const PLATFORM_FEE_PERCENTAGE = 0.05; // 5%
        
    public function __construct(
        private StripeWrapper $stripeWrapper,
        private StripeCustomerService $customerService
    ) {
    }

    /**
     * Create a checkout session for funding a contract.
     */
    public function createContractFundingCheckout(
        Contract $contract,
        User $brand,
        string $successUrl,
        string $cancelUrl
    ): Session {
        $customerId = $this->customerService->ensureStripeCustomer($brand);

        $amount = $this->calculateTotalAmount((float) ($contract->budget ?? 0));

        return $this->stripeWrapper->createCheckoutSession([
            'customer' => $customerId,
            'mode' => 'payment',
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => 'brl',
                        'product_data' => [
                            'name' => "Contract: {$contract->title}",
                            'description' => "Payment for contract #{$contract->id}",
                        ],
                        'unit_amount' => (int) ($amount * 100), // Convert to cents
                    ],
                    'quantity' => 1,
                ]
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => (string) $brand->id,
                'contract_id' => (string) $contract->id,
                'type' => 'contract_funding',
                'amount' => (string) $amount,
            ],
        ]);
    }

    /**
     * Handle successful contract funding checkout.
     */
    public function handleContractFundingCompleted(Session $session): void
    {
        $contractId = $session->metadata->contract_id ?? null;
        $userId = $session->metadata->user_id ?? null;

        if (!$contractId) {
            Log::error('Missing contract_id in checkout session', ['session_id' => $session->id]);

            return;
        }

        $contract = Contract::find($contractId);
        if (!$contract) {
            Log::error('Contract not found for funding checkout', ['contract_id' => $contractId]);

            return;
        }

        DB::transaction(function () use ($contract, $session, $userId): void {
            $this->processContractPayment($contract, $session, $userId);
        });
    }

    /**
     * Release payment to creator when contract is completed.
     */
    public function releasePaymentToCreator(Contract $contract): void
    {
        $jobPayment = JobPayment::where('contract_id', $contract->id)
            ->where('status', 'held')
            ->first()
        ;

        if (!$jobPayment) {
            throw new Exception('No held payment found for this contract');
        }

        DB::transaction(function () use ($contract, $jobPayment): void {
            // Update job payment status
            $jobPayment->update([
                'status' => 'completed',
                'released_at' => now(),
            ]);

            // Add to creator balance
            $this->addToCreatorBalance(
                $contract->creator_id,
                (float) ($jobPayment->creator_amount ?? 0),
                $contract->id,
                $jobPayment->id
            );

            // Update contract workflow status
            $contract->update([
                'workflow_status' => 'payment_available',
            ]);

            Log::info('Payment released to creator', [
                'contract_id' => $contract->id,
                'creator_id' => $contract->creator_id,
                'amount' => $jobPayment->creator_amount,
            ]);

            // TODO: Implement notification
            // PaymentNotificationService::notifyPaymentReleased($contract, $jobPayment);
        });
    }

    /**
     * Calculate total amount including platform fee.
     */
    public function calculateTotalAmount(float $baseAmount): float
    {
        return $baseAmount * (1 + self::PLATFORM_FEE_PERCENTAGE);
    }

    /**
     * Calculate platform fee from total amount.
     */
    public function calculatePlatformFee(float $totalAmount): float
    {
        return $totalAmount * self::PLATFORM_FEE_PERCENTAGE;
    }

    /**
     * Refund a contract payment.
     */
    public function refundContractPayment(Contract $contract, ?string $reason = null): void
    {
        $jobPayment = JobPayment::where('contract_id', $contract->id)
            ->whereIn('status', ['held', 'completed'])
            ->first()
        ;

        if (!$jobPayment || !$jobPayment->stripe_payment_intent_id) {
            throw new Exception('No refundable payment found for this contract');
        }

        try {
            // Refund in Stripe
            $this->stripeWrapper->createRefund([
                'payment_intent' => $jobPayment->stripe_payment_intent_id,
                'reason' => 'requested_by_customer',
            ]);

            // Update job payment
            $jobPayment->update([
                'status' => 'refunded',
                'refunded_at' => now(),
                'refund_reason' => $reason,
            ]);

            // If payment was already released, deduct from creator balance
            if ('completed' === $jobPayment->status) {
                $this->deductFromCreatorBalance($contract->creator_id, $jobPayment->creator_amount);
            }

            Log::info('Contract payment refunded', [
                'contract_id' => $contract->id,
                'job_payment_id' => $jobPayment->id,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to refund contract payment', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create a Stripe PaymentIntent for a contract.
     *
     * This method centralizes PaymentIntent creation to reduce type complexity
     * in controllers and improve testability.
     *
     * @param Contract $contract        The contract to create a payment for
     * @param string   $customerId      The Stripe customer ID
     * @param string   $paymentMethodId The Stripe payment method ID
     * @param User     $user            The user (brand) making the payment
     */
    public function createPaymentIntent(
        Contract $contract,
        string $customerId,
        string $paymentMethodId,
        User $user
    ): PaymentIntent {
        Log::info('Creating Stripe PaymentIntent for contract', [
            'contract_id' => $contract->id,
            'customer_id' => $customerId,
            'payment_method_id' => $paymentMethodId,
            'amount_cents' => (int) round($contract->budget * 100),
            'amount_decimal' => $contract->budget,
            'currency' => 'brl',
        ]);

        $intent = $this->stripeWrapper->createPaymentIntent([
            'amount' => (int) round($contract->budget * 100),
            'currency' => 'brl',
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
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

        return $intent;
    }

    /**
     * Attach a payment method to a Stripe customer.
     *
     * @param User   $user            The user to attach the payment method to
     * @param string $customerId      The Stripe customer ID
     * @param string $paymentMethodId The Stripe payment method ID
     */
    public function attachPaymentMethodToCustomer(
        User $user,
        string $customerId,
        string $paymentMethodId
    ): void {
        Log::info('Attaching payment method to customer', [
            'customer_id' => $customerId,
            'payment_method_id' => $paymentMethodId,
        ]);

        $this->stripeWrapper->attachPaymentMethod($paymentMethodId, $customerId);

        if ($user->stripe_payment_method_id !== $paymentMethodId) {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['stripe_payment_method_id' => $paymentMethodId])
            ;

            $user->refresh();

            Log::info('Updated user stripe_payment_method_id after attaching payment method', [
                'user_id' => $user->id,
                'stripe_payment_method_id' => $paymentMethodId,
            ]);
        }
    }

    /**
     * Record the transaction for a contract payment.
     */
    public function recordContractTransaction(User $user, Contract $contract, PaymentIntent $intent): Transaction
    {
        Log::info('Recording Stripe PaymentIntent transaction', [
            'contract_id' => $contract->id,
            'payment_intent_id' => $intent->id,
            'status' => $intent->status,
        ]);

        return Transaction::create([
            'user_id' => $user->id,
            'stripe_payment_intent_id' => $intent->id,
            'stripe_charge_id' => $intent->latest_charge ?? null,
            'status' => 'succeeded' === $intent->status ? 'paid' : $intent->status,
            'amount' => $contract->budget,
            'payment_method' => 'stripe',
            'payment_data' => ['intent' => $intent->toArray()],
            'paid_at' => 'succeeded' === $intent->status ? now() : null,
            'contract_id' => $contract->id,
        ]);
    }

    /**
     * Record the job payment for a contract.
     */
    public function recordContractJobPayment(Contract $contract, Transaction $transaction): JobPayment
    {
        $platformFee = $contract->budget * self::PLATFORM_FEE_PERCENTAGE;
        $creatorAmount = $contract->budget * (1 - self::PLATFORM_FEE_PERCENTAGE);
        
        return JobPayment::create([
            'contract_id' => $contract->id,
            'brand_id' => $contract->brand_id,
            'creator_id' => $contract->creator_id,
            'total_amount' => $contract->budget,
            'platform_fee' => $platformFee,
            'creator_amount' => $creatorAmount,
            'payment_method' => 'stripe_escrow',
            'status' => 'pending',
            'transaction_id' => $transaction->id,
        ]);
    }

    /**
     * Update the creator's balance with the pending payment.
     */
    public function updateCreatorBalance(Contract $contract): void
    {
        $creatorAmount = $contract->budget * (1 - self::PLATFORM_FEE_PERCENTAGE);
        
        $balance = CreatorBalance::firstOrCreate(
            ['creator_id' => $contract->creator_id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_earned' => 0,
                'total_withdrawn' => 0,
            ]
        );
        $balance->increment('pending_balance', $creatorAmount);
    }

    /**
     * Activate the contract after successful payment.
     */
    public function activateContract(Contract $contract): void
    {
        $contract->update([
            'status' => 'active',
            'workflow_status' => 'active',
            'started_at' => now(),
        ]);

        ContractNotificationService::notifyCreatorOfContractStarted($contract);
        ContractNotificationService::notifyBrandOfContractStarted($contract);
    }

    /**
     * Handle contract funding success from frontend redirect (synchronous).
     */
    public function handleContractFundingSuccess(Contract $contract, Session $session): JobPayment
    {
        if ('paid' !== $session->payment_status) {
            throw new Exception('Payment not paid');
        }

        // Verify idempotency
        $paymentIntentId = $session->payment_intent instanceof PaymentIntent ? $session->payment_intent->id : $session->payment_intent;

        $existingTransaction = Transaction::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if ($existingTransaction) {
            // Return existing job payment if transaction exists
            $jobPayment = JobPayment::where('transaction_id', $existingTransaction->id)->first();
            if ($jobPayment) {
                return $jobPayment;
            }
        }

        return DB::transaction(function () use ($contract, $session, $paymentIntentId) {
            $amount = $session->amount_total / 100;

            // Create Transaction
            $transaction = Transaction::create([
                'user_id' => $contract->brand_id,
                'stripe_payment_intent_id' => $paymentIntentId,
                'status' => 'paid',
                'amount' => $amount,
                'payment_method' => 'stripe',
                'payment_data' => ['session_id' => $session->id, 'type' => 'contract_funding'],
                'paid_at' => now(),
                'contract_id' => $contract->id,
            ]);

            // Create/Update JobPayment
            $platformFee = $contract->budget * self::PLATFORM_FEE_PERCENTAGE;
            $creatorAmount = $contract->budget * (1 - self::PLATFORM_FEE_PERCENTAGE);

            return JobPayment::updateOrCreate(
                ['contract_id' => $contract->id],
                [
                    'brand_id' => $contract->brand_id,
                    'creator_id' => $contract->creator_id,
                    'total_amount' => $contract->budget,
                    'platform_fee' => $platformFee,
                    'creator_amount' => $creatorAmount,
                    'payment_method' => 'stripe_escrow',
                    'status' => 'held', // Money is held until contract completion
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'transaction_id' => $transaction->id,
                    'paid_at' => now(),
                ]
            );
        });
    }

    /**
     * Process a contract payment.
     */
    private function processContractPayment(
        Contract $contract,
        Session $session,
        ?string $userId
    ): void {
        $amount = (float) ($session->metadata->amount ?? $contract->budget);
        $platformFee = $this->calculatePlatformFee($amount);
        $creatorAmount = $amount - $platformFee;

        // Create job payment record
        $jobPayment = JobPayment::create([
            'contract_id' => $contract->id,
            'brand_id' => $contract->brand_id,
            'creator_id' => $contract->creator_id,
            'amount' => $amount,
            'platform_fee' => $platformFee,
            'creator_amount' => $creatorAmount,
            'status' => 'held', // Money is held until contract completion
            'stripe_payment_intent_id' => $session->payment_intent,
            'paid_at' => now(),
        ]);

        // Update contract status
        $contract->update([
            'payment_status' => 'funded',
            'funded_at' => now(),
        ]);

        Log::info('Contract payment processed', [
            'contract_id' => $contract->id,
            'job_payment_id' => $jobPayment->id,
            'amount' => $amount,
        ]);
    }

    /**
     * Add amount to creator's balance.
     */
    private function addToCreatorBalance(
        int $creatorId,
        float $amount,
        int $contractId,
        int $jobPaymentId
    ): CreatorBalance {
        $balance = CreatorBalance::firstOrCreate(
            ['creator_id' => $creatorId],
            ['available_balance' => 0, 'pending_balance' => 0, 'total_earned' => 0]
        );

        $balance->increment('available_balance', $amount);
        $balance->increment('total_earned', $amount);

        // Record the balance transaction
        $balance->transactions()->create([
            'type' => 'credit',
            'amount' => $amount,
            'description' => "Payment for contract #{$contractId}",
            'reference_type' => 'job_payment',
            'reference_id' => $jobPaymentId,
        ]);

        return $balance->fresh();
    }

    /**
     * Deduct amount from creator's balance.
     */
    private function deductFromCreatorBalance(int $creatorId, float $amount): void
    {
        $balance = CreatorBalance::where('creator_id', $creatorId)->first();

        if ($balance) {
            $balance->decrement('available_balance', min($amount, $balance->available_balance));
        }
    }
}

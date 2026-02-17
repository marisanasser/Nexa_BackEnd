<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Models\Payment\CreatorBalance;
use App\Models\Payment\JobPayment;
use App\Models\Payment\Withdrawal;
use App\Models\Payment\WithdrawalMethod;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Customer;

use function config;

/**
 * CreatorBalanceService handles creator balance and withdrawal operations.
 */
class CreatorBalanceService
{
    private const float PLATFORM_FEE_PERCENTAGE = 0.05; // 5%

    public function __construct(
        private readonly ?StripeWrapper $stripe = null
    ) {}

    /**
     * Get or create creator balance.
     */
    public function getOrCreateBalance(User $creator): CreatorBalance
    {
        return CreatorBalance::firstOrCreate(
            ['creator_id' => $creator->id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_earned' => 0,
                'total_withdrawn' => 0,
            ]
        );
    }

    /**
     * Add funds to creator's pending balance.
     */
    public function addPendingBalance(User $creator, float $amount, ?string $description = null): CreatorBalance
    {
        $balance = $this->getOrCreateBalance($creator);

        $balance->increment('pending_balance', $amount);

        Log::info('Added pending balance', [
            'creator_id' => $creator->id,
            'amount' => $amount,
            'new_pending' => $balance->fresh()->pending_balance,
        ]);

        return $balance->fresh();
    }

    /**
     * Release pending funds to available balance.
     */
    public function releasePendingToAvailable(User $creator, float $amount): CreatorBalance
    {
        $balance = $this->getOrCreateBalance($creator);

        if ($balance->pending_balance < $amount) {
            throw new Exception('Insufficient pending balance');
        }

        DB::transaction(function () use ($balance, $amount): void {
            $balance->decrement('pending_balance', $amount);
            $balance->increment('available_balance', $amount);
            $balance->increment('total_earned', $amount);
        });

        Log::info('Released pending to available', [
            'creator_id' => $creator->id,
            'amount' => $amount,
        ]);

        return $balance->fresh();
    }

    /**
     * Request a withdrawal.
     */
    public function requestWithdrawal(
        User $creator,
        float $amount,
        string $withdrawalMethod,
        array $withdrawalDetails
    ): Withdrawal {
        $balance = $this->getOrCreateBalance($creator);

        if ($balance->available_balance < $amount) {
            throw new Exception('Insufficient available balance');
        }

        // Validate minimum withdrawal
        $minWithdrawal = config('payment.min_withdrawal', 50);
        if ($amount < $minWithdrawal) {
            throw new Exception("Minimum withdrawal amount is {$minWithdrawal}");
        }

        return DB::transaction(function () use ($creator, $balance, $amount, $withdrawalMethod, $withdrawalDetails) {
            // Deduct from available balance
            $balance->decrement('available_balance', $amount);

            // Create withdrawal request
            $withdrawal = Withdrawal::create([
                'creator_id' => $creator->id,
                'amount' => $amount,
                'withdrawal_method' => $withdrawalMethod,
                'withdrawal_details' => $withdrawalDetails,
                'status' => 'pending',
            ]);

            Log::info('Withdrawal requested', [
                'withdrawal_id' => $withdrawal->id,
                'creator_id' => $creator->id,
                'amount' => $amount,
            ]);

            return $withdrawal;
        });
    }

    /**
     * Process a withdrawal (admin action).
     */
    public function processWithdrawal(Withdrawal $withdrawal, ?string $transactionId = null): Withdrawal
    {
        if ('pending' !== $withdrawal->status) {
            throw new Exception('Only pending withdrawals can be processed');
        }

        $withdrawal->update([
            'status' => 'completed',
            'processed_at' => now(),
            'transaction_id' => $transactionId,
        ]);

        // Update user's total withdrawn
        $balance = CreatorBalance::where('creator_id', $withdrawal->creator_id)->first();
        if ($balance) {
            $balance->increment('total_withdrawn', (float) $withdrawal->amount);
        }

        Log::info('Withdrawal processed', [
            'withdrawal_id' => $withdrawal->id,
            'transaction_id' => $transactionId,
        ]);

        return $withdrawal->fresh();
    }

    /**
     * Cancel a withdrawal.
     */
    public function cancelWithdrawal(Withdrawal $withdrawal, ?string $reason = null): Withdrawal
    {
        if ('pending' !== $withdrawal->status) {
            throw new Exception('Only pending withdrawals can be cancelled');
        }

        return DB::transaction(function () use ($withdrawal, $reason) {
            // Return funds to available balance
            $balance = CreatorBalance::where('creator_id', $withdrawal->creator_id)->first();
            if ($balance) {
                $balance->increment('available_balance', (float) $withdrawal->amount);
            }

            $withdrawal->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            Log::info('Withdrawal cancelled', [
                'withdrawal_id' => $withdrawal->id,
                'reason' => $reason,
            ]);

            return $withdrawal->fresh();
        });
    }

    /**
     * Get withdrawal history for a creator.
     */
    public function getWithdrawalHistory(User $creator, int $perPage = 15): LengthAwarePaginator
    {
        return Withdrawal::where('creator_id', $creator->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
        ;
    }

    /**
     * Get balance summary.
     */
    public function getBalanceSummary(User $creator): array
    {
        $balance = $this->getOrCreateBalance($creator);

        return [
            'available_balance' => (float) $balance->available_balance,
            'pending_balance' => (float) $balance->pending_balance,
            'total_earned' => (float) $balance->total_earned,
            'total_withdrawn' => (float) $balance->total_withdrawn,
            'pending_withdrawals' => Withdrawal::where('creator_id', $creator->id)
                ->where('status', 'pending')
                ->sum('amount'),
        ];
    }

    /**
     * Get work history (completed contracts).
     */
    public function getWorkHistory(User $creator, int $perPage = 10): LengthAwarePaginator
    {
        return $creator->creatorContracts()
            ->with(['brand:id,name,avatar_url', 'payment'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
        ;
    }

    /**
     * Get available withdrawal methods for user.
     */
    public function getWithdrawalMethods(User $user): array
    {
        return $user->getWithdrawalMethods()->values()->toArray();
    }

    /**
     * Create Stripe setup session for connecting a withdrawal method (card).
     */

    public function createStripeSetupSession(User $user): array
    {
        if (!$this->stripe) {
            throw new Exception('Stripe wrapper not configured');
        }

        $customerId = $this->ensureStripeCustomer($user);

        $frontendUrl = (string) config('app.frontend_url', 'http://localhost:5173');
        $successUrl = "$frontendUrl/dashboard/payment-methods?setup_success=true&session_id={CHECKOUT_SESSION_ID}";
        $cancelUrl = "$frontendUrl/dashboard/payment-methods?setup_canceled=true";

        $sessionParams = [
            'customer' => $customerId,
            'mode' => 'setup',
            'payment_method_types' => ['card'],
            'locale' => 'pt-BR',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => (string) $user->id,
                'type' => 'creator_payment_method_setup',
                'purpose' => 'withdrawal',
            ],
        ];

        $session = Session::create($sessionParams);

        return [
            'url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    /**
     * Save Stripe payment method from setup session.
     */
    public function saveStripePaymentMethod(User $user, string $sessionId): array
    {
        if (!$this->stripe) {
            throw new Exception('Stripe wrapper not configured');
        }

        $session = Session::retrieve($sessionId, [
            'expand' => ['setup_intent', 'setup_intent.payment_method'],
        ]);

        if ($session->customer !== $user->stripe_customer_id) {
            throw new Exception('Session does not belong to this user');
        }

        $setupIntent = $session->setup_intent;
        if ('succeeded' !== $setupIntent->status) {
            throw new Exception('Setup intent not completed');
        }

        $paymentMethod = $setupIntent->payment_method;
        $paymentMethodId = $paymentMethod->id;

        DB::transaction(function () use ($user, $paymentMethodId): void {
            $user->update(['stripe_payment_method_id' => $paymentMethodId]);

            // Activate Stripe Card withdrawal method if not active
            $method = WithdrawalMethod::where('code', 'stripe_card')->first();
            if ($method && !$method->is_active) {
                $method->update(['is_active' => true]);
            } elseif (!$method) {
                WithdrawalMethod::create([
                    'code' => 'stripe_card',
                    'name' => 'Cartão de Crédito/Débito (Stripe)',
                    'description' => 'Receba seus saques diretamente no seu cartão',
                    'min_amount' => 10.00,
                    'max_amount' => 10000.00,
                    'is_active' => true,
                    'sort_order' => 100,
                ]);
            }
        });

        return [
            'payment_method_id' => $paymentMethodId,
            'card_brand' => $paymentMethod->card->brand ?? null,
            'card_last4' => $paymentMethod->card->last4 ?? null,
        ];
    }

    /**
     * Ensure balance exists and perform simple recalculation check (lightweight).
     */
    public function ensureBalanceExists(User $creator): CreatorBalance
    {
        $balance = $this->getOrCreateBalance($creator);

        // Simple consistency check: if zero but payments exist, trigger full recalc logic (not implemented fully here but placeholder)
        if (0 == $balance->total_earned && JobPayment::where('creator_id', $creator->id)->exists()) {
            $balance->recalculateFromPayments();
            $balance->refresh();
        }

        return $balance;
    }

    /**
     * Calculate platform fee.
     */
    public function calculatePlatformFee(float $amount): float
    {
        return $amount * self::PLATFORM_FEE_PERCENTAGE;
    }

    /**
     * Calculate creator payout after fees.
     */
    public function calculateCreatorPayout(float $grossAmount): float
    {
        $fee = $this->calculatePlatformFee($grossAmount);

        return $grossAmount - $fee;
    }

    private function ensureStripeCustomer(User $user): string
    {
        $customerId = $user->stripe_customer_id;

        if (!$customerId) {
            $customer = Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => ['user_id' => $user->id, 'role' => $user->role],
            ]);
            $customerId = $customer->id;
            $user->update(['stripe_customer_id' => $customerId]);
        }

        return $customerId;
    }
}

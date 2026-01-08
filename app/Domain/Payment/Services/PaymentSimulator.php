<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Models\Payment\Subscription;
use App\Models\Payment\SubscriptionPlan;
use App\Models\Payment\Transaction;
use App\Models\User\User;
use Log;

class PaymentSimulator
{
    public static function isSimulationMode(): bool
    {
        return config('services.pagarme.simulation_mode', false);
    }

    public static function simulateSubscriptionPayment(array $requestData, User $user, SubscriptionPlan $subscriptionPlan): array
    {
        Log::info('SIMULATION: Processing subscription payment', [
            'user_id' => $user->id,
            'plan_id' => $subscriptionPlan->id,
            'amount' => $subscriptionPlan->price,
        ]);

        usleep(500000);

        $transactionId = 'SIM_' . time() . '_' . $user->id . '_' . random_int(1000, 9999);

        $expiresAt = now()->addMonths($subscriptionPlan->duration_months);

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'pagarme_transaction_id' => $transactionId,
            'status' => 'paid',
            'amount' => $subscriptionPlan->price,
            'payment_method' => 'credit_card',
            'card_brand' => self::getRandomCardBrand(),
            'card_last4' => substr($requestData['card_number'], -4),
            'card_holder_name' => $requestData['card_holder_name'],
            'payment_data' => [
                'simulation' => true,
                'original_request' => $requestData,
                'processed_at' => now()->toISOString(),
            ],
            'paid_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        $subscription = Subscription::updateOrCreate(
            ['user_id' => $user->id],
            [
                'subscription_plan_id' => $subscriptionPlan->id,
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => $expiresAt,
                'amount_paid' => $subscriptionPlan->price,
                'payment_method' => 'credit_card',
                'transaction_id' => $transaction->id,
                'auto_renew' => true,
            ]
        );

        $user->update([
            'has_premium' => true,
            'premium_expires_at' => $expiresAt,
        ]);

        Log::info('SIMULATION: Subscription payment completed', [
            'transaction_id' => $transactionId,
            'subscription_id' => $subscription->id,
            'expires_at' => $expiresAt,
        ]);

        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'status' => 'paid',
            'amount' => $subscriptionPlan->price,
            'expires_at' => $expiresAt->toISOString(),
            'simulation' => true,
        ];
    }

    public static function simulateAccountPayment(array $requestData, User $user): array
    {
        Log::info('SIMULATION: Processing account payment', [
            'user_id' => $user->id,
            'amount' => $requestData['amount'],
            'account_id' => $requestData['account_id'],
        ]);

        usleep(300000);

        $transactionId = 'SIM_ACC_' . time() . '_' . $user->id . '_' . random_int(1000, 9999);

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'pagarme_transaction_id' => $transactionId,
            'status' => 'paid',
            'amount' => $requestData['amount'],
            'payment_method' => 'account_balance',
            'payment_data' => [
                'simulation' => true,
                'account_id' => $requestData['account_id'],
                'description' => $requestData['description'],
                'processed_at' => now()->toISOString(),
            ],
            'paid_at' => now(),
        ]);

        Log::info('SIMULATION: Account payment completed', [
            'transaction_id' => $transactionId,
            'amount' => $requestData['amount'],
        ]);

        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'status' => 'paid',
            'amount' => $requestData['amount'],
            'simulation' => true,
        ];
    }

    public static function simulateContractPayment(array $requestData, User $user): array
    {
        Log::info('SIMULATION: Processing contract payment', [
            'user_id' => $user->id,
            'amount' => $requestData['amount'],
            'contract_id' => $requestData['contract_id'] ?? null,
        ]);

        usleep(400000);

        $transactionId = 'SIM_CONTRACT_' . time() . '_' . $user->id . '_' . random_int(1000, 9999);

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'pagarme_transaction_id' => $transactionId,
            'status' => 'paid',
            'amount' => $requestData['amount'],
            'payment_method' => 'credit_card',
            'card_brand' => self::getRandomCardBrand(),
            'card_last4' => '****',
            'card_holder_name' => $user->name,
            'payment_data' => [
                'simulation' => true,
                'contract_id' => $requestData['contract_id'] ?? null,
                'processed_at' => now()->toISOString(),
            ],
            'paid_at' => now(),
        ]);

        Log::info('SIMULATION: Contract payment completed', [
            'transaction_id' => $transactionId,
            'amount' => $requestData['amount'],
        ]);

        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'status' => 'paid',
            'amount' => $requestData['amount'],
            'simulation' => true,
        ];
    }

    public static function simulateWithdrawalProcessing(int $withdrawalId, string $method = 'bank_transfer'): array
    {
        Log::info('SIMULATION: Processing withdrawal', [
            'withdrawal_id' => $withdrawalId,
            'method' => $method,
        ]);

        $delay = match ($method) {
            'pix' => 200000,
            'bank_transfer' => 500000,
            'pagarme_bank_transfer' => 300000,
            default => 400000,
        };

        usleep($delay);

        $transactionId = 'SIM_WD_' . strtoupper($method) . '_' . time() . '_' . $withdrawalId;

        Log::info('SIMULATION: Withdrawal processing completed', [
            'withdrawal_id' => $withdrawalId,
            'transaction_id' => $transactionId,
            'method' => $method,
        ]);

        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'status' => 'completed',
            'method' => $method,
            'simulation' => true,
        ];
    }

    public static function simulatePaymentMethodCreation(array $requestData, User $user): array
    {
        Log::info('SIMULATION: Creating payment method', [
            'user_id' => $user->id,
            'card_last4' => substr($requestData['card_number'], -4),
        ]);

        usleep(200000);

        $cardId = 'SIM_CARD_' . time() . '_' . $user->id . '_' . random_int(1000, 9999);

        return [
            'success' => true,
            'card_id' => $cardId,
            'brand' => self::getRandomCardBrand(),
            'last4' => substr($requestData['card_number'], -4),
            'exp_month' => $requestData['exp_month'],
            'exp_year' => $requestData['exp_year'],
            'holder_name' => $requestData['holder_name'],
            'simulation' => true,
        ];
    }

    public static function simulateError(string $message = 'Simulated payment error'): array
    {
        Log::info('SIMULATION: Simulating payment error', [
            'message' => $message,
        ]);

        return [
            'success' => false,
            'message' => $message,
            'error_code' => 'SIMULATION_ERROR',
            'simulation' => true,
        ];
    }

    public static function shouldSimulateError(): bool
    {
        return random_int(1, 100) <= 5;
    }

    private static function getRandomCardBrand(): string
    {
        $brands = ['visa', 'mastercard', 'amex', 'elo', 'hipercard'];

        return $brands[array_rand($brands)];
    }
}

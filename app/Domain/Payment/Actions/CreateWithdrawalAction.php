<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Models\Payment\CreatorBalance;
use App\Models\Payment\Withdrawal;
use App\Models\Payment\WithdrawalMethod;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CreateWithdrawalAction handles the creation of a new withdrawal request.
 *
 * This action encapsulates all business logic for creating a withdrawal,
 * including fee calculation, detail enrichment, and balance deduction.
 */
class CreateWithdrawalAction
{
    public function __construct(
        private readonly StripeWrapper $stripe
    ) {
        $stripeSecret = config('services.stripe.secret');
        if ($stripeSecret) {
            $this->stripe->setApiKey($stripeSecret);
        }
    }

    /**
     * Execute the withdrawal creation.
     *
     * @return array{success: bool, withdrawal?: Withdrawal, message?: string, balance?: CreatorBalance}
     */
    public function execute(
        User $user,
        float $amount,
        string $withdrawalMethodCode,
        ?WithdrawalMethod $withdrawalMethod,
        ?array $dynamicMethod,
        array $withdrawalDetails = []
    ): array {
        try {
            return DB::transaction(function () use ($user, $amount, $withdrawalMethodCode, $withdrawalMethod, $dynamicMethod, $withdrawalDetails) {
                // Get or create balance
                $balance = $this->getOrCreateBalance($user);

                // Validate balance
                if (!$balance->canWithdraw($amount)) {
                    return [
                        'success' => false,
                        'message' => 'Saldo insuficiente para o saque. Saldo disponível: ' . $balance->formattedAvailableBalance(),
                    ];
                }

                // Validate amount limits
                $limitValidation = $this->validateAmountLimits($amount, $withdrawalMethod, $dynamicMethod, $withdrawalMethodCode);
                if (!$limitValidation['valid']) {
                    return [
                        'success' => false,
                        'message' => $limitValidation['message'],
                    ];
                }

                // Enrich withdrawal details
                $enrichedDetails = $this->enrichWithdrawalDetails(
                    $user,
                    $withdrawalMethodCode,
                    $withdrawalMethod,
                    $dynamicMethod,
                    $withdrawalDetails
                );

                // Create the withdrawal
                $withdrawal = Withdrawal::create([
                    'creator_id' => $user->id,
                    'amount' => $amount,
                    'withdrawal_method' => $withdrawalMethodCode,
                    'withdrawal_details' => $enrichedDetails,
                    'status' => 'pending',
                ]);

                if (!$withdrawal || !$withdrawal->id) {
                    throw new Exception('Failed to create withdrawal record in database');
                }

                // Deduct from balance
                $withdrawResult = $balance->withdraw($amount);
                if (!$withdrawResult) {
                    throw new Exception('Failed to deduct amount from available balance');
                }

                $balance->refresh();

                Log::info('Withdrawal created successfully', [
                    'withdrawal_id' => $withdrawal->id,
                    'creator_id' => $user->id,
                    'amount' => $amount,
                    'method' => $withdrawalMethodCode,
                    'net_amount' => $withdrawal->net_amount,
                ]);

                return [
                    'success' => true,
                    'withdrawal' => $withdrawal,
                    'balance' => $balance,
                ];
            });
        } catch (Exception $e) {
            Log::error('Withdrawal creation failed', [
                'user_id' => $user->id,
                'amount' => $amount,
                'method' => $withdrawalMethodCode,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao processar saque: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get or create the creator's balance.
     */
    private function getOrCreateBalance(User $user): CreatorBalance
    {
        $balance = CreatorBalance::where('creator_id', $user->id)->first();

        if (!$balance) {
            Log::info('Creating new balance for creator', ['creator_id' => $user->id]);

            $balance = CreatorBalance::create([
                'creator_id' => $user->id,
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_earned' => 0,
                'total_withdrawn' => 0,
            ]);
        }

        return $balance;
    }

    /**
     * Validate amount against method limits.
     *
     * @return array{valid: bool, message?: string}
     */
    private function validateAmountLimits(
        float $amount,
        ?WithdrawalMethod $withdrawalMethod,
        ?array $dynamicMethod,
        string $methodCode
    ): array {
        if ($withdrawalMethod) {
            if (!$withdrawalMethod->isAmountValid($amount)) {
                return [
                    'valid' => false,
                    'message' => "Valor deve estar entre {$withdrawalMethod->formattedMinAmount()} e {$withdrawalMethod->formattedMaxAmount()} para {$withdrawalMethod->name}",
                ];
            }
        } elseif ($dynamicMethod) {
            $minAmount = $dynamicMethod['min_amount'] ?? 0;
            $maxAmount = $dynamicMethod['max_amount'] ?? 1000000;

            if ($amount < $minAmount || $amount > $maxAmount) {
                $formattedMin = 'R$ ' . number_format($minAmount, 2, ',', '.');
                $formattedMax = 'R$ ' . number_format($maxAmount, 2, ',', '.');
                $methodName = $dynamicMethod['name'] ?? $methodCode;

                return [
                    'valid' => false,
                    'message' => "Valor deve estar entre {$formattedMin} e {$formattedMax} para {$methodName}",
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Enrich withdrawal details with method-specific information.
     */
    private function enrichWithdrawalDetails(
        User $user,
        string $withdrawalMethodCode,
        ?WithdrawalMethod $withdrawalMethod,
        ?array $dynamicMethod,
        array $withdrawalDetails
    ): array {
        // Add method info
        if ($withdrawalMethod) {
            // Fees are intentionally disabled for creator withdrawals.
            $withdrawalDetails['method_fee_percentage'] = 0;
            $withdrawalDetails['method_name'] = $withdrawalMethod->name;
            $withdrawalDetails['method_code'] = $withdrawalMethod->code;
        } elseif ($dynamicMethod) {
            $withdrawalDetails['method_fee_percentage'] = 0;
            $withdrawalDetails['method_name'] = $dynamicMethod['name'] ?? $withdrawalMethodCode;
            $withdrawalDetails['method_code'] = $withdrawalMethodCode;
        }

        // Add Stripe account ID for Stripe methods
        if (str_contains($withdrawalMethodCode, 'stripe') && $user->stripe_account_id) {
            $withdrawalDetails['stripe_account_id'] = $dynamicMethod['stripe_account_id'] ?? $user->stripe_account_id;

            // Add bank account details for Stripe Connect
            if ('stripe_connect_bank_account' === $withdrawalMethodCode) {
                $withdrawalDetails = $this->addStripeBankAccountDetails($user, $withdrawalDetails);
            }
        }

        return $withdrawalDetails;
    }

    /**
     * Add Stripe bank account details to withdrawal.
     */
    private function addStripeBankAccountDetails(User $user, array $details): array
    {
        try {
            $externalAccounts = $this->stripe->allExternalAccounts(
                $user->stripe_account_id,
                ['object' => 'bank_account', 'limit' => 1]
            );

            if (!empty($externalAccounts->data)) {
                $bankAccount = $externalAccounts->data[0];
                $details['bank_account_id'] = $bankAccount->id;
                $details['bank_name'] = $bankAccount->bank_name ?? null;
                $details['bank_last4'] = $bankAccount->last4 ?? null;
                $details['account_holder_name'] = $bankAccount->account_holder_name ?? null;
                $details['account_holder_type'] = $bankAccount->account_holder_type ?? null;
                $details['country'] = $bankAccount->country ?? 'BR';
                $details['currency'] = $bankAccount->currency ?? 'brl';
                $details['routing_number'] = $bankAccount->routing_number ?? null;
            }
        } catch (Exception $e) {
            Log::warning('Failed to retrieve Stripe bank account details', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $details;
    }

}

<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Services;

use App\Models\Campaign\Campaign;
use App\Models\Contract\Contract;
use App\Models\Payment\CreatorBalance;
use App\Models\Payment\JobPayment;
use App\Models\Payment\Transaction;
use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Refund;
use Stripe\Stripe;

/**
 * CampaignRefundService handles refund operations when archiving campaigns.
 *
 * Responsibilities:
 * - Processing contract refunds during campaign archival
 * - Handling Stripe refunds
 * - Managing creator balance adjustments
 */
class CampaignRefundService
{
    public function __construct()
    {
        $stripeSecret = config('services.stripe.secret');
        if ($stripeSecret) {
            Stripe::setApiKey($stripeSecret);
        }
    }

    /**
     * Process all refunds for a campaign being archived.
     *
     * @return array{refunded_amount: float, refunded_contracts: array}
     */
    public function processArchivalRefunds(Campaign $campaign): array
    {
        $refundedAmount = 0.0;
        $refundedContracts = [];

        // Get contracts that need refunding (funded but not completed)
        // Contracts are linked to campaigns through the Offer model
        $contracts = Contract::with('creator')
            ->whereHas('offer', function ($query) use ($campaign): void {
                $query->where('campaign_id', $campaign->id);
            })
            ->whereIn('status', ['approved', 'active', 'pending_delivery', 'in_revision'])
            ->get()
        ;

        foreach ($contracts as $contract) {
            $result = $this->processContractRefund($campaign, $contract);

            if ($result['success']) {
                $refundedAmount += $result['amount'];
                $refundedContracts[] = [
                    'contract_id' => $contract->id,
                    'amount' => $result['amount'],
                    'creator_name' => $contract->creator?->name,
                ];
            }
        }

        return [
            'refunded_amount' => $refundedAmount,
            'refunded_contracts' => $refundedContracts,
        ];
    }

    /**
     * Process refund for a single contract.
     *
     * @return array{success: bool, amount: float, error?: string}
     */
    public function processContractRefund(Campaign $campaign, Contract $contract): array
    {
        try {
            $refundedAmount = 0.0;

            // Find the transaction associated with this contract
            $transaction = Transaction::where('contract_id', $contract->id)
                ->where('type', 'contract_payment')
                ->where('status', 'completed')
                ->first()
            ;

            if ($transaction?->stripe_payment_intent_id) {
                $refundResult = $this->processStripeRefund($campaign, $contract, $transaction);
                if ($refundResult['success']) {
                    $refundedAmount = $refundResult['amount'];
                }
            }

            // Handle job payment and creator balance adjustments
            $this->handleJobPaymentAndBalanceRefund($contract);

            // Update contract status
            $contract->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'Campaign archived by brand',
            ]);

            Log::info('Contract refund processed', [
                'campaign_id' => $campaign->id,
                'contract_id' => $contract->id,
                'refunded_amount' => $refundedAmount,
            ]);

            return [
                'success' => true,
                'amount' => $refundedAmount,
            ];
        } catch (Exception $e) {
            Log::error('Failed to process contract refund', [
                'campaign_id' => $campaign->id,
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'amount' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process Stripe refund for a transaction.
     *
     * @return array{success: bool, amount: float}
     */
    private function processStripeRefund(
        Campaign $campaign,
        Contract $contract,
        Transaction $transaction
    ): array {
        try {
            $refund = Refund::create([
                'payment_intent' => $transaction->stripe_payment_intent_id,
                'reason' => 'requested_by_customer',
                'metadata' => [
                    'campaign_id' => $campaign->id,
                    'contract_id' => $contract->id,
                    'reason' => 'campaign_archived',
                ],
            ]);

            // Update transaction status
            $transaction->update([
                'status' => 'refunded',
                'refunded_at' => now(),
                'refund_id' => $refund->id,
            ]);

            // Update brand balance if applicable
            $this->adjustBrandBalance($campaign->user_id, (float) ($transaction->amount ?? 0));

            Log::info('Stripe refund processed', [
                'transaction_id' => $transaction->id,
                'refund_id' => $refund->id,
                'amount' => $transaction->amount,
            ]);

            return [
                'success' => true,
                'amount' => (float) $transaction->amount,
            ];
        } catch (Exception $e) {
            Log::error('Stripe refund failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'amount' => 0,
            ];
        }
    }

    /**
     * Adjust brand balance after refund.
     */
    private function adjustBrandBalance(int $brandId, float $amount): void
    {
        // Brand balance will be credited when Stripe processes the refund
        // This is mainly for internal tracking
        Log::info('Brand balance adjustment pending', [
            'brand_id' => $brandId,
            'amount' => $amount,
        ]);
    }

    /**
     * Handle job payment and creator balance adjustments.
     */
    private function handleJobPaymentAndBalanceRefund(Contract $contract): void
    {
        // Find and update job payment
        $jobPayment = JobPayment::where('contract_id', $contract->id)
            ->whereIn('status', ['held', 'pending'])
            ->first()
        ;

        if (!$jobPayment) {
            return;
        }

        // If payment was held (not yet released to creator)
        if ('held' === $jobPayment->status) {
            $jobPayment->update([
                'status' => 'refunded',
                'refunded_at' => now(),
            ]);

            Log::info('Job payment marked as refunded', [
                'job_payment_id' => $jobPayment->id,
                'contract_id' => $contract->id,
            ]);

            return;
        }

        // If payment was pending release
        if ('pending' === $jobPayment->status) {
            // Cancel the pending payment
            $jobPayment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            // Deduct from creator's pending balance if applicable
            $creatorBalance = CreatorBalance::where('user_id', $contract->creator_id)->first();
            if ($creatorBalance && $creatorBalance->pending_balance >= $jobPayment->creator_amount) {
                $creatorBalance->decrement('pending_balance', $jobPayment->creator_amount);

                Log::info('Creator pending balance adjusted', [
                    'creator_id' => $contract->creator_id,
                    'amount_deducted' => $jobPayment->creator_amount,
                ]);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Notification\Services\PaymentNotificationService;
use App\Domain\Payment\DTOs\WithdrawalProcessResult;
use App\Models\Payment\Transaction;
use App\Models\Payment\Withdrawal;
use Exception;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Log;
use function in_array;

/**
 * ProcessWithdrawalAction handles the complete withdrawal processing flow.
 *
 * This action encapsulates all business logic for processing a creator withdrawal,
 * including validation, balance updates, notification, and status management.
 *
 * Single Responsibility: Process a withdrawal request from start to finish.
 */
class ProcessWithdrawalAction
{
    /**
     * Execute the withdrawal processing.
     *
     * @param Withdrawal  $withdrawal    The withdrawal to process
     * @param null|string $transactionId Optional transaction ID from payment gateway
     *
     * @return WithdrawalProcessResult Result object containing success/failure info
     */
    public function execute(Withdrawal $withdrawal, ?string $transactionId = null): WithdrawalProcessResult
    {
        if (!$this->canProcess($withdrawal)) {
            return WithdrawalProcessResult::failure(
                "Withdrawal cannot be processed in current status: {$withdrawal->status}"
            );
        }

        try {
            $transaction = DB::transaction(function () use ($withdrawal, $transactionId): Transaction {
                if (!$withdrawal->process()) {
                    $withdrawal->refresh();
                    $message = is_string($withdrawal->failure_reason) && '' !== trim($withdrawal->failure_reason)
                        ? $withdrawal->failure_reason
                        : 'Failed to process withdrawal'
                    ;

                    throw new Exception($message);
                }

                if ($transactionId) {
                    $withdrawal->update(['transaction_id' => $transactionId]);
                }

                $withdrawal->refresh();

                $transaction = Transaction::query()
                    ->where('user_id', $withdrawal->creator_id)
                    ->where(function ($query) use ($withdrawal): void {
                        $query->whereJsonContains('payment_data->withdrawal_id', (string) $withdrawal->id)
                            ->orWhereJsonContains('payment_data->withdrawal_id', $withdrawal->id)
                            ->orWhereJsonContains('metadata->withdrawal_id', (string) $withdrawal->id)
                            ->orWhereJsonContains('metadata->withdrawal_id', $withdrawal->id)
                        ;
                    })
                    ->latest('id')
                    ->first()
                ;

                if ($transaction instanceof Transaction) {
                    return $transaction;
                }

                return Transaction::create([
                    'user_id' => $withdrawal->creator_id,
                    'status' => 'paid',
                    'amount' => $withdrawal->amount,
                    'payment_method' => 'withdrawal',
                    'payment_data' => [
                        'withdrawal_id' => $withdrawal->id,
                        'withdrawal_method' => $withdrawal->withdrawal_method,
                        'transaction_id' => $withdrawal->transaction_id,
                    ],
                    'metadata' => [
                        'withdrawal_id' => $withdrawal->id,
                        'source' => 'process_withdrawal_action_fallback',
                    ],
                    'paid_at' => $withdrawal->processed_at ?? now(),
                ]);
            });

            Log::info('Withdrawal processed successfully via action', [
                'withdrawal_id' => $withdrawal->id,
                'creator_id' => $withdrawal->creator_id,
                'amount' => $withdrawal->amount,
                'net_amount' => $withdrawal->net_amount,
                'method' => $withdrawal->withdrawal_method,
            ]);

            return WithdrawalProcessResult::success($withdrawal->fresh(), $transaction);
        } catch (Exception $e) {
            Log::error('Withdrawal processing failed', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark as failed
            $withdrawal->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            // Notify about failure
            try {
                PaymentNotificationService::notifyUserOfWithdrawalStatus(
                    $withdrawal,
                    'failed',
                    $e->getMessage()
                );
            } catch (Exception $notificationError) {
                Log::warning('Failed to send withdrawal failure notification', [
                    'withdrawal_id' => $withdrawal->id,
                ]);
            }

            return WithdrawalProcessResult::failure($e->getMessage());
        }
    }

    /**
     * Check if the withdrawal can be processed.
     */
    private function canProcess(Withdrawal $withdrawal): bool
    {
        return in_array($withdrawal->status, ['pending'], true);
    }
}

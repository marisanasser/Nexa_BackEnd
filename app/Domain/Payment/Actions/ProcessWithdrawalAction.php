<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Notification\Services\PaymentNotificationService;
use App\Domain\Payment\DTOs\WithdrawalProcessResult;
use App\Models\Payment\CreatorBalance;
use App\Models\Payment\Transaction;
use App\Models\Payment\Withdrawal;
use Exception;
use Illuminate\Support\Facades\DB;

use Log;
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
            return DB::transaction(function () use ($withdrawal, $transactionId) {
                // Mark as processing
                $withdrawal->update(['status' => 'processing']);

                // Deduct from creator balance
                $balance = CreatorBalance::where('user_id', $withdrawal->creator_id)->first();

                if (!$balance || $balance->available_balance < $withdrawal->amount) {
                    throw new Exception('Insufficient available balance for withdrawal');
                }

                $balance->update([
                    'available_balance' => $balance->available_balance - $withdrawal->amount,
                ]);

                // Create transaction record
                $transaction = Transaction::create([
                    'user_id' => $withdrawal->creator_id,
                    'type' => 'withdrawal',
                    'amount' => -$withdrawal->amount,
                    'status' => 'completed',
                    'description' => 'Saque processado via ' . $withdrawal->withdrawal_method_label,
                    'reference_type' => Withdrawal::class,
                    'reference_id' => $withdrawal->id,
                ]);

                // Mark withdrawal as completed
                $withdrawal->update([
                    'status' => 'completed',
                    'transaction_id' => $transactionId ?? $transaction->id,
                    'processed_at' => now(),
                ]);

                // Send notification
                try {
                    PaymentNotificationService::notifyUserOfWithdrawalStatus(
                        $withdrawal,
                        'completed'
                    );
                } catch (Exception $notificationError) {
                    Log::warning('Failed to send withdrawal completion notification', [
                        'withdrawal_id' => $withdrawal->id,
                        'error' => $notificationError->getMessage(),
                    ]);
                }

                Log::info('Withdrawal processed successfully', [
                    'withdrawal_id' => $withdrawal->id,
                    'creator_id' => $withdrawal->creator_id,
                    'amount' => $withdrawal->amount,
                    'net_amount' => $withdrawal->net_amount,
                    'method' => $withdrawal->withdrawal_method,
                ]);

                return WithdrawalProcessResult::success($withdrawal, $transaction);
            });
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
        return in_array($withdrawal->status, ['pending', 'approved']);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Admin\Services;

use App\Models\Contract\Contract;
use App\Models\Payment\BankAccount;
use App\Models\Payment\JobPayment;
use App\Models\Payment\Withdrawal;
use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminPayoutService
{
    /**
     * Get payout metrics for admin dashboard.
     */
    public function getMetrics(): array
    {
        try {
            return [
                'total_pending_withdrawals' => Withdrawal::where('status', 'pending')->count(),
                'total_processing_withdrawals' => Withdrawal::where('status', 'processing')->count(),
                'total_completed_withdrawals' => Withdrawal::where('status', 'completed')->count(),
                'total_failed_withdrawals' => Withdrawal::where('status', 'failed')->count(),
                'total_pending_amount' => Withdrawal::where('status', 'pending')->sum('amount'),
                'total_processing_amount' => Withdrawal::where('status', 'processing')->sum('amount'),
                'contracts_waiting_review' => Contract::where('status', 'completed')
                    ->where('workflow_status', 'waiting_review')->count(),
                'contracts_payment_available' => Contract::where('status', 'completed')
                    ->where('workflow_status', 'payment_available')->count(),
                'contracts_payment_withdrawn' => Contract::where('status', 'completed')
                    ->where('workflow_status', 'payment_withdrawn')->count(),
                'total_platform_fees' => JobPayment::where('status', 'completed')->sum('platform_fee'),
                'total_creator_payments' => JobPayment::where('status', 'completed')->sum('creator_amount'),
            ];
        } catch (Exception $e) {
            Log::error('Error fetching payout metrics', ['error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Get pending withdrawals with pagination.
     */
    public function getPendingWithdrawals(int $perPage = 20): LengthAwarePaginator
    {
        $withdrawals = Withdrawal::with(['creator:id,name,email,avatar_url'])
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
        ;

        // Transform items in place or return paginator and let controller transform?
        // Service should return domain objects or standard DTOs.
        // For simple admin views, returning the Paginator with transformed array is acceptable via through()
        if ($withdrawals instanceof LengthAwarePaginator) {
            $withdrawals->through($this->transformWithdrawalData(...));
        }

        return $withdrawals;
    }

    /**
     * Approve a withdrawal.
     */
    public function approveWithdrawal(int $id): array
    {
        $withdrawal = Withdrawal::with('creator')->find($id);

        if (!$withdrawal instanceof Withdrawal) {
            throw new Exception('Withdrawal not found');
        }

        if ('completed' === $withdrawal->status) {
            return [
                'withdrawal_id' => $withdrawal->id,
                'status' => $withdrawal->status,
                'transaction_id' => $withdrawal->transaction_id,
            ];
        }

        if ('pending' !== $withdrawal->status) {
            throw new Exception("Withdrawal cannot be processed in status: {$withdrawal->status}");
        }

        if ($withdrawal->process()) {
            $withdrawal->refresh();

            Log::info('Admin processed withdrawal', [
                'withdrawal_id' => $withdrawal->id,
                'admin_id' => Auth::id(),
            ]);

            return [
                'withdrawal_id' => $withdrawal->id,
                'status' => $withdrawal->status,
                'transaction_id' => $withdrawal->transaction_id,
            ];
        }

        $withdrawal->refresh();
        $failureReason = $withdrawal->failure_reason;

        if (is_string($failureReason) && '' !== trim($failureReason)) {
            throw new Exception($failureReason);
        }

        throw new Exception('Failed to process withdrawal');
    }

    /**
     * Reject a withdrawal.
     */
    public function rejectWithdrawal(int $id, ?string $reason): array
    {
        $withdrawal = Withdrawal::with('creator')->find($id);

        if (!$withdrawal instanceof Withdrawal) {
            throw new Exception('Withdrawal not found');
        }

        if ($withdrawal->cancel($reason)) {
            Log::info('Admin rejected withdrawal', [
                'withdrawal_id' => $withdrawal->id,
                'admin_id' => Auth::id(),
                'reason' => $reason,
            ]);

            return [
                'withdrawal_id' => $withdrawal->id,
                'status' => $withdrawal->status,
            ];
        }

        throw new Exception('Failed to reject withdrawal');
    }

    /**
     * Get payout history.
     */
    public function getPayoutHistory(int $perPage = 50): LengthAwarePaginator
    {
        $withdrawals = Withdrawal::with(['creator:id,name,email,avatar_url'])
            ->where('status', 'completed')
            ->orderBy('processed_at', 'desc')
            ->paginate($perPage)
        ;

        if ($withdrawals instanceof LengthAwarePaginator) {
            $withdrawals->through(fn ($withdrawal) => [
                'id' => $withdrawal->id,
                'amount' => $withdrawal->formatted_amount,
                'withdrawal_method' => $withdrawal->withdrawal_method_label,
                'transaction_id' => $withdrawal->transaction_id,
                'processed_at' => $withdrawal->processed_at?->format('Y-m-d H:i:s'),
                'creator' => [
                    'id' => $withdrawal->creator->id,
                    'name' => $withdrawal->creator->name,
                    'email' => $withdrawal->creator->email,
                ],
            ]);
        }

        return $withdrawals;
    }

    /**
     * Verify withdrawal logic.
     */
    public function verifyWithdrawal(int $id): array
    {
        $withdrawal = Withdrawal::with(['creator.bankAccount'])->find($id);

        if (!$withdrawal instanceof Withdrawal) {
            throw new Exception('Withdrawal not found');
        }

        $currentBankAccount = BankAccount::where('user_id', $withdrawal->creator_id)->first();

        return $this->buildVerificationData($withdrawal, $currentBankAccount);
    }

    public function generateVerificationReport(array $filters): array
    {
        $query = Withdrawal::with(['creator.bankAccount']);

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date'].' 23:59:59');
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['withdrawal_method'])) {
            $query->where('withdrawal_method', $filters['withdrawal_method']);
        }

        $withdrawals = $query->orderBy('created_at', 'desc')->paginate(50); // Using paginator for report chunks

        return $this->buildVerificationReport($withdrawals);
    }

    // Private helpers

    private function transformWithdrawalData(Withdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->id,
            'amount' => $withdrawal->formatted_amount,
            'withdrawal_method' => $withdrawal->withdrawal_method_label,
            'status' => $withdrawal->status,
            'created_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
            'days_since_created' => $withdrawal->getDaysSinceCreatedAttribute(),
            'creator' => [
                'id' => $withdrawal->creator->id,
                'name' => $withdrawal->creator->name,
                'email' => $withdrawal->creator->email,
                'avatar_url' => $withdrawal->creator->getAvatarAttribute(),
            ],
            'withdrawal_details' => $withdrawal->withdrawal_details,
        ];
    }

    private function buildVerificationData(Withdrawal $withdrawal, ?BankAccount $currentBankAccount): array
    {
        return [
            'withdrawal' => [
                'id' => $withdrawal->id,
                'amount' => $withdrawal->formatted_amount,
                'withdrawal_method' => $withdrawal->withdrawal_method_label,
                'status' => $withdrawal->status,
                'transaction_id' => $withdrawal->transaction_id,
                'processed_at' => $withdrawal->processed_at?->format('Y-m-d H:i:s'),
                'created_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                'withdrawal_details' => $withdrawal->withdrawal_details,
            ],
            'creator' => [
                'id' => $withdrawal->creator->id,
                'name' => $withdrawal->creator->name,
                'email' => $withdrawal->creator->email,
            ],
            'bank_account_verification' => [
                'withdrawal_bank_details' => $this->extractBankDetailsFromWithdrawal($withdrawal),
                'current_bank_account' => $currentBankAccount ? [
                    'bank_code' => $currentBankAccount->bank_code,
                    'agencia' => $currentBankAccount->agencia,
                    'agencia_dv' => $currentBankAccount->agencia_dv,
                    'conta' => $currentBankAccount->conta,
                    'conta_dv' => $currentBankAccount->conta_dv,
                    'cpf' => $currentBankAccount->cpf,
                    'name' => $currentBankAccount->name,
                ] : null,
                'details_match' => $this->compareBankDetails($withdrawal, $currentBankAccount),
            ],
            'verification_summary' => [
                'withdrawal_amount_correct' => $this->verifyWithdrawalAmount($withdrawal),
                'bank_details_consistent' => $this->compareBankDetails($withdrawal, $currentBankAccount),
                'transaction_id_valid' => !empty($withdrawal->transaction_id),
                'processing_time_reasonable' => $this->verifyProcessingTime($withdrawal),
                'overall_verification_status' => $this->getOverallVerificationStatus($withdrawal, $currentBankAccount),
            ],
        ];
    }

    private function buildVerificationReport($withdrawals): array
    {
        $report = [
            'summary' => [
                'total_withdrawals' => $withdrawals->total(),
                'total_amount' => $withdrawals->sum('amount'),
                'verification_passed' => 0,
                'verification_failed' => 0,
                'pending_verification' => 0,
            ],
            'withdrawals' => [],
        ];

        foreach ($withdrawals->items() as $withdrawal) {
            $currentBankAccount = BankAccount::where('user_id', $withdrawal->creator_id)->first();
            $verificationStatus = $this->getOverallVerificationStatus($withdrawal, $currentBankAccount);

            $report['withdrawals'][] = [
                'id' => $withdrawal->id,
                'amount' => $withdrawal->formatted_amount,
                'withdrawal_method' => $withdrawal->withdrawal_method_label,
                'status' => $withdrawal->status,
                'transaction_id' => $withdrawal->transaction_id,
                'processed_at' => $withdrawal->processed_at?->format('Y-m-d H:i:s'),
                'creator' => [
                    'id' => $withdrawal->creator->id,
                    'name' => $withdrawal->creator->name,
                    'email' => $withdrawal->creator->email,
                ],
                'verification_status' => $verificationStatus,
                'bank_details_match' => $this->compareBankDetails($withdrawal, $currentBankAccount),
                'amount_verification' => $this->verifyWithdrawalAmount($withdrawal),
            ];

            match ($verificationStatus) {
                'passed' => $report['summary']['verification_passed']++,
                'failed' => $report['summary']['verification_failed']++,
                'pending' => $report['summary']['pending_verification']++,
                default => null,
            };
        }

        $report['pagination'] = [
            'current_page' => $withdrawals->currentPage(),
            'last_page' => $withdrawals->lastPage(),
            'per_page' => $withdrawals->perPage(),
            'total' => $withdrawals->total(),
        ];

        return $report;
    }

    private function extractBankDetailsFromWithdrawal(Withdrawal $withdrawal): ?array
    {
        if (!$withdrawal->withdrawal_details) {
            return null;
        }

        return [
            'bank_code' => $withdrawal->withdrawal_details['bank_code'] ?? null,
            'agencia' => $withdrawal->withdrawal_details['agencia'] ?? null,
            'agencia_dv' => $withdrawal->withdrawal_details['agencia_dv'] ?? null,
            'conta' => $withdrawal->withdrawal_details['conta'] ?? null,
            'conta_dv' => $withdrawal->withdrawal_details['conta_dv'] ?? null,
            'cpf' => $withdrawal->withdrawal_details['cpf'] ?? null,
            'name' => $withdrawal->withdrawal_details['name'] ?? null,
        ];
    }

    private function compareBankDetails(Withdrawal $withdrawal, ?BankAccount $currentBankAccount): bool
    {
        if (!$currentBankAccount || !$withdrawal->withdrawal_details) {
            return false;
        }

        $withdrawalDetails = $this->extractBankDetailsFromWithdrawal($withdrawal);

        if (!$withdrawalDetails) {
            return false;
        }

        $fieldsToCompare = ['bank_code', 'agencia', 'agencia_dv', 'conta', 'conta_dv', 'cpf'];

        foreach ($fieldsToCompare as $field) {
            if (($withdrawalDetails[$field] ?? '') !== ($currentBankAccount->{$field} ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function verifyWithdrawalAmount(Withdrawal $withdrawal): bool
    {
        if ($withdrawal->amount <= 0) {
            return false;
        }

        if ($withdrawal->amount > 1000000) {
            return false;
        }

        return true;
    }

    private function verifyProcessingTime(Withdrawal $withdrawal): bool
    {
        if (!$withdrawal->processed_at) {
            return true;
        }

        $processingTime = $withdrawal->created_at->diffInHours($withdrawal->processed_at);

        return $processingTime <= 72;
    }

    private function getOverallVerificationStatus(Withdrawal $withdrawal, ?BankAccount $currentBankAccount): string
    {
        if ('pending' === $withdrawal->status || 'processing' === $withdrawal->status) {
            return 'pending';
        }

        if ('failed' === $withdrawal->status || 'cancelled' === $withdrawal->status) {
            return 'failed';
        }

        $amountCorrect = $this->verifyWithdrawalAmount($withdrawal);
        $bankDetailsMatch = $this->compareBankDetails($withdrawal, $currentBankAccount);
        $transactionIdValid = !empty($withdrawal->transaction_id);
        $processingTimeReasonable = $this->verifyProcessingTime($withdrawal);

        if ($amountCorrect && $bankDetailsMatch && $transactionIdValid && $processingTimeReasonable) {
            return 'passed';
        }

        return 'failed';
    }
}

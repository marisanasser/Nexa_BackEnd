<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Withdrawal;
use App\Models\JobPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;

class AdminPayoutController extends Controller
{
    
    public function getPayoutMetrics(): JsonResponse
    {
        try {
            $metrics = [
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

            return response()->json([
                'success' => true,
                'data' => $metrics,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching payout metrics', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payout metrics',
            ], 500);
        }
    }

    
    public function getPendingWithdrawals(Request $request): JsonResponse
    {
        try {
            $withdrawals = Withdrawal::with(['creator:id,name,email,avatar_url'])
                ->whereIn('status', ['pending', 'processing'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            $withdrawals->getCollection()->transform(function ($withdrawal) {
                return [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->formatted_amount,
                    'withdrawal_method' => $withdrawal->withdrawal_method_label,
                    'status' => $withdrawal->status,
                    'created_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                    'days_since_created' => $withdrawal->days_since_created,
                    'creator' => [
                        'id' => $withdrawal->creator->id,
                        'name' => $withdrawal->creator->name,
                        'email' => $withdrawal->creator->email,
                        'avatar_url' => $withdrawal->creator->avatar_url,
                    ],
                    'withdrawal_details' => $withdrawal->withdrawal_details,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $withdrawals,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching pending withdrawals', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending withdrawals',
            ], 500);
        }
    }

    
    public function processWithdrawal(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $withdrawal = Withdrawal::with('creator')->find($id);

            if (!$withdrawal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal not found',
                ], 404);
            }

            if ($request->action === 'approve') {
                if ($withdrawal->process()) {
                    Log::info('Admin processed withdrawal', [
                        'withdrawal_id' => $withdrawal->id,
                        'admin_id' => Auth::id(),
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Withdrawal processed successfully',
                        'data' => [
                            'withdrawal_id' => $withdrawal->id,
                            'status' => $withdrawal->status,
                        ],
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to process withdrawal',
                    ], 500);
                }
            } else {
                
                if ($withdrawal->cancel($request->reason)) {
                    Log::info('Admin rejected withdrawal', [
                        'withdrawal_id' => $withdrawal->id,
                        'admin_id' => Auth::id(),
                        'reason' => $request->reason,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Withdrawal rejected successfully',
                        'data' => [
                            'withdrawal_id' => $withdrawal->id,
                            'status' => $withdrawal->status,
                        ],
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to reject withdrawal',
                    ], 500);
                }
            }

        } catch (\Exception $e) {
            Log::error('Error processing withdrawal', [
                'withdrawal_id' => $id,
                'admin_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process withdrawal',
            ], 500);
        }
    }

    
    public function getDisputedContracts(): JsonResponse
    {
        try {
            $contracts = Contract::with(['brand:id,name,email,avatar_url', 'creator:id,name,email,avatar_url'])
                ->where('status', 'disputed')
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            $contracts->getCollection()->transform(function ($contract) {
                return [
                    'id' => $contract->id,
                    'title' => $contract->title,
                    'description' => $contract->description,
                    'budget' => $contract->formatted_budget,
                    'status' => $contract->status,
                    'workflow_status' => $contract->workflow_status,
                    'created_at' => $contract->created_at->format('Y-m-d H:i:s'),
                    'brand' => [
                        'id' => $contract->brand->id,
                        'name' => $contract->brand->name,
                        'email' => $contract->brand->email,
                        'avatar_url' => $contract->brand->avatar_url,
                    ],
                    'creator' => [
                        'id' => $contract->creator->id,
                        'name' => $contract->creator->name,
                        'email' => $contract->creator->email,
                        'avatar_url' => $contract->creator->avatar_url,
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $contracts,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching disputed contracts', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch disputed contracts',
            ], 500);
        }
    }

    
    public function resolveDispute(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'resolution' => 'required|in:complete,cancel,refund',
            'reason' => 'required|string|max:1000',
            'winner' => 'required|in:brand,creator,platform',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $contract = Contract::with(['brand', 'creator'])->find($id);

            if (!$contract || $contract->status !== 'disputed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Disputed contract not found',
                ], 404);
            }

            $resolution = $request->resolution;
            $reason = $request->reason;
            $winner = $request->winner;

            switch ($resolution) {
                case 'complete':
                    
                    $contract->update([
                        'status' => 'completed',
                        'workflow_status' => 'waiting_review',
                    ]);
                    break;

                case 'cancel':
                    
                    $contract->update([
                        'status' => 'cancelled',
                        'cancellation_reason' => $reason,
                    ]);
                    break;

                case 'refund':
                    
                    if ($winner === 'creator') {
                        
                        $contract->update([
                            'status' => 'cancelled',
                            'cancellation_reason' => $reason,
                        ]);
                    } elseif ($winner === 'brand') {
                        
                        $contract->update([
                            'status' => 'completed',
                            'workflow_status' => 'waiting_review',
                        ]);
                    }
                    break;
            }

            Log::info('Admin resolved contract dispute', [
                'contract_id' => $contract->id,
                'admin_id' => Auth::id(),
                'resolution' => $resolution,
                'winner' => $winner,
                'reason' => $reason,
            ]);

            
            NotificationService::notifyUsersOfDisputeResolution($contract, $resolution, $winner, $reason);

            return response()->json([
                'success' => true,
                'message' => 'Dispute resolved successfully',
                'data' => [
                    'contract_id' => $contract->id,
                    'resolution' => $resolution,
                    'winner' => $winner,
                    'new_status' => $contract->status,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error resolving dispute', [
                'contract_id' => $id,
                'admin_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve dispute',
            ], 500);
        }
    }

    
    public function getPayoutHistory(Request $request): JsonResponse
    {
        try {
            $withdrawals = Withdrawal::with(['creator:id,name,email,avatar_url'])
                ->where('status', 'completed')
                ->orderBy('processed_at', 'desc')
                ->paginate(50);

            $withdrawals->getCollection()->transform(function ($withdrawal) {
                return [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->formatted_amount,
                    'withdrawal_method' => $withdrawal->withdrawal_method_label,
                    'transaction_id' => $withdrawal->transaction_id,
                    'processed_at' => $withdrawal->processed_at->format('Y-m-d H:i:s'),
                    'creator' => [
                        'id' => $withdrawal->creator->id,
                        'name' => $withdrawal->creator->name,
                        'email' => $withdrawal->creator->email,
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $withdrawals,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching payout history', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payout history',
            ], 500);
        }
    }

    
    public function verifyWithdrawal(Request $request, int $id): JsonResponse
    {
        try {
            $withdrawal = Withdrawal::with(['creator.bankAccount'])
                ->find($id);

            if (!$withdrawal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal not found',
                ], 404);
            }

            
            $currentBankAccount = \App\Models\BankAccount::where('user_id', $withdrawal->creator_id)->first();
            
            
            $verificationData = [
                'withdrawal' => [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->formatted_amount,
                    'withdrawal_method' => $withdrawal->withdrawal_method_label,
                    'status' => $withdrawal->status,
                    'transaction_id' => $withdrawal->transaction_id,
                    'processed_at' => $withdrawal->processed_at ? $withdrawal->processed_at->format('Y-m-d H:i:s') : null,
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

            return response()->json([
                'success' => true,
                'data' => $verificationData,
            ]);

        } catch (\Exception $e) {
            Log::error('Error verifying withdrawal', [
                'withdrawal_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify withdrawal',
            ], 500);
        }
    }

    
    public function getWithdrawalVerificationReport(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'status' => 'nullable|in:pending,processing,completed,failed,cancelled',
                'withdrawal_method' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $query = Withdrawal::with(['creator.bankAccount']);

            
            if ($request->start_date) {
                $query->where('created_at', '>=', $request->start_date);
            }
            if ($request->end_date) {
                $query->where('created_at', '<=', $request->end_date . ' 23:59:59');
            }
            if ($request->status) {
                $query->where('status', $request->status);
            }
            if ($request->withdrawal_method) {
                $query->where('withdrawal_method', $request->withdrawal_method);
            }

            $withdrawals = $query->orderBy('created_at', 'desc')->paginate(50);

            $verificationReport = [
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
                $currentBankAccount = \App\Models\BankAccount::where('user_id', $withdrawal->creator_id)->first();
                $verificationStatus = $this->getOverallVerificationStatus($withdrawal, $currentBankAccount);
                
                $verificationReport['withdrawals'][] = [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->formatted_amount,
                    'withdrawal_method' => $withdrawal->withdrawal_method_label,
                    'status' => $withdrawal->status,
                    'transaction_id' => $withdrawal->transaction_id,
                    'processed_at' => $withdrawal->processed_at ? $withdrawal->processed_at->format('Y-m-d H:i:s') : null,
                    'creator' => [
                        'id' => $withdrawal->creator->id,
                        'name' => $withdrawal->creator->name,
                        'email' => $withdrawal->creator->email,
                    ],
                    'verification_status' => $verificationStatus,
                    'bank_details_match' => $this->compareBankDetails($withdrawal, $currentBankAccount),
                    'amount_verification' => $this->verifyWithdrawalAmount($withdrawal),
                ];

                
                switch ($verificationStatus) {
                    case 'passed':
                        $verificationReport['summary']['verification_passed']++;
                        break;
                    case 'failed':
                        $verificationReport['summary']['verification_failed']++;
                        break;
                    case 'pending':
                        $verificationReport['summary']['pending_verification']++;
                        break;
                }
            }

            $verificationReport['pagination'] = [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
            ];

            return response()->json([
                'success' => true,
                'data' => $verificationReport,
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating withdrawal verification report', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate verification report',
            ], 500);
        }
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

    
    private function compareBankDetails(Withdrawal $withdrawal, $currentBankAccount): bool
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
            if (($withdrawalDetails[$field] ?? '') !== ($currentBankAccount->$field ?? '')) {
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

    
    private function getOverallVerificationStatus(Withdrawal $withdrawal, $currentBankAccount): string
    {
        if ($withdrawal->status === 'pending' || $withdrawal->status === 'processing') {
            return 'pending';
        }

        if ($withdrawal->status === 'failed' || $withdrawal->status === 'cancelled') {
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

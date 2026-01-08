<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Domain\Admin\Services\AdminDisputeService;
use App\Domain\Admin\Services\AdminPayoutService;
use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AdminPayoutController handles admin payout and withdrawal operations.
 *
 * Migrated to Admin namespace for consistent organization.
 */
class AdminPayoutController extends Controller
{
    use HasAuthenticatedUser;

    protected AdminPayoutService $payoutService;
    protected AdminDisputeService $disputeService;

    public function __construct(
        AdminPayoutService $payoutService,
        AdminDisputeService $disputeService
    ) {
        $this->payoutService = $payoutService;
        $this->disputeService = $disputeService;
    }

    /**
     * Get payout metrics for admin dashboard.
     */
    public function getMetrics(): JsonResponse
    {
        try {
            $metrics = $this->payoutService->getMetrics();

            return response()->json([
                'success' => true,
                'data' => $metrics,
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching payout metrics', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payout metrics',
            ], 500);
        }
    }

    public function getPendingWithdrawals(Request $request): JsonResponse
    {
        try {
            $withdrawals = $this->payoutService->getPendingWithdrawals(20);

            return response()->json([
                'success' => true,
                'data' => $withdrawals->items(), // items are already transformed in service via through()
                'pagination' => [
                    'current_page' => $withdrawals->currentPage(),
                    'last_page' => $withdrawals->lastPage(),
                    'per_page' => $withdrawals->perPage(),
                    'total' => $withdrawals->total(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching pending withdrawals', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending withdrawals',
            ], 500);
        }
    }

    /**
     * Process a withdrawal (approve/reject).
     */
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
            if ('approve' === $request->action) {
                $result = $this->payoutService->approveWithdrawal($id);
                $message = 'Withdrawal processed successfully';
            } else {
                $result = $this->payoutService->rejectWithdrawal($id, $request->reason);
                $message = 'Withdrawal rejected successfully';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('Error processing withdrawal', [
                'withdrawal_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $status = 'Withdrawal not found' === $e->getMessage() ? 404 : 500;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    public function getDisputedContracts(): JsonResponse
    {
        try {
            $contracts = $this->disputeService->getDisputedContracts(20);

            return response()->json([
                'success' => true,
                'data' => $contracts->items(),
                'pagination' => [
                    'current_page' => $contracts->currentPage(),
                    'last_page' => $contracts->lastPage(),
                    'per_page' => $contracts->perPage(),
                    'total' => $contracts->total(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching disputed contracts', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch disputed contracts',
            ], 500);
        }
    }

    /**
     * Resolve a contract dispute.
     */
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
            $result = $this->disputeService->resolveDispute(
                $id,
                $request->resolution,
                $request->reason,
                $request->winner
            );

            return response()->json([
                'success' => true,
                'message' => 'Dispute resolved successfully',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('Error resolving dispute', [
                'contract_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $status = 'Disputed contract not found' === $e->getMessage() ? 404 : 500;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * Get payout history.
     */
    public function getPayoutHistory(Request $request): JsonResponse
    {
        try {
            $withdrawals = $this->payoutService->getPayoutHistory(50);

            return response()->json([
                'success' => true,
                'data' => $withdrawals->items(),
                'pagination' => [
                    'current_page' => $withdrawals->currentPage(),
                    'last_page' => $withdrawals->lastPage(),
                    'per_page' => $withdrawals->perPage(),
                    'total' => $withdrawals->total(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching payout history', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payout history',
            ], 500);
        }
    }

    /**
     * Verify a withdrawal for audit purposes.
     */
    public function verifyWithdrawal(Request $request, int $id): JsonResponse
    {
        try {
            $verificationData = $this->payoutService->verifyWithdrawal($id);

            return response()->json([
                'success' => true,
                'data' => $verificationData,
            ]);
        } catch (Exception $e) {
            Log::error('Error verifying withdrawal', [
                'withdrawal_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $status = 'Withdrawal not found' === $e->getMessage() ? 404 : 500;

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify withdrawal',
            ], $status);
        }
    }

    /**
     * Get withdrawal verification report.
     */
    public function getVerificationReport(Request $request): JsonResponse
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

            $report = $this->payoutService->generateVerificationReport($request->all());

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Exception $e) {
            Log::error('Error generating withdrawal verification report', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate verification report',
            ], 500);
        }
    }
}

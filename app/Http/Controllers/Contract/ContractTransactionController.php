<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contract;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Domain\Payment\Repositories\TransactionRepository;
use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Http\Resources\Payment\TransactionCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractTransactionController extends Controller
{
    use HasAuthenticatedUser;

    public function __construct(
        private readonly TransactionRepository $transactionRepository
    ) {}

    public function getUserHistory(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            $perPage = $request->integer('per_page', 10);
            $perPage = min(max($perPage, 1), 100);
            $page = $request->integer('page', 1);

            $transactions = $this->transactionRepository->getUserHistory($user, $perPage, $page);

            return (new TransactionCollection($transactions))->response();
        } catch (Exception $e) {
            Log::error('Error fetching transaction history', [
                'user_id' => auth()->id(), // Fallback if user retrieval fails earlier
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transaction history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getBrandHistory(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This endpoint is only available for brands',
                ], 403);
            }

            $perPage = $request->integer('per_page', 10);
            $perPage = min(max($perPage, 1), 100);
            $page = $request->integer('page', 1);

            $transactions = $this->transactionRepository->getBrandHistory($user, $perPage, $page);

            return (new TransactionCollection($transactions))->response();
        } catch (Exception $e) {
            Log::error('Error fetching brand transaction history', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch brand transaction history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

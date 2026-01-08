<?php

declare(strict_types=1);

namespace App\Domain\Payment\Repositories;

use App\Models\Payment\JobPayment;
use App\Models\Payment\Transaction;
use App\Models\User\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * TransactionRepository handles data access for Transactions.
 */
class TransactionRepository
{
    /**
     * Get transaction history for a user (paginated).
     */
    public function getUserHistory(User $user, int $perPage, int $page): LengthAwarePaginator
    {
        return $user->transactions()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page)
        ;
    }

    /**
     * Get transaction history for a brand (paginated), including contract-related transactions.
     */
    public function getBrandHistory(User $brand, int $perPage, int $page): LengthAwarePaginator
    {
        $contractIds = $brand->brandContracts()->pluck('id');

        $jobPaymentTransactionIds = JobPayment::where('brand_id', $brand->id)
            ->whereNotNull('transaction_id')
            ->pluck('transaction_id')
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
        ;

        return Transaction::where(function ($query) use ($brand, $contractIds, $jobPaymentTransactionIds): void {
            if ($contractIds->isNotEmpty()) {
                $query->whereIn('contract_id', $contractIds);
            }
            $query->orWhere('user_id', $brand->id);

            if ($contractIds->isNotEmpty()) {
                $query->orWhereHas('contract', function ($q) use ($brand): void {
                    $q->where('brand_id', $brand->id);
                });
            }

            if ($jobPaymentTransactionIds->isNotEmpty()) {
                $query->orWhereIn('id', $jobPaymentTransactionIds);
            }
        })
            ->with([
                'contract' => function ($query): void {
                    $query->select('id', 'title', 'budget', 'creator_id', 'brand_id')
                        ->with([
                            'creator' => function ($q): void {
                                $q->select('id', 'name', 'email');
                            }
                        ])
                    ;
                }
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page)
        ;
    }

    /**
     * Create a new transaction.
     */
    public function create(array $data): Transaction
    {
        return Transaction::create($data);
    }
}

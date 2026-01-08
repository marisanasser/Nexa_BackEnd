<?php

declare(strict_types=1);

namespace App\Domain\Payment\Repositories;

use App\Models\Payment\CreatorBalance;
use App\Models\Payment\Withdrawal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * WithdrawalRepository handles all data access for Withdrawal entities.
 *
 * This repository abstracts database queries away from business logic,
 * making testing easier and providing a single point of modification
 * for withdrawal-related queries.
 */
class WithdrawalRepository
{
    /**
     * Find a withdrawal by ID.
     */
    public function findById(int $id): ?Withdrawal
    {
        return Withdrawal::find($id);
    }

    /**
     * Find a withdrawal by ID or throw exception.
     */
    public function findByIdOrFail(int $id): Withdrawal
    {
        return Withdrawal::findOrFail($id);
    }

    /**
     * Get all withdrawals for a creator.
     */
    public function getByCreatorId(int $creatorId, int $perPage = 15): LengthAwarePaginator
    {
        return Withdrawal::where('creator_id', $creatorId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
        ;
    }

    /**
     * Get pending withdrawals for a creator.
     *
     * @return Collection<int, Withdrawal>
     */
    public function getPendingByCreatorId(int $creatorId): Collection
    {
        return Withdrawal::where('creator_id', $creatorId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get()
        ;
    }

    /**
     * Get all pending withdrawals (for admin).
     */
    public function getAllPending(int $perPage = 15): LengthAwarePaginator
    {
        return Withdrawal::where('status', 'pending')
            ->with('creator')
            ->orderBy('created_at', 'asc')
            ->paginate($perPage)
        ;
    }

    /**
     * Count pending withdrawals.
     */
    public function countPending(): int
    {
        return Withdrawal::where('status', 'pending')->count();
    }

    /**
     * Get total amount of pending withdrawals.
     */
    public function getTotalPendingAmount(): float
    {
        return (float) Withdrawal::where('status', 'pending')->sum('amount');
    }

    /**
     * Create a new withdrawal.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): Withdrawal
    {
        return Withdrawal::create($data);
    }

    /**
     * Update a withdrawal.
     *
     * @param array<string, mixed> $data
     */
    public function update(Withdrawal $withdrawal, array $data): bool
    {
        return $withdrawal->update($data);
    }

    /**
     * Get creator's available balance.
     */
    public function getCreatorAvailableBalance(int $creatorId): float
    {
        $balance = CreatorBalance::where('user_id', $creatorId)->first();

        return $balance ? (float) $balance->available_balance : 0.0;
    }

    /**
     * Check if creator has a pending withdrawal.
     */
    public function hasPendingWithdrawal(int $creatorId): bool
    {
        return Withdrawal::where('creator_id', $creatorId)
            ->whereIn('status', ['pending', 'processing', 'approved'])
            ->exists()
        ;
    }

    /**
     * Get withdrawal stats for a creator.
     *
     * @return array{total_withdrawn: float, total_pending: float, withdrawal_count: int}
     */
    public function getCreatorStats(int $creatorId): array
    {
        $stats = Withdrawal::where('creator_id', $creatorId)
            ->selectRaw("
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_withdrawn,
                SUM(CASE WHEN status IN ('pending', 'processing', 'approved') THEN amount ELSE 0 END) as total_pending,
                COUNT(*) as withdrawal_count
            ")
            ->first()
        ;

        return [
            'total_withdrawn' => (float) ($stats->total_withdrawn ?? 0),
            'total_pending' => (float) ($stats->total_pending ?? 0),
            'withdrawal_count' => (int) ($stats->withdrawal_count ?? 0),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Contract\Repositories;

use App\Models\Contract\Contract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * ContractRepository handles all data access for Contract entities.
 *
 * This repository abstracts database queries away from business logic,
 * making testing easier and providing a single point of modification
 * for contract-related queries.
 */
class ContractRepository
{
    /**
     * Find a contract by ID.
     */
    public function findById(int $id): ?Contract
    {
        return Contract::find($id);
    }

    /**
     * Find a contract by ID or throw exception.
     */
    public function findByIdOrFail(int $id): Contract
    {
        return Contract::findOrFail($id);
    }

    /**
     * Find a contract by ID with relationships loaded.
     *
     * @param array<string> $with Relationships to load
     */
    public function findByIdWithRelations(int $id, array $with = []): ?Contract
    {
        return Contract::with($with)->find($id);
    }

    /**
     * Get contracts for a creator.
     *
     * @param null|string $status Optional status filter
     */
    public function getByCreatorId(int $creatorId, ?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = Contract::where('creator_id', $creatorId)
            ->with(['brand', 'campaign'])
        ;

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get contracts for a brand.
     *
     * @param null|string $status Optional status filter
     */
    public function getByBrandId(int $brandId, ?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = Contract::where('brand_id', $brandId)
            ->with(['creator', 'campaign'])
        ;

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get active contracts for a user (either as brand or creator).
     *
     * @return Collection<int, Contract>
     */
    public function getActiveForUser(int $userId): Collection
    {
        return Contract::query()
            ->where(function ($query) use ($userId): void {
                $query->where('creator_id', $userId)
                    ->orWhere('brand_id', $userId)
                ;
            })
            ->whereIn('status', ['pending', 'in_progress', 'pending_review'])
            ->with(['creator', 'brand', 'campaign'])
            ->orderBy('created_at', 'desc')
            ->get()
        ;
    }

    /**
     * Count contracts by status for a user.
     *
     * @return array<string, int>
     */
    public function countByStatusForUser(int $userId): array
    {
        return Contract::query()
            ->where(function ($query) use ($userId): void {
                $query->where('creator_id', $userId)
                    ->orWhere('brand_id', $userId)
                ;
            })
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray()
        ;
    }

    /**
     * Create a new contract.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): Contract
    {
        return Contract::create($data);
    }

    /**
     * Update a contract.
     *
     * @param array<string, mixed> $data
     */
    public function update(Contract $contract, array $data): bool
    {
        return $contract->update($data);
    }

    /**
     * Get contracts that are pending completion.
     *
     * @return Collection<int, Contract>
     */
    public function getPendingCompletion(): Collection
    {
        return Contract::where('status', 'pending_review')
            ->with(['creator', 'brand'])
            ->get()
        ;
    }

    /**
     * Get contracts in dispute.
     *
     * @return Collection<int, Contract>
     */
    public function getInDispute(): Collection
    {
        return Contract::where('status', 'disputed')
            ->with(['creator', 'brand', 'campaign'])
            ->orderBy('updated_at', 'asc')
            ->get()
        ;
    }

    /**
     * Get contract statistics for a user.
     *
     * @return array{active: int, completed: int, cancelled: int, total_earnings: float}
     */
    public function getStatsForUser(int $userId): array
    {
        $contracts = Contract::where('creator_id', $userId)->get();

        return [
            'active' => $contracts->whereIn('status', ['pending', 'in_progress', 'pending_review'])->count(),
            'completed' => $contracts->where('status', 'completed')->count(),
            'cancelled' => $contracts->where('status', 'cancelled')->count(),
            'total_earnings' => (float) $contracts->where('status', 'completed')->sum('creator_amount'),
        ];
    }
}

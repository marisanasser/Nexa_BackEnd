<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Models\Payment\WithdrawalMethod;
use Illuminate\Database\Eloquent\Collection;

class WithdrawalMethodService
{
    /**
     * Get all withdrawal methods sorted by sort order.
     */
    public function getAll(): Collection
    {
        return WithdrawalMethod::orderBy('sort_order')->get();
    }

    /**
     * Create a new withdrawal method.
     */
    public function create(array $data): WithdrawalMethod
    {
        return WithdrawalMethod::create($data);
    }

    /**
     * Update a withdrawal method.
     */
    public function update(WithdrawalMethod $method, array $data): WithdrawalMethod
    {
        $method->update($data);

        return $method;
    }

    /**
     * Delete a withdrawal method.
     */
    public function delete(WithdrawalMethod $method): void
    {
        $method->delete();
    }

    /**
     * Toggle the active status of a withdrawal method.
     */
    public function toggleActive(WithdrawalMethod $method): WithdrawalMethod
    {
        $method->update(['is_active' => !$method->is_active]);

        return $method;
    }
}

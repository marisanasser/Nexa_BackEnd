<?php

declare(strict_types=1);

namespace App\Domain\Contract\Services;

use App\Models\Contract\Contract;
use App\Models\Contract\ContractAuditLog;
use App\Models\User\User;
use Illuminate\Support\Facades\Request;

class ContractAuditService
{
    /**
     * Log an action in the contract audit log.
     */
    public function log(Contract $contract, string $action, array $payload = [], ?User $user = null): ContractAuditLog
    {
        return ContractAuditLog::create([
            'contract_id' => $contract->id,
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'payload' => $payload,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Services;

use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignAuditLog;
use Illuminate\Support\Facades\Auth;

class CampaignAuditService
{
    public function log(Campaign $campaign, string $action, ?array $details = null): void
    {
        CampaignAuditLog::create([
            'campaign_id' => $campaign->id,
            'user_id' => Auth::id(), // Can be null for system actions
            'action' => $action,
            'details' => $details,
            'ip_address' => request()->ip(),
        ]);
    }
}

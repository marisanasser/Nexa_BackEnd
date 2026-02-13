<?php

namespace App\Models\Campaign;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'user_id',
        'action',
        'details',
        'ip_address',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

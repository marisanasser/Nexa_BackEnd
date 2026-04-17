<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorBalanceAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'amount',
        'kind',
        'affects_available',
        'reason',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'affects_available' => 'boolean',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}


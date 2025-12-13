<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\NotificationService;

class JobPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'brand_id',
        'creator_id',
        'total_amount',
        'platform_fee',
        'creator_amount',
        'payment_method',
        'transaction_id',
        'status',
        'paid_at',
        'processed_at',
        'payment_data',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'creator_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'processed_at' => 'datetime',
        'payment_data' => 'array',
    ];

    
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }

    
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    public function canBeProcessed(): bool
    {
        return $this->isPending();
    }

    public function process(): bool
    {
        if (!$this->canBeProcessed()) {
            return false;
        }

        $this->update([
            'status' => 'processing',
        ]);

        
        try {
            
            $this->processPayment();
            
            $this->update([
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            
            $this->updateCreatorBalance();

            
            NotificationService::notifyUserOfPaymentCompleted($this);

            return true;
        } catch (\Exception $e) {
            $this->update([
                'status' => 'failed',
            ]);

            
            NotificationService::notifyUserOfPaymentFailed($this, $e->getMessage());

            return false;
        }
    }

    private function processPayment(): void
    {
        
        
        
        
        sleep(1);
        
        
        $this->update([
            'transaction_id' => 'TXN_' . time() . '_' . $this->id,
        ]);
    }

    private function updateCreatorBalance(): void
    {
        $balance = CreatorBalance::firstOrCreate(
            ['creator_id' => $this->creator_id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_earned' => 0,
                'total_withdrawn' => 0,
            ]
        );

        
        $balance->decrement('pending_balance', $this->creator_amount);
        $balance->increment('available_balance', $this->creator_amount);
    }

    public function refund(?string $reason = null): bool
    {
        if (!$this->isCompleted()) {
            return false;
        }

        $this->update([
            'status' => 'refunded',
        ]);

        
        $balance = CreatorBalance::where('creator_id', $this->creator_id)->first();
        if ($balance) {
            $balance->decrement('available_balance', $this->creator_amount);
            $balance->decrement('total_earned', $this->creator_amount);
        }

        
        NotificationService::notifyUserOfPaymentRefunded($this, $reason);

        return true;
    }

    public function getFormattedTotalAmountAttribute(): string
    {
        return 'R$ ' . number_format($this->total_amount, 2, ',', '.');
    }

    public function getFormattedCreatorAmountAttribute(): string
    {
        return 'R$ ' . number_format($this->creator_amount, 2, ',', '.');
    }

    public function getFormattedPlatformFeeAttribute(): string
    {
        return 'R$ ' . number_format($this->platform_fee, 2, ',', '.');
    }

    public function getStatusColorAttribute(): string
    {
        switch ($this->status) {
            case 'completed':
                return 'text-green-600';
            case 'processing':
                return 'text-blue-600';
            case 'pending':
                return 'text-yellow-600';
            case 'failed':
                return 'text-red-600';
            case 'refunded':
                return 'text-gray-600';
            default:
                return 'text-gray-600';
        }
    }

    public function getStatusBadgeColorAttribute(): string
    {
        switch ($this->status) {
            case 'completed':
                return 'bg-green-100 text-green-800';
            case 'processing':
                return 'bg-blue-100 text-blue-800';
            case 'pending':
                return 'bg-yellow-100 text-yellow-800';
            case 'failed':
                return 'bg-red-100 text-red-800';
            case 'refunded':
                return 'bg-gray-100 text-gray-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    }
} 
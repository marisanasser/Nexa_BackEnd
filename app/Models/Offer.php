<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;
use App\Services\NotificationService;

class Offer extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'creator_id',
        'campaign_id',
        'chat_room_id',
        'title',
        'description',
        'budget',
        'estimated_days',
        'requirements',
        'status',
        'expires_at',
        'accepted_at',
        'rejected_at',
        'rejection_reason',
        'is_barter',
        'barter_description',
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'requirements' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'is_barter' => 'boolean',
    ];

    // Relationships
    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'pending')
                    ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'pending')
                    ->where('expires_at', '<=', now());
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    // Methods
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isBarter(): bool
    {
        return $this->is_barter === true;
    }

    public function isCash(): bool
    {
        return $this->is_barter === false;
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function canBeAccepted(): bool
    {
        return $this->isPending() && !$this->isExpired();
    }

    public function canBeRejected(): bool
    {
        return $this->isPending() && !$this->isExpired();
    }

    public function canBeCancelled(): bool
    {
        return $this->isPending() && !$this->isExpired();
    }

    public function cancel(): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
        ]);

        // Notify creator about cancellation
        NotificationService::notifyUserOfOfferCancelled($this);

        return true;
    }

    public function accept(): bool
    {
        if (!$this->canBeAccepted()) {
            return false;
        }

        try {
            // Start a database transaction
            \Illuminate\Support\Facades\DB::beginTransaction();

            $this->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            // For barter offers, no payment processing is needed
            if ($this->isBarter()) {
                // Create contract without payment processing
                $contract = Contract::create([
                    'offer_id' => $this->id,
                    'brand_id' => $this->brand_id,
                    'creator_id' => $this->creator_id,
                    'title' => $this->title ?? 'Contrato de Permuta',
                    'description' => $this->description ?? 'Contrato criado a partir de oferta de permuta',
                    'budget' => 0, // No budget for barter
                    'platform_fee' => 0,
                    'creator_amount' => 0,
                    'estimated_days' => $this->estimated_days,
                    'requirements' => $this->requirements ?? [],
                    'started_at' => now(),
                    'expected_completion_at' => now()->addDays($this->estimated_days),
                    'status' => 'active', // Set to active for barter contracts
                    'workflow_status' => 'active',
                ]);

                if (!$contract) {
                    throw new \Exception('Failed to create barter contract');
                }

                \Illuminate\Support\Facades\DB::commit();
                return true;
            }

            // Calculate platform fee (10%) and creator amount (90%) for cash offers
            $platformFee = $this->budget * 0.10;
            $creatorAmount = $this->budget * 0.90;

            // Create contract with better error handling
            $contract = Contract::create([
                'offer_id' => $this->id,
                'brand_id' => $this->brand_id,
                'creator_id' => $this->creator_id,
                'title' => $this->title ?? 'Contrato de Projeto',
                'description' => $this->description ?? 'Contrato criado a partir de oferta',
                'budget' => $this->budget,
                'platform_fee' => $platformFee,
                'creator_amount' => $creatorAmount,
                'estimated_days' => $this->estimated_days,
                'requirements' => $this->requirements ?? [],
                'started_at' => now(),
                'expected_completion_at' => now()->addDays($this->estimated_days),
                'status' => 'pending', // Set to pending until payment is processed
                'workflow_status' => 'payment_pending',
            ]);

            if (!$contract) {
                throw new \Exception('Failed to create contract');
            }

            // Process automatic payment
            $paymentService = new \App\Services\AutomaticPaymentService();
            $paymentResult = $paymentService->processContractPayment($contract);

            if (!$paymentResult['success']) {
                // If payment fails, update contract status
                $contract->update([
                    'status' => 'payment_failed',
                    'workflow_status' => 'payment_failed',
                ]);

                // Log the payment failure
                \Illuminate\Support\Facades\Log::error('Automatic payment failed for contract', [
                    'contract_id' => $contract->id,
                    'offer_id' => $this->id,
                    'error' => $paymentResult['message'],
                ]);
            }

            // Commit the transaction
            \Illuminate\Support\Facades\DB::commit();

            // Notify brand about acceptance
            NotificationService::notifyUserOfOfferAccepted($this);

            return true;

        } catch (\Exception $e) {
            // Rollback the transaction on error
            \Illuminate\Support\Facades\DB::rollBack();

            // Log the error
            \Illuminate\Support\Facades\Log::error('Error accepting offer', [
                'offer_id' => $this->id,
                'brand_id' => $this->brand_id,
                'creator_id' => $this->creator_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Revert offer status
            $this->update([
                'status' => 'pending',
                'accepted_at' => null,
            ]);

            return false;
        }
    }

    public function reject(string $reason = null): bool
    {
        if (!$this->canBeAccepted()) {
            return false;
        }

        $this->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        // Notify brand about rejection
        NotificationService::notifyUserOfOfferRejected($this, $reason);

        return true;
    }

    public function getFormattedBudgetAttribute(): string
    {
        return 'R$ ' . number_format($this->budget, 2, ',', '.');
    }

    public function getDaysUntilExpiryAttribute(): int
    {
        if ($this->expires_at->isPast()) {
            return 0; // Already expired
        }
        
        $diffInHours = now()->diffInHours($this->expires_at, false);
        if ($diffInHours < 24) {
            return 1; // Less than 24 hours = 1 day
        }
        
        return now()->diffInDays($this->expires_at, false);
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        return $this->days_until_expiry <= 1;
    }

    public function getTitleAttribute($value): string
    {
        return $value ?? 'Oferta de Projeto';
    }

    public function getDescriptionAttribute($value): string
    {
        return $value ?? 'Oferta enviada via chat';
    }

    protected static function booted()
    {
        // Auto-expire offers after 1 day
        static::creating(function ($offer) {
            if (!$offer->expires_at) {
                $offer->expires_at = now()->addDay();
            }
        });
    }
} 
<?php

namespace App\Models;

use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        return $this->isPending() && ! $this->isExpired();
    }

    public function canBeRejected(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    public function canBeCancelled(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    public function cancel(): bool
    {
        if (! $this->canBeCancelled()) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
        ]);

        NotificationService::notifyUserOfOfferCancelled($this);

        return true;
    }

    public function accept(): bool
    {
        if (! $this->canBeAccepted()) {
            return false;
        }

        try {

            \Illuminate\Support\Facades\DB::beginTransaction();

            $this->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            if ($this->isBarter()) {

                $contract = Contract::create([
                    'offer_id' => $this->id,
                    'brand_id' => $this->brand_id,
                    'creator_id' => $this->creator_id,
                    'title' => $this->title ?? 'Contrato de Permuta',
                    'description' => $this->description ?? 'Contrato criado a partir de oferta de permuta',
                    'budget' => 0,
                    'platform_fee' => 0,
                    'creator_amount' => 0,
                    'estimated_days' => $this->estimated_days,
                    'requirements' => $this->requirements ?? [],
                    'started_at' => now(),
                    'expected_completion_at' => now()->addDays($this->estimated_days),
                    'status' => 'active',
                    'workflow_status' => 'active',
                ]);

                if (! $contract) {
                    throw new \Exception('Failed to create barter contract');
                }

                \Illuminate\Support\Facades\DB::commit();

                return true;
            }

            $platformFee = $this->budget * 0.10;
            $creatorAmount = $this->budget * 0.90;

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
                'status' => 'pending',
                'workflow_status' => 'payment_pending',
            ]);

            if (! $contract) {
                throw new \Exception('Failed to create contract');
            }

            $paymentService = new \App\Services\AutomaticPaymentService;
            $paymentResult = $paymentService->processContractPayment($contract);

            if (! $paymentResult['success']) {

                $contract->update([
                    'status' => 'payment_failed',
                    'workflow_status' => 'payment_failed',
                ]);

                \Illuminate\Support\Facades\Log::error('Automatic payment failed for contract', [
                    'contract_id' => $contract->id,
                    'offer_id' => $this->id,
                    'error' => $paymentResult['message'],
                ]);
            }

            \Illuminate\Support\Facades\DB::commit();

            NotificationService::notifyUserOfOfferAccepted($this);

            return true;

        } catch (\Exception $e) {

            \Illuminate\Support\Facades\DB::rollBack();

            \Illuminate\Support\Facades\Log::error('Error accepting offer', [
                'offer_id' => $this->id,
                'brand_id' => $this->brand_id,
                'creator_id' => $this->creator_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->update([
                'status' => 'pending',
                'accepted_at' => null,
            ]);

            return false;
        }
    }

    public function reject(?string $reason = null): bool
    {
        if (! $this->canBeAccepted()) {
            return false;
        }

        $this->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        NotificationService::notifyUserOfOfferRejected($this, $reason);

        return true;
    }

    public function getFormattedBudgetAttribute(): string
    {
        return 'R$ '.number_format($this->budget, 2, ',', '.');
    }

    public function getDaysUntilExpiryAttribute(): int
    {
        if ($this->expires_at->isPast()) {
            return 0;
        }

        $diffInHours = now()->diffInHours($this->expires_at, false);
        if ($diffInHours < 24) {
            return 1;
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

        static::creating(function ($offer) {
            if (! $offer->expires_at) {
                $offer->expires_at = now()->addDay();
            }
        });
    }
}

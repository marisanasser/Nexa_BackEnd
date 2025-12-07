<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CampaignApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'creator_id',
        'status',
        'workflow_status',
        'proposal',
        'portfolio_links',
        'estimated_delivery_days',
        'proposed_budget',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'approved_at',
        'first_contact_at',
        'agreement_finalized_at'
    ];

    protected $casts = [
        'portfolio_links' => 'array',
        'proposed_budget' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'first_contact_at' => 'datetime',
        'agreement_finalized_at' => 'datetime',
    ];

    
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function chatRoom(): HasOne
    {
        return $this->hasOne(ChatRoom::class, 'campaign_id', 'campaign_id')
            ->where('creator_id', $this->creator_id);
    }

    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeByCreator($query, $creatorId)
    {
        return $query->where('creator_id', $creatorId);
    }

    public function scopeByCampaign($query, $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    public function scopeFirstContactPending($query)
    {
        return $query->where('workflow_status', 'first_contact_pending');
    }

    public function scopeAgreementInProgress($query)
    {
        return $query->where('workflow_status', 'agreement_in_progress');
    }

    public function scopeAgreementFinalized($query)
    {
        return $query->where('workflow_status', 'agreement_finalized');
    }

    
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isFirstContactPending(): bool
    {
        return $this->workflow_status === 'first_contact_pending';
    }

    public function isAgreementInProgress(): bool
    {
        return $this->workflow_status === 'agreement_in_progress';
    }

    public function isAgreementFinalized(): bool
    {
        return $this->workflow_status === 'agreement_finalized';
    }

    public function approve($brandId): bool
    {
        $this->update([
            'status' => 'approved',
            'workflow_status' => 'first_contact_pending',
            'reviewed_by' => $brandId,
            'reviewed_at' => now(),
            'approved_at' => now(),
            'rejection_reason' => null
        ]);

        return true;
    }

    public function reject($brandId, $reason = null): bool
    {
        $this->update([
            'status' => 'rejected',
            'workflow_status' => 'first_contact_pending', 
            'reviewed_by' => $brandId,
            'reviewed_at' => now(),
            'rejection_reason' => $reason
        ]);

        return true;
    }

    public function initiateFirstContact(): bool
    {
        if (!$this->isApproved() || !$this->isFirstContactPending()) {
            return false;
        }

        $this->update([
            'workflow_status' => 'agreement_in_progress',
            'first_contact_at' => now()
        ]);

        return true;
    }

    public function finalizeAgreement(): bool
    {
        if (!$this->isApproved() || !$this->isAgreementInProgress()) {
            return false;
        }

        $this->update([
            'workflow_status' => 'agreement_finalized',
            'agreement_finalized_at' => now()
        ]);

        return true;
    }

    public function canBeReviewedBy($user): bool
    {
        return $this->campaign->brand_id === $user->id && $this->isPending();
    }

    public function canBeWithdrawnBy($user): bool
    {
        return $this->creator_id === $user->id && $this->isPending();
    }

    public function canInitiateFirstContact(): bool
    {
        return $this->isApproved() && $this->isFirstContactPending();
    }

    public function canFinalizeAgreement(): bool
    {
        return $this->isApproved() && $this->isAgreementInProgress();
    }

    
    public function getWorkflowStatusLabelAttribute(): string
    {
        return match($this->workflow_status) {
            'first_contact_pending' => 'Primeiro Contato Pendente',
            'agreement_in_progress' => 'Acordo em Andamento',
            'agreement_finalized' => 'Acordo Finalizado',
            default => 'Desconhecido'
        };
    }

    
    public function getWorkflowStatusColorAttribute(): string
    {
        return match($this->workflow_status) {
            'first_contact_pending' => 'warning',
            'agreement_in_progress' => 'info',
            'agreement_finalized' => 'success',
            default => 'default'
        };
    }
}

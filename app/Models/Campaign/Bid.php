<?php

declare(strict_types=1);

namespace App\Models\Campaign;

use App\Models\User\User;
use App\Domain\Notification\Services\CampaignNotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bid model for campaign proposals.
 *
 * @property int           $id
 * @property int           $campaign_id
 * @property int           $user_id
 * @property float|string  $bid_amount
 * @property null|string   $proposal
 * @property null|string   $portfolio_links
 * @property null|int      $estimated_delivery_days
 * @property string        $status
 * @property null|Carbon   $accepted_at
 * @property null|string   $rejection_reason
 * @property null|Carbon   $created_at
 * @property null|Carbon   $updated_at
 * @property null|Campaign $campaign
 * @property null|User     $user
 */
class Bid extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'user_id',
        'bid_amount',
        'proposal',
        'portfolio_links',
        'estimated_delivery_days',
        'status',
        'accepted_at',
        'rejection_reason',
    ];

    protected $casts = [
        'bid_amount' => 'decimal:2',
        'accepted_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function isPending(): bool
    {
        return 'pending' === $this->status;
    }

    public function isAccepted(): bool
    {
        return 'accepted' === $this->status;
    }

    public function isRejected(): bool
    {
        return 'rejected' === $this->status;
    }

    public function isWithdrawn(): bool
    {
        return 'withdrawn' === $this->status;
    }

    public function accept(): bool
    {
        $this->campaign->bids()->where('id', '!=', $this->id)->update(['status' => 'rejected']);

        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $this->campaign->update([
            'final_price' => $this->bid_amount,
        ]);

        CampaignNotificationService::notifyCreatorOfProposalStatus($this, 'accepted');

        return true;
    }

    public function reject($reason = null): bool
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        CampaignNotificationService::notifyCreatorOfProposalStatus($this, 'rejected', $reason);

        return true;
    }

    public function withdraw(): bool
    {
        $this->update([
            'status' => 'withdrawn',
        ]);

        return true;
    }
}

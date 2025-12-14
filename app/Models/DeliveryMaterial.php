<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'creator_id',
        'brand_id',
        'milestone_id',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
        'media_type',
        'title',
        'description',
        'status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
        'comment',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(CampaignTimeline::class, 'milestone_id');
    }

    public function getFileUrlAttribute(): string
    {
        return asset('storage/'.$this->file_path);
    }

    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    public function getThumbnailUrlAttribute(): string
    {

        return $this->file_url;
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

    public function isImage(): bool
    {
        return $this->media_type === 'image';
    }

    public function isVideo(): bool
    {
        return $this->media_type === 'video';
    }

    public function isDocument(): bool
    {
        return $this->media_type === 'document';
    }

    public function canBeReviewedBy($user): bool
    {
        return $this->brand_id === $user->id && $this->isPending();
    }

    public function approve($brandId, ?string $comment = null): bool
    {
        $this->update([
            'status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => $brandId,
            'comment' => $comment,
            'rejection_reason' => null,
        ]);

        return true;
    }

    public function reject($brandId, ?string $reason = null, ?string $comment = null): bool
    {
        $this->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewed_by' => $brandId,
            'rejection_reason' => $reason,
            'comment' => $comment,
        ]);

        return true;
    }

    public function getStatusColor(): string
    {
        if ($this->isApproved()) {
            return 'green';
        }
        if ($this->isRejected()) {
            return 'red';
        }

        return 'yellow';
    }

    public function getStatusIcon(): string
    {
        if ($this->isApproved()) {
            return '✅';
        }
        if ($this->isRejected()) {
            return '❌';
        }

        return '⏳';
    }

    public function getMediaTypeIcon(): string
    {
        switch ($this->media_type) {
            case 'image':
                return '🖼️';
            case 'video':
                return '🎥';
            case 'document':
                return '📄';
            default:
                return '📎';
        }
    }
}

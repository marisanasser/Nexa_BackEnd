<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class CampaignTimeline extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'milestone_type',
        'title',
        'description',
        'deadline',
        'completed_at',
        'status',
        'comment',
        'file_path',
        'file_name',
        'file_size',
        'file_type',
        'justification',
        'is_delayed',
        'delay_notified_at',
        'extension_days',
        'extension_reason',
        'extended_at',
        'extended_by',
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'completed_at' => 'datetime',
        'delay_notified_at' => 'datetime',
        'is_delayed' => 'boolean',
        'extended_at' => 'datetime',
    ];

    protected $appends = [
        'can_upload_file',
        'can_be_approved',
        'can_request_approval',
        'can_justify_delay',
        'can_be_extended',
        'is_extended',
        'total_extension_days',
        'days_until_deadline',
        'days_overdue',
        'is_overdue',
        'status_icon',
        'milestone_icon',
        'status_color',
        'formatted_deadline',
        'formatted_completed_at',
        'formatted_file_size',
    ];

    
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function deliveryMaterials(): HasMany
    {
        return $this->hasMany(DeliveryMaterial::class, 'milestone_id');
    }

    
    const MILESTONE_TYPES = [
        'script_submission' => 'Envio do Roteiro',
        'script_approval' => 'Aprovação do Roteiro',
        'video_submission' => 'Envio do Vídeo',
        'final_approval' => 'Aprovação Final',
    ];

    
    const STATUSES = [
        'pending' => 'Pendente',
        'approved' => 'Aprovado',
        'delayed' => 'Atrasado',
        'completed' => 'Concluído',
    ];

    
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isDelayed(): bool
    {
        return $this->status === 'delayed' || $this->is_delayed;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isOverdue(): bool
    {
        return $this->deadline && $this->deadline->isPast() && !$this->isCompleted();
    }

    public function getDaysUntilDeadline(): int
    {
        if (!$this->deadline) {
            return 0;
        }
        return max(0, now()->diffInDays($this->deadline, false));
    }

    public function getDaysOverdue(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }
        return abs(now()->diffInDays($this->deadline, false));
    }

    public function getStatusColor(): string
    {
        if ($this->isCompleted()) {
            return 'green';
        }
        if ($this->isApproved()) {
            return 'blue';
        }
        if ($this->isDelayed() || $this->isOverdue()) {
            return 'red';
        }
        return 'yellow';
    }

    public function getStatusIcon(): string
    {
        if ($this->isCompleted()) {
            return '🟢';
        }
        if ($this->isApproved()) {
            return '🟢';
        }
        if ($this->isDelayed() || $this->isOverdue()) {
            return '🔴';
        }
        return '🟡';
    }

    public function getMilestoneIcon(): string
    {
        switch ($this->milestone_type) {
            case 'script_submission':
                return '📝';
            case 'script_approval':
                return '✅';
            case 'video_submission':
                return '🎥';
            case 'final_approval':
                return '🏆';
            default:
                return '📋';
        }
    }

    public function canBeCompleted(): bool
    {
        return $this->isPending() || $this->isApproved();
    }

    public function canBeApproved(): bool
    {
        return $this->isPending() && $this->file_path;
    }

    public function canUploadFile(): bool
    {
        return $this->isPending() && in_array($this->milestone_type, ['script_submission', 'video_submission']);
    }

    public function canRequestApproval(): bool
    {
        return $this->isPending() && in_array($this->milestone_type, ['script_approval', 'final_approval']);
    }

    public function canJustifyDelay(): bool
    {
        return $this->isDelayed() && !$this->justification;
    }

    public function markAsCompleted(): bool
    {
        if (!$this->canBeCompleted()) {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return true;
    }

    public function markAsApproved(?string $comment = null): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->update([
            'status' => 'approved',
            'comment' => $comment,
        ]);

        return true;
    }

    public function markAsDelayed(?string $justification = null): bool
    {
        $this->update([
            'status' => 'delayed',
            'is_delayed' => true,
            'justification' => $justification,
            'delay_notified_at' => now(),
        ]);

        return true;
    }

    public function uploadFile(string $filePath, string $fileName, string $fileSize, string $fileType): bool
    {
        if (!$this->canUploadFile()) {
            return false;
        }

        $this->update([
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_type' => $fileType,
        ]);

        return true;
    }

    public function justifyDelay(string $justification): bool
    {
        if (!$this->canJustifyDelay()) {
            return false;
        }

        $this->update([
            'justification' => $justification,
        ]);

        return true;
    }

    
    public function extendTimeline(int $days, string $reason, int $extendedBy): bool
    {
        $this->update([
            'extension_days' => $this->extension_days + $days,
            'extension_reason' => $reason,
            'extended_at' => now(),
            'extended_by' => $extendedBy,
            'deadline' => $this->deadline->addDays($days),
            'is_delayed' => false, 
            'status' => $this->status === 'delayed' ? 'pending' : $this->status,
        ]);

        return true;
    }

    
    public function isExtended(): bool
    {
        return $this->extension_days > 0;
    }

    
    public function getTotalExtensionDays(): int
    {
        return $this->extension_days;
    }

    
    public function getExtendedDeadline(): \Carbon\Carbon
    {
        return $this->deadline;
    }

    
    public function canBeExtended(): bool
    {
        try {
            return $this->contract && $this->contract->brand_id === (auth()->id() ?? 0);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getFormattedFileSizeAttribute(): string
    {
        if (!$this->file_size) {
            return 'Unknown size';
        }

        $bytes = (int) $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function getFormattedDeadlineAttribute(): ?string
    {
        return $this->deadline ? $this->deadline->format('M d, Y H:i') : null;
    }

    public function getFormattedCompletedAtAttribute(): ?string
    {
        return $this->completed_at ? $this->completed_at->format('M d, Y H:i') : null;
    }

    public function getFormattedDelayNotifiedAtAttribute(): string
    {
        return $this->delay_notified_at ? $this->delay_notified_at->format('M d, Y H:i') : null;
    }

    
    public function getCanUploadFileAttribute(): bool
    {
        return $this->isPending() && in_array($this->milestone_type, ['script_submission', 'video_submission']);
    }

    public function getCanBeApprovedAttribute(): bool
    {
        return $this->isPending() && $this->file_path;
    }

    public function getCanRequestApprovalAttribute(): bool
    {
        return $this->isPending() && in_array($this->milestone_type, ['script_approval', 'final_approval']);
    }

    public function getCanJustifyDelayAttribute(): bool
    {
        return $this->isDelayed() && !$this->justification;
    }

    public function getCanBeExtendedAttribute(): bool
    {
        try {
            return $this->contract && $this->contract->brand_id === (auth()->id() ?? 0);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getIsExtendedAttribute(): bool
    {
        return $this->extension_days > 0;
    }

    public function getTotalExtensionDaysAttribute(): int
    {
        return $this->extension_days ?? 0;
    }

    public function getDaysUntilDeadlineAttribute(): int
    {
        return $this->getDaysUntilDeadline();
    }

    public function getDaysOverdueAttribute(): int
    {
        return $this->getDaysOverdue();
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->isOverdue();
    }

    public function getStatusIconAttribute(): string
    {
        return $this->getStatusIcon();
    }

    public function getMilestoneIconAttribute(): string
    {
        return $this->getMilestoneIcon();
    }

    public function getStatusColorAttribute(): string
    {
        return $this->getStatusColor();
    }
} 
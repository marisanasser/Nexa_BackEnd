<?php

declare(strict_types=1);

namespace App\Models\Campaign;

use App\Models\Contract\Contract;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use function count;
use function in_array;

/**
 * @property int                             $id
 * @property int                             $contract_id
 * @property string                          $milestone_type
 * @property string                          $title
 * @property bool                            $penalty_applied
 * @property null|string                     $description
 * @property null|\Illuminate\Support\Carbon $deadline
 * @property null|\Illuminate\Support\Carbon $completed_at
 * @property string                          $status
 * @property null|string                     $comment
 * @property null|string                     $file_path
 * @property null|string                     $file_name
 * @property null|string                     $file_size
 * @property null|string                     $file_type
 * @property null|string                     $justification
 * @property bool                            $is_delayed
 * @property null|\Illuminate\Support\Carbon $delay_notified_at
 * @property int                             $extension_days
 * @property null|string                     $extension_reason
 * @property null|\Illuminate\Support\Carbon $extended_at
 * @property null|int                        $extended_by
 * @property null|\Illuminate\Support\Carbon $created_at
 * @property null|\Illuminate\Support\Carbon $updated_at
 * @property Contract                        $contract
 * @property Collection|DeliveryMaterial[]   $deliveryMaterials
 *
 * @method static \Illuminate\Database\Eloquent\Builder where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder whereNull($columns, $boolean = 'and', $not = false)
 * @method        mixed                                 getKey()
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class CampaignTimeline extends Model
{
    use HasFactory;

    public const array MILESTONE_TYPES = [
        'script_submission' => 'Envio do Roteiro',
        'script_approval' => 'Aprovação do Roteiro',
        'video_submission' => 'Envio do Vídeo',
        'final_approval' => 'Aprovação Final',
    ];

    public const array STATUSES = [
        'pending' => 'Pendente',
        'approved' => 'Aprovado',
        'rejected' => 'Rejeitado',
        'delayed' => 'Atrasado',
        'completed' => 'Concluído',
    ];

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
        'can_be_rejected',
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

    public function isPending(): bool
    {
        return 'pending' === $this->status;
    }

    public function isApproved(): bool
    {
        return 'approved' === $this->status;
    }

    public function isRejected(): bool
    {
        return 'rejected' === $this->status;
    }

    public function isDelayed(): bool
    {
        return 'delayed' === $this->status || $this->is_delayed;
    }

    public function isCompleted(): bool
    {
        return 'completed' === $this->status;
    }

    public function isOverdue(): bool
    {
        return $this->deadline?->isPast() && !in_array($this->status, ['approved', 'completed'], true);
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
            return '🔵';
        }
        if ($this->isDelayed() || $this->isOverdue()) {
            return '🔴';
        }

        return '🟡';
    }

    public function getMilestoneIcon(): string
    {
        return match ($this->milestone_type) {
            'script_submission' => '📝',
            'script_approval' => '✅',
            'video_submission' => '🎥',
            'final_approval' => '🏆',
            default => '📋',
        };
    }

    public function canBeCompleted(): bool
    {
        return $this->isPending() || $this->isApproved();
    }

    public function canBeApproved(): bool
    {
        if (!$this->isPending() || !$this->isDependencySatisfied()) {
            return false;
        }

        if ($this->isSubmissionMilestone()) {
            return !empty($this->file_path);
        }

        if ($this->isApprovalMilestone()) {
            return true;
        }

        return false;
    }

    public function canBeRejected(): bool
    {
        if (!$this->isPending() || !$this->isDependencySatisfied()) {
            return false;
        }

        if ($this->isSubmissionMilestone()) {
            return !empty($this->file_path);
        }

        if ($this->isApprovalMilestone()) {
            return true;
        }

        return false;
    }

    public function canUploadFile(): bool
    {
        if (!$this->isSubmissionMilestone()) {
            return false;
        }

        if (!in_array($this->status, ['pending', 'rejected'], true)) {
            return false;
        }

        return $this->isDependencySatisfied();
    }

    public function canRequestApproval(): bool
    {
        return $this->isPending() && $this->isApprovalMilestone() && $this->isDependencySatisfied();
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
            'completed_at' => now(),
        ]);

        return true;
    }

    public function markAsRejected(?string $comment = null): bool
    {
        if (!$this->canBeRejected()) {
            return false;
        }

        $this->update([
            'status' => 'rejected',
            'comment' => $comment,
            'completed_at' => null,
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

    public function uploadFile(string $filePath, string $fileName, int | bool $fileSize, string $fileType): bool
    {
        if (!$this->canUploadFile()) {
            return false;
        }

        $this->update([
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_type' => $fileType,
            'status' => 'pending',
            'comment' => null,
            'completed_at' => null,
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
            'status' => 'delayed' === $this->status ? 'pending' : $this->status,
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

    public function getExtendedDeadline(): Carbon
    {
        return $this->deadline;
    }

    public function canBeExtended(): bool
    {
        try {
            return $this->contract && $this->contract->brand_id === (auth()->id() ?? 0);
        } catch (Exception $e) {
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
        $bytes /= 1024 ** $pow;

        return round($bytes, 2).' '.$units[$pow];
    }

    public function getFormattedDeadlineAttribute(): ?string
    {
        return $this->deadline?->format('M d, Y H:i');
    }

    public function getFormattedCompletedAtAttribute(): ?string
    {
        return $this->completed_at?->format('M d, Y H:i');
    }

    public function getFormattedDelayNotifiedAtAttribute(): string
    {
        return $this->delay_notified_at?->format('M d, Y H:i');
    }

    public function getCanUploadFileAttribute(): bool
    {
        return $this->canUploadFile();
    }

    public function getCanBeApprovedAttribute(): bool
    {
        return $this->canBeApproved();
    }

    public function getCanBeRejectedAttribute(): bool
    {
        return $this->canBeRejected();
    }

    public function getCanRequestApprovalAttribute(): bool
    {
        return $this->canRequestApproval();
    }

    public function getCanJustifyDelayAttribute(): bool
    {
        return $this->isDelayed() && !$this->justification;
    }

    public function getCanBeExtendedAttribute(): bool
    {
        return $this->canBeExtended();
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

    /**
     * Get milestones that are overdue and haven't been notified yet.
     *
     * @return Collection<int, self>
     */
    public static function getOverdueForNotification()
    {
        return self::query()
            ->where('deadline', '<', now())
            ->where('status', 'pending')
            ->where('is_delayed', false)
            ->whereNull('delay_notified_at')
            ->with(['contract.creator', 'contract.brand'])
            ->get()
        ;
    }

    public function getUploadBlockerReason(): ?string
    {
        if (!$this->isSubmissionMilestone()) {
            return 'Este não é um milestone de submissão.';
        }

        if (!in_array($this->status, ['pending', 'rejected'], true)) {
            return "O status do milestone não permite upload ({$this->status}).";
        }

        if (!$this->isDependencySatisfied()) {
            $dependencyName = match ($this->milestone_type) {
                'video_submission' => 'Envio do Roteiro aprovado',
                default => 'Etapa anterior',
            };
            return "A etapa anterior ({$dependencyName}) precisa ser concluída antes de enviar este arquivo.";
        }

        return null;
    }

    private function isSubmissionMilestone(): bool
    {
        return in_array($this->milestone_type, ['script_submission', 'video_submission'], true);
    }

    private function isApprovalMilestone(): bool
    {
        return in_array($this->milestone_type, ['script_approval', 'final_approval'], true);
    }

    private function isDependencySatisfied(): bool
    {
        return match ($this->milestone_type) {
            'script_submission' => true,
            'script_approval' => $this->isMilestoneDone($this->getMilestoneByType('script_submission')),
            'video_submission' => $this->isScriptReadyForVideoSubmission(),
            'final_approval' => $this->isMilestoneDone($this->getMilestoneByType('video_submission')),
            default => true,
        };
    }

    /**
     * Supports both the new simplified flow (script approved directly on submission)
     * and legacy contracts that still have a separate script_approval milestone.
     */
    private function isScriptReadyForVideoSubmission(): bool
    {
        return $this->isMilestoneDone($this->getMilestoneByType('script_submission'))
            || $this->isMilestoneDone($this->getMilestoneByType('script_approval'));
    }

    private function isMilestoneDone(?self $milestone): bool
    {
        return $milestone && in_array($milestone->status, ['approved', 'completed'], true);
    }

    private function getMilestoneByType(string $milestoneType): ?self
    {
        return self::query()
            ->where('contract_id', $this->contract_id)
            ->where('milestone_type', $milestoneType)
            ->first();
    }
}

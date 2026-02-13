<?php

declare(strict_types=1);

namespace App\Models\Contract;

use App\Models\Campaign\Campaign;
use App\Models\Chat\ChatRoom;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $brand_id
 * @property int $creator_id
 * @property int $chat_room_id
 * @property int|null $campaign_id
 * @property string $title
 * @property string $description
 * @property float $budget
 * @property int $estimated_days
 * @property array $requirements
 * @property string $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string $formatted_budget
 * @property-read int $days_until_expiry
 * @property-read bool $is_expiring_soon
 * @property-read User $brand
 * @property-read User $creator
 * @property-read Contract|null $contract
 * @property-read Campaign|null $campaign
 * @property-read ChatRoom|null $chatRoom
 */
class Offer extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'creator_id',
        'chat_room_id',
        'campaign_id',
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
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'requirements' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class);
    }

    public function getFormattedBudgetAttribute(): string
    {
        return 'R$ ' . number_format((float) $this->budget, 2, ',', '.');
    }

    public function getDaysUntilExpiryAttribute(): int
    {
        if (!$this->expires_at) {
            return 0;
        }

        return max(0, now()->diffInDays($this->expires_at, false));
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        return $this->days_until_expiry <= 2 && $this->days_until_expiry > 0;
    }

    public function isExpired(): bool
    {
        return $this?->expires_at?->isPast();
    }

    public function canBeAccepted(): bool
    {
        return 'pending' === $this->status && !$this->isExpired();
    }

    public function canBeRejected(): bool
    {
        return 'pending' === $this->status;
    }

    public function canBeCancelled(): bool
    {
        return 'pending' === $this->status;
    }

    public function accept(): bool
    {
        if (!$this->canBeAccepted()) {
            return false;
        }

        return DB::transaction(function () {
            $this->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            if (!$this->contract) {
                $platformFee = round($this->budget * 0.05, 2);
                $creatorAmount = round($this->budget - $platformFee, 2);
                
                // Create the contract
                $contract = Contract::create([
                    'offer_id' => $this->id,
                    'brand_id' => $this->brand_id,
                    'creator_id' => $this->creator_id,
                    'title' => $this->title,
                    'description' => $this->description,
                    'budget' => $this->budget,
                    'estimated_days' => $this->estimated_days,
                    'requirements' => $this->requirements,
                    // Status should be pending until the brand funds it
                    'status' => 'pending', 
                    'workflow_status' => 'payment_pending',
                    'platform_fee' => $platformFee,
                    'creator_amount' => $creatorAmount,
                    // started_at should be set when payment is made
                    'expected_completion_at' => now()->addDays($this->estimated_days),
                    'created_at' => now(),
                ]);

                // Create the standard full timeline for the campaign
                // This ensures the flow matches the real-world process: Script -> Approval -> Video -> Final Approval
                $startDate = now();
                $totalDays = $this->estimated_days ?? 7;

                $timelineMilestones = [
                    [
                        'milestone_type' => 'script_submission',
                        'title' => 'Envio do Roteiro',
                        'description' => 'Enviar o roteiro inicial para revisão da marca.',
                        'status' => 'pending',
                        'deadline' => $startDate->copy()->addDays((int) ceil($totalDays * 0.25)),
                    ],
                    [
                        'milestone_type' => 'script_approval',
                        'title' => 'Aprovação do Roteiro',
                        'description' => 'Aprovação do roteiro pela marca.',
                        'status' => 'pending',
                        'deadline' => $startDate->copy()->addDays((int) ceil($totalDays * 0.35)),
                    ],
                    [
                        'milestone_type' => 'video_submission',
                        'title' => 'Envio de Imagem e Vídeo',
                        'description' => 'Enviar o conteúdo final de imagem e vídeo.',
                        'status' => 'pending',
                        'deadline' => $startDate->copy()->addDays((int) ceil($totalDays * 0.85)),
                    ],
                    [
                        'milestone_type' => 'final_approval',
                        'title' => 'Aprovação Final',
                        'description' => 'Aprovação final do vídeo pela marca.',
                        'status' => 'pending',
                        'deadline' => $startDate->copy()->addDays($totalDays),
                    ],
                ];

                foreach ($timelineMilestones as $milestone) {
                    $contract->timeline()->create($milestone);
                }

                // Also create a default ContractMilestone for backend compatibility (Contract State Machine)
                $contract->milestones()->create([
                    'title' => 'Execução do Projeto',
                    'description' => 'Execução de todas as etapas do projeto (Roteiro e Vídeo).',
                    'status' => 'pending',
                    'amount' => $this->budget,
                    'due_date' => $contract->expected_completion_at,
                    'order' => 1,
                ]);

                $this->refresh();
            }

            return true;
        });
    }

    public function reject(?string $reason = null): bool
    {
        if (!$this->canBeRejected()) {
            return false;
        }

        $this->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return true;
    }
}

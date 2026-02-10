<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Models\Campaign\Campaign;
use App\Models\Contract\Contract;
use App\Models\Contract\Offer;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ChatRoom representa o chat de uma campanha específica.
 * Cada campanha tem um chat dedicado entre brand e creator.
 * 
 * Ciclo de vida:
 * 1. Chat criado quando brand aprova candidatura do creator
 * 2. Briefings e negociação acontecem no chat
 * 3. Entregáveis são discutidos e aprovados
 * 4. Pagamento final realizado
 * 5. Chat é arquivado com todas as informações
 *
 * @property int                  $id
 * @property int                  $campaign_id
 * @property int                  $brand_id
 * @property int                  $creator_id
 * @property string               $room_id
 * @property bool                 $is_active
 * @property string               $chat_status  Status: active, completed, archived
 * @property null|Carbon          $archived_at
 * @property null|string          $closure_reason
 * @property null|array           $campaign_summary
 * @property null|Carbon          $last_message_at
 * @property null|Carbon          $created_at
 * @property null|Carbon          $updated_at
 * @property Campaign             $campaign
 * @property User                 $brand
 * @property User                 $creator
 * @property Collection|Message[] $messages
 * @property Collection|Offer[]   $offers
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class ChatRoom extends Model
{
    use HasFactory;

    // Status constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';

    // Closure reasons
    public const CLOSURE_CAMPAIGN_COMPLETED = 'campaign_completed';
    public const CLOSURE_CAMPAIGN_CANCELLED = 'campaign_cancelled';
    public const CLOSURE_CONTRACT_COMPLETED = 'contract_completed';
    public const CLOSURE_CONTRACT_CANCELLED = 'contract_cancelled';
    public const CLOSURE_PAYMENT_COMPLETED = 'payment_completed';

    protected $fillable = [
        'campaign_id',
        'brand_id',
        'creator_id',
        'room_id',
        'is_active',
        'chat_status',
        'archived_at',
        'closure_reason',
        'campaign_summary',
        'last_message_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_message_at' => 'datetime',
        'archived_at' => 'datetime',
        'campaign_summary' => 'array',
    ];

    protected $attributes = [
        'chat_status' => self::STATUS_ACTIVE,
    ];

    // ==========================================
    // Relacionamentos
    // ==========================================

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'chat_room_id', 'id');
    }

    public function lastMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latest('created_at');
    }

    /**
     * Obtem todos os contratos associados a este chat através das offers.
     */
    public function contracts(): HasManyThrough
    {
        return $this->hasManyThrough(
            Contract::class,
            Offer::class,
            'chat_room_id', // Foreign key on offers table
            'offer_id',      // Foreign key on contracts table
            'id',            // Local key on chat_rooms
            'id'             // Local key on offers
        );
    }

    // ==========================================
    // Scopes
    // ==========================================

    public function scopeActive($query)
    {
        return $query->where('chat_status', self::STATUS_ACTIVE);
    }

    public function scopeCompleted($query)
    {
        return $query->where('chat_status', self::STATUS_COMPLETED);
    }

    public function scopeArchived($query)
    {
        return $query->where('chat_status', self::STATUS_ARCHIVED);
    }

    public function scopeNotArchived($query)
    {
        return $query->where('chat_status', '!=', self::STATUS_ARCHIVED);
    }

    public function scopeForCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    // ==========================================
    // Métodos de Status
    // ==========================================

    public function isActiveChat(): bool
    {
        return self::STATUS_ACTIVE === $this->chat_status;
    }

    public function isCompleted(): bool
    {
        return self::STATUS_COMPLETED === $this->chat_status;
    }

    public function isArchived(): bool
    {
        return self::STATUS_ARCHIVED === $this->chat_status;
    }

    public function canSendMessages(): bool
    {
        return $this->is_active && $this->isActiveChat();
    }

    // ==========================================
    // Métodos de Atualização
    // ==========================================

    public function updateLastMessageTimestamp(): void
    {
        $this->update(['last_message_at' => now()]);
    }

    /**
     * Marca o chat como completo (trabalho finalizado, aguardando pagamento ou arquivamento).
     */
    public function markAsCompleted(string $reason = self::CLOSURE_CONTRACT_COMPLETED): bool
    {
        if ($this->isArchived()) {
            return false;
        }

        $this->update([
            'chat_status' => self::STATUS_COMPLETED,
            'closure_reason' => $reason,
        ]);

        Log::info('Chat marked as completed', [
            'chat_room_id' => $this->id,
            'room_id' => $this->room_id,
            'campaign_id' => $this->campaign_id,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Arquiva o chat com todas as informações do processo.
     * Este método deve ser chamado após o pagamento final.
     */
    public function archive(string $reason = self::CLOSURE_PAYMENT_COMPLETED): bool
    {
        if ($this->isArchived()) {
            return false;
        }

        // Gera o resumo da campanha para relatórios futuros
        $summary = $this->generateCampaignSummary();

        $this->update([
            'chat_status' => self::STATUS_ARCHIVED,
            'is_active' => false,
            'archived_at' => now(),
            'closure_reason' => $reason,
            'campaign_summary' => $summary,
        ]);

        Log::info('Chat archived', [
            'chat_room_id' => $this->id,
            'room_id' => $this->room_id,
            'campaign_id' => $this->campaign_id,
            'reason' => $reason,
            'summary' => $summary,
        ]);

        return true;
    }

    /**
     * Gera um resumo completo da campanha para relatórios.
     */
    public function generateCampaignSummary(): array
    {
        $this->load(['campaign', 'brand', 'creator', 'messages', 'offers.contract']);

        $offers = $this->offers;
        $contracts = $offers->map(fn ($offer) => $offer->contract)->filter();
        
        $totalBudget = $offers->sum('budget');
        $totalPaid = $contracts->where('status', 'completed')->sum('creator_amount');
        $totalMessages = $this->messages->count();

        $timeline = [
            'chat_created_at' => $this->created_at?->toISOString(),
            'first_message_at' => $this->messages->first()?->created_at?->toISOString(),
            'last_message_at' => $this->last_message_at?->toISOString(),
            'archived_at' => now()->toISOString(),
        ];

        // Calcula duração total
        $durationDays = $this->created_at ? $this->created_at->diffInDays(now()) : 0;

        return [
            'campaign' => [
                'id' => $this->campaign_id,
                'title' => $this->campaign?->title,
                'description' => $this->campaign?->description,
                'category' => $this->campaign?->category,
                'type' => $this->campaign?->type,
                'status' => $this->campaign?->status,
            ],
            'participants' => [
                'brand' => [
                    'id' => $this->brand_id,
                    'name' => $this->brand?->name,
                    'email' => $this->brand?->email,
                ],
                'creator' => [
                    'id' => $this->creator_id,
                    'name' => $this->creator?->name,
                    'email' => $this->creator?->email,
                ],
            ],
            'financial' => [
                'total_budget' => $totalBudget,
                'total_paid_to_creator' => $totalPaid,
                'offers_count' => $offers->count(),
                'contracts_count' => $contracts->count(),
                'completed_contracts' => $contracts->where('status', 'completed')->count(),
            ],
            'communication' => [
                'total_messages' => $totalMessages,
                'messages_by_brand' => $this->messages->where('sender_id', $this->brand_id)->count(),
                'messages_by_creator' => $this->messages->where('sender_id', $this->creator_id)->count(),
            ],
            'timeline' => $timeline,
            'duration_days' => $durationDays,
            'closure_reason' => $this->closure_reason,
        ];
    }

    /**
     * Obtém o relatório de processamento da campanha.
     * Útil para extração de dados após arquivamento.
     */
    public function getCampaignReport(): array
    {
        // Se já temos o resumo salvo, retorna ele
        if ($this->campaign_summary) {
            return array_merge($this->campaign_summary, [
                'generated_at' => $this->archived_at?->toISOString(),
                'is_cached' => true,
            ]);
        }

        // Gera novo resumo se não existir
        return array_merge($this->generateCampaignSummary(), [
            'generated_at' => now()->toISOString(),
            'is_cached' => false,
        ]);
    }

    // ==========================================
    // Métodos Estáticos
    // ==========================================

    public static function generateRoomId(int $campaignId, int $brandId, int $creatorId): string
    {
        return "room_{$campaignId}_{$brandId}_{$creatorId}";
    }

    /**
     * Encontra ou cria um chat room para uma campanha específica.
     * Cada campanha tem seu próprio chat - não reutiliza chats de outras campanhas.
     */
    public static function findOrCreateRoom(int $campaignId, int $brandId, int $creatorId): self
    {
        $roomId = self::generateRoomId($campaignId, $brandId, $creatorId);

        // Busca chat existente para ESTA campanha específica
        $existingRoom = self::where('campaign_id', $campaignId)
            ->where('brand_id', $brandId)
            ->where('creator_id', $creatorId)
            ->first();

        // Se existe e está arquivado, não reutiliza - retorna erro ou cria novo
        if ($existingRoom && $existingRoom->isArchived()) {
            Log::warning('Attempted to access archived chat room', [
                'room_id' => $existingRoom->room_id,
                'campaign_id' => $campaignId,
            ]);
            
            // Retorna o existente para visualização (read-only)
            return $existingRoom;
        }

        // Se existe e está ativo, retorna ele
        if ($existingRoom) {
            return $existingRoom;
        }

        // Cria novo chat para esta campanha
        return self::create([
            'campaign_id' => $campaignId,
            'brand_id' => $brandId,
            'creator_id' => $creatorId,
            'room_id' => $roomId,
            'is_active' => true,
            'chat_status' => self::STATUS_ACTIVE,
            'last_message_at' => now(),
        ]);
    }

    /**
     * Obtém o chat ativo para uma campanha.
     * Retorna null se o chat estiver arquivado.
     */
    public static function getActiveRoomForCampaign(int $campaignId, int $brandId, int $creatorId): ?self
    {
        return self::where('campaign_id', $campaignId)
            ->where('brand_id', $brandId)
            ->where('creator_id', $creatorId)
            ->notArchived()
            ->first();
    }

    /**
     * Obtém todos os chats arquivados de um brand ou creator.
     */
    public static function getArchivedRooms(int $userId, string $role = 'any', int $limit = 20): Collection
    {
        $query = self::archived()
            ->with(['campaign', 'brand', 'creator']);

        if ('brand' === $role) {
            $query->where('brand_id', $userId);
        } elseif ('creator' === $role) {
            $query->where('creator_id', $userId);
        } else {
            $query->where(function ($q) use ($userId) {
                $q->where('brand_id', $userId)
                    ->orWhere('creator_id', $userId);
            });
        }

        return $query->orderBy('archived_at', 'desc')
            ->limit($limit)
            ->get();
    }
}

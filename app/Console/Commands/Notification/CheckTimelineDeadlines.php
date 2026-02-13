<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Models\Campaign\CampaignTimeline;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\Message;
use App\Models\Common\Notification;
use App\Models\User\User;
use DB;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckTimelineDeadlines extends Command
{
    protected $signature = 'timeline:check-deadlines';

    protected $description = 'Check for overdue timeline milestones and apply penalties';

    public function handle(): void
    {
        $this->info('Checking timeline deadlines...');

        $overdueMilestones = CampaignTimeline::where('deadline', '<', now())
            ->where('status', '!=', 'completed')
            ->whereNull('delay_notified_at')
            ->with(['contract.creator', 'contract.brand', 'contract.offer'])
            ->get()
        ;

        $this->info("Found {$overdueMilestones->count()} overdue milestones");

        foreach ($overdueMilestones as $milestone) {
            $this->processOverdueMilestone($milestone);
        }

        $this->checkForSuspensions();

        $this->info('Timeline deadline check completed!');
    }

    private function processOverdueMilestone(CampaignTimeline $milestone): void
    {
        $contract = $milestone->contract;
        $creator = $contract->creator;
        $brand = $contract->brand;

        $milestone->markAsDelayed();

        $this->sendOverdueNotifications($milestone, $contract, $creator, $brand);

        $overdueCount = CampaignTimeline::whereHas('contract', function ($query) use ($creator): void {
            $query->where('creator_id', $creator->id);
        })
            ->where('deadline', '<', now())
            ->where('status', '!=', 'completed')
            ->count()
        ;

        if ($overdueCount >= 2) {
            $this->applySuspension($creator);
        }

        $this->info("Processed overdue milestone {$milestone->getKey()} for creator {$creator->name}");
    }

    private function sendOverdueNotifications($milestone, $contract, $creator, $brand): void
    {
        $creatorMessage = "⚠️ Milestone '{$milestone->title}' está atrasado.
        Prazo: " . $milestone->deadline->format('d/m/Y H:i') . "
        Contrato: {$contract->title}

        Se você não justificar o atraso, poderá receber uma penalidade de 7 dias sem novos convites.";

        Notification::create([
            'user_id' => $creator->getKey(),
            'title' => 'Milestone Atrasado',
            'message' => $creatorMessage,
            'type' => 'timeline_overdue',
            'data' => [
                'milestone_id' => $milestone->id,
                'contract_id' => $contract->id,
                'deadline' => $milestone->deadline->toISOString(),
            ],
        ]);

        $brandMessage = "⚠️ Milestone '{$milestone->title}' está atrasado.
        Criador: {$creator->name}
        Prazo: " . $milestone->deadline->format('d/m/Y H:i') . "
        Contrato: {$contract->title}

        Você pode justificar o atraso para evitar penalidades ao criador.";

        Notification::create([
            'user_id' => $brand->getKey(),
            'title' => 'Milestone Atrasado',
            'message' => $brandMessage,
            'type' => 'timeline_overdue',
            'data' => [
                'milestone_id' => $milestone->getKey(),
                'contract_id' => $contract->getKey(),
                'deadline' => $milestone->deadline->toISOString(),
            ],
        ]);

        $this->sendChatMessages($milestone, $contract, $brand);
    }

    private function sendChatMessages($milestone, $contract, $brand): void
    {
        try {
            $chatRoom = ChatRoom::whereHas('offers', function ($query) use ($contract): void {
                $query->where('id', $contract->offer_id);
            })->first();

            if ($chatRoom) {
                Message::create([
                    'chat_room_id' => $chatRoom->getKey(),
                    'sender_id' => $brand->getKey(),
                    'message' => "⚠️ Milestone '{$milestone->title}' está atrasado desde "
                        . $milestone->deadline->format('d/m/Y H:i') . '.
                                Prazo para justificativa: 24 horas.',
                    'message_type' => 'system',
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to send overdue milestone chat message', [
                'milestone_id' => $milestone->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function applySuspension(User $creator): void
    {
        if ($creator?->suspended_until?->isFuture()) {
            return;
        }

        $suspensionEnd = now()->addDays(7);

        $creator->update([
            'suspended_until' => $suspensionEnd,
            'suspension_reason' => 'Multiple overdue timeline milestones',
        ]);

        Notification::create([
            'user_id' => $creator->getKey(),
            'title' => 'Conta Suspensa',
            'message' => 'Sua conta foi suspensa por 7 dias devido a múltiplos milestones atrasados.
            Suspensão até: ' . $suspensionEnd->format('d/m/Y H:i'),
            'type' => 'account_suspended',
            'data' => [
                'suspended_until' => $suspensionEnd->toISOString(),
                'reason' => 'Multiple overdue timeline milestones',
            ],
        ]);

        $this->warn("Applied 7-day suspension to creator {$creator->name}");
    }

    private function checkForSuspensions(): void
    {
        $creatorsWithOverdue = DB::table('campaign_timelines')
            ->join('contracts', 'campaign_timelines.contract_id', '=', 'contracts.id')
            ->join('users', 'contracts.creator_id', '=', 'users.id')
            ->where('campaign_timelines.deadline', '<', now())
            ->where('campaign_timelines.status', '!=', 'completed')
            ->where('users.role', 'creator')
            ->select('users.id', 'users.name', DB::raw('COUNT(*) as overdue_count'))
            ->groupBy('users.id', 'users.name')
            ->havingRaw('COUNT(*) >= ?', [2])
            ->get()
        ;

        foreach ($creatorsWithOverdue as $creatorData) {
            $creator = User::find($creatorData->id);

            if ($creator && (!$creator->suspended_until || $creator->suspended_until->isPast())) {
                $this->applySuspension($creator);
            }
        }
    }
}

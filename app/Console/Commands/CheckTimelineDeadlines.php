<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CampaignTimeline;
use App\Models\Contract;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;

class CheckTimelineDeadlines extends Command
{
    
    protected $signature = 'timeline:check-deadlines';

    
    protected $description = 'Check for overdue timeline milestones and apply penalties';

    
    public function handle()
    {
        $this->info('Checking timeline deadlines...');

        
        $overdueMilestones = CampaignTimeline::where('deadline', '<', now())
            ->where('status', '!=', 'completed')
            ->whereNull('delay_notified_at')
            ->with(['contract.creator', 'contract.brand', 'contract.offer'])
            ->get();

        $this->info("Found {$overdueMilestones->count()} overdue milestones");

        foreach ($overdueMilestones as $milestone) {
            $this->processOverdueMilestone($milestone);
        }

        
        $this->checkForSuspensions();

        $this->info('Timeline deadline check completed!');
    }

    
    private function processOverdueMilestone(CampaignTimeline $milestone)
    {
        $contract = $milestone->contract;
        $creator = $contract->creator;
        $brand = $contract->brand;

        
        $milestone->markAsDelayed();

        
        $this->sendOverdueNotifications($milestone, $contract, $creator, $brand);

        
        $overdueCount = CampaignTimeline::whereHas('contract', function ($query) use ($creator) {
            $query->where('creator_id', $creator->id);
        })
        ->where('deadline', '<', now())
        ->where('status', '!=', 'completed')
        ->count();

        if ($overdueCount >= 2) {
            $this->applySuspension($creator);
        }

        $this->info("Processed overdue milestone {$milestone->id} for creator {$creator->name}");
    }

    
    private function sendOverdueNotifications($milestone, $contract, $creator, $brand)
    {
        
        $creatorMessage = "⚠️ Milestone '{$milestone->title}' está atrasado. 
        Prazo: " . $milestone->deadline->format('d/m/Y H:i') . "
        Contrato: {$contract->title}
        
        Se você não justificar o atraso, poderá receber uma penalidade de 7 dias sem novos convites.";

        
        \App\Models\Notification::create([
            'user_id' => $creator->id,
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

        
        \App\Models\Notification::create([
            'user_id' => $brand->id,
            'title' => 'Milestone Atrasado',
            'message' => $brandMessage,
            'type' => 'timeline_overdue',
            'data' => [
                'milestone_id' => $milestone->id,
                'contract_id' => $contract->id,
                'deadline' => $milestone->deadline->toISOString(),
            ],
        ]);

        
        $this->sendChatMessages($milestone, $contract, $creator, $brand);
    }

    
    private function sendChatMessages($milestone, $contract, $creator, $brand)
    {
        try {
            
            $chatRoom = \App\Models\ChatRoom::whereHas('offers', function ($query) use ($contract) {
                $query->where('id', $contract->offer_id);
            })->first();

            if ($chatRoom) {
                
                \App\Models\Message::create([
                    'chat_room_id' => $chatRoom->id,
                    'sender_id' => $brand->id,
                    'message' => "⚠️ Milestone '{$milestone->title}' está atrasado desde " . 
                                $milestone->deadline->format('d/m/Y H:i') . ". 
                                Prazo para justificativa: 24 horas.",
                    'message_type' => 'system',
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send overdue milestone chat message', [
                'milestone_id' => $milestone->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    private function applySuspension(User $creator)
    {
        
        if ($creator->suspended_until && $creator->suspended_until->isFuture()) {
            return;
        }

        
        $suspensionEnd = now()->addDays(7);
        
        $creator->update([
            'suspended_until' => $suspensionEnd,
            'suspension_reason' => 'Multiple overdue timeline milestones',
        ]);

        
        \App\Models\Notification::create([
            'user_id' => $creator->id,
            'title' => 'Conta Suspensa',
            'message' => "Sua conta foi suspensa por 7 dias devido a múltiplos milestones atrasados. 
            Suspensão até: " . $suspensionEnd->format('d/m/Y H:i'),
            'type' => 'account_suspended',
            'data' => [
                'suspended_until' => $suspensionEnd->toISOString(),
                'reason' => 'Multiple overdue timeline milestones',
            ],
        ]);

        $this->warn("Applied 7-day suspension to creator {$creator->name}");
    }

    
    private function checkForSuspensions()
    {
        
        $creatorsWithOverdue = \DB::table('campaign_timelines')
            ->join('contracts', 'campaign_timelines.contract_id', '=', 'contracts.id')
            ->join('users', 'contracts.creator_id', '=', 'users.id')
            ->where('campaign_timelines.deadline', '<', now())
            ->where('campaign_timelines.status', '!=', 'completed')
            ->where('users.role', 'creator')
            ->select('users.id', 'users.name', \DB::raw('COUNT(*) as overdue_count'))
            ->groupBy('users.id', 'users.name')
            ->havingRaw('COUNT(*) >= ?', [2])
            ->get();

        foreach ($creatorsWithOverdue as $creatorData) {
            $creator = User::find($creatorData->id);
            
            if ($creator && (!$creator->suspended_until || $creator->suspended_until->isPast())) {
                $this->applySuspension($creator);
            }
        }
    }
} 
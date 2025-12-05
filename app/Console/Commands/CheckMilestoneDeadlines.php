<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CampaignTimeline;
use App\Models\Contract;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CheckMilestoneDeadlines extends Command
{
    
    protected $signature = 'milestones:check-deadlines';

    
    protected $description = 'Check milestone deadlines and send automatic delay warnings';

    
    public function handle()
    {
        $this->info('Checking milestone deadlines...');

        try {
            
            $overdueMilestones = CampaignTimeline::where('deadline', '<', now())
                ->where('status', 'pending')
                ->where('is_delayed', false)
                ->whereNull('delay_notified_at')
                ->with(['contract.creator', 'contract.brand'])
                ->get();

            if ($overdueMilestones->isEmpty()) {
                $this->info('No overdue milestones found.');
                return 0;
            }

            $this->info("Found {$overdueMilestones->count()} overdue milestones.");

            $warningsSent = 0;
            $penaltiesApplied = 0;

            foreach ($overdueMilestones as $milestone) {
                try {
                    $this->info("Processing milestone: {$milestone->title} (ID: {$milestone->id})");

                    
                    NotificationService::notifyCreatorOfMilestoneDelay($milestone);
                    
                    
                    $milestone->update([
                        'delay_notified_at' => now(),
                        'is_delayed' => true,
                    ]);

                    $warningsSent++;
                    $this->info("✓ Warning sent for milestone: {$milestone->title}");

                    
                    $this->checkAndApplyPenalties($milestone);

                } catch (\Exception $e) {
                    $this->error("Failed to process milestone {$milestone->id}: {$e->getMessage()}");
                    Log::error('Failed to process milestone in command', [
                        'milestone_id' => $milestone->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->info("✓ Processed {$overdueMilestones->count()} milestones");
            $this->info("✓ Warnings sent: {$warningsSent}");
            $this->info("✓ Penalties applied: {$penaltiesApplied}");

            return 0;

        } catch (\Exception $e) {
            $this->error("Command failed: {$e->getMessage()}");
            Log::error('CheckMilestoneDeadlines command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    
    private function checkAndApplyPenalties(CampaignTimeline $milestone)
    {
        try {
            
            $daysOverdue = $milestone->getDaysOverdue();
            
            if ($daysOverdue >= 7 && !$milestone->penalty_applied) {
                $this->info("Applying penalty for milestone: {$milestone->title} (overdue for {$daysOverdue} days)");
                
                
                $creator = $milestone->contract->creator;
                
                
                $creator->update([
                    'penalty_until' => now()->addDays(7),
                    'penalty_reason' => 'Milestone overdue for more than 7 days',
                    'penalty_milestone_id' => $milestone->id,
                ]);

                
                $milestone->update([
                    'penalty_applied' => true,
                    'penalty_applied_at' => now(),
                ]);

                
                $this->sendPenaltyNotification($milestone, $creator);
                
                $this->info("✓ Penalty applied to creator: {$creator->name}");
            }

        } catch (\Exception $e) {
            $this->error("Failed to apply penalty for milestone {$milestone->id}: {$e->getMessage()}");
            Log::error('Failed to apply penalty', [
                'milestone_id' => $milestone->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    private function sendPenaltyNotification(CampaignTimeline $milestone, User $creator)
    {
        try {
            
            \App\Models\Notification::create([
                'user_id' => $creator->id,
                'type' => 'milestone_penalty',
                'title' => '🚫 Penalidade Aplicada - 7 Dias Sem Convites',
                'message' => "Devido ao atraso no milestone '{$milestone->title}', você recebeu uma penalidade de 7 dias sem novos convites para campanhas.",
                'data' => [
                    'milestone_id' => $milestone->id,
                    'contract_id' => $milestone->contract_id,
                    'contract_title' => $milestone->contract->title,
                    'penalty_duration' => 7,
                    'penalty_until' => now()->addDays(7)->toISOString(),
                    'penalty_reason' => 'Milestone overdue for more than 7 days',
                ],
                'read_at' => null,
            ]);

            
            try {
                
                
            } catch (\Exception $emailError) {
                Log::error('Failed to send penalty email', [
                    'creator_id' => $creator->id,
                    'error' => $emailError->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to send penalty notification', [
                'creator_id' => $creator->id,
                'milestone_id' => $milestone->id,
                'error' => $e->getMessage()
            ]);
        }
    }
} 
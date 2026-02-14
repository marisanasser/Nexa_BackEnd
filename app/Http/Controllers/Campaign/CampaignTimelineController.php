<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaign;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Base\Controller;
use App\Models\Campaign\CampaignTimeline;
use App\Models\Contract\Contract;
use App\Domain\Notification\Services\ContractNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CampaignTimelineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => 'required|exists:contracts,id',
        ]);

        $contract = Contract::findOrFail($request->contract_id);

        if ('brand' === Auth::user()->role && $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ('creator' === Auth::user()->role && $contract->creator_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $timeline = $contract->timeline()->with(['deliveryMaterials.creator', 'contract'])->orderBy('deadline')->get();

        return response()->json([
            'success' => true,
            'data' => $timeline,
        ]);
    }

    public function createMilestones(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => 'required|exists:contracts,id',
        ]);

        $contract = Contract::with('offer')->findOrFail($request->contract_id);

        if ('brand' !== Auth::user()->role || $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if ($contract->timeline()->exists()) {
            return response()->json(['error' => 'Timeline já existe para este contrato'], 400);
        }

        $startDate = $contract->offer?->created_at ?? $contract->created_at ?? now();
        $totalDays = $contract->estimated_days ?? 7;

        $milestones = [
            [
                'milestone_type' => 'script_submission',
                'title' => 'Envio do Roteiro',
                'description' => 'Enviar o roteiro inicial para revisão',
                'deadline' => $startDate->copy()->addDays(ceil($totalDays * 0.25)),
            ],
            [
                'milestone_type' => 'script_approval',
                'title' => 'Aprovação do Roteiro',
                'description' => 'Aprovar o roteiro enviado',
                'deadline' => $startDate->copy()->addDays(ceil($totalDays * 0.35)),
            ],
            [
                'milestone_type' => 'video_submission',
                'title' => 'Envio de Imagem e Vídeo',
                'description' => 'Enviar o conteúdo final de imagem e vídeo',
                'deadline' => $startDate->copy()->addDays(ceil($totalDays * 0.85)),
            ],
            [
                'milestone_type' => 'final_approval',
                'title' => 'Aprovação Final',
                'description' => 'Aprovar o vídeo final',
                'deadline' => $startDate->copy()->addDays($totalDays),
            ],
        ];

        try {
            $createdMilestones = [];
            foreach ($milestones as $milestone) {
                $createdMilestones[] = $contract->timeline()->create($milestone);
            }

            return response()->json([
                'success' => true,
                'data' => $createdMilestones,
                'message' => 'Milestones da timeline criados com sucesso',
            ]);
        } catch (Exception $e) {
            Log::error('Failed to create timeline milestones', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Falha ao criar milestones da timeline: '.$e->getMessage(),
            ], 500);
        }
    }

    public function uploadFile(Request $request): JsonResponse
    {
        $request->validate([
            'milestone_id' => 'required|exists:campaign_timelines,id',
            'file' => 'required|file|max:102400',
        ]);

        $milestone = CampaignTimeline::findOrFail($request->milestone_id);
        $contract = $milestone->contract;

        if ('brand' === Auth::user()->role && $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if ('creator' === Auth::user()->role && $contract->creator_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if ('creator' !== Auth::user()->role && in_array($milestone->milestone_type, ['script_submission', 'video_submission'])) {
            return response()->json(['error' => 'Apenas o criador pode enviar arquivos para milestones de submissão'], 403);
        }

        if (!$milestone->canUploadFile()) {
            return response()->json(['error' => 'Não é possível enviar arquivo para este milestone'], 400);
        }

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $fileSize = $file->getSize();
        $fileType = $file->getMimeType();

        $filePath = $file->store('timeline-files', 'public');

        $milestone->uploadFile($filePath, $fileName, $fileSize, $fileType);

        return response()->json([
            'success' => true,
            'data' => $milestone->fresh(),
            'message' => 'Arquivo enviado com sucesso',
        ]);
    }

    public function approveMilestone(Request $request): JsonResponse
    {
        $request->validate([
            'milestone_id' => 'required|exists:campaign_timelines,id',
            'comment' => 'nullable|string|max:500',
        ]);

        $milestone = CampaignTimeline::findOrFail($request->milestone_id);
        $contract = $milestone->contract;

        if ('brand' !== Auth::user()->role || $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if (!$milestone->canBeApproved()) {
            return response()->json(['error' => 'Não é possível aprovar este milestone'], 400);
        }

        try {
            $milestone->markAsApproved($request->comment);

            try {
                ContractNotificationService::notifyCreatorOfMilestoneApproval($milestone);
            } catch (Exception $notificationError) {
                Log::error('Failed to send milestone approval notification', [
                    'milestone_id' => $milestone->id,
                    'error' => $notificationError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $milestone->fresh(),
                'message' => 'Milestone aprovado com sucesso',
            ]);
        } catch (Exception $e) {
            Log::error('Failed to approve milestone', [
                'milestone_id' => $milestone->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao aprovar milestone',
            ], 500);
        }
    }

    public function completeMilestone(Request $request): JsonResponse
    {
        $request->validate([
            'milestone_id' => 'required|exists:campaign_timelines,id',
        ]);

        $milestone = CampaignTimeline::findOrFail($request->milestone_id);
        $contract = $milestone->contract;

        if ('brand' === Auth::user()->role && $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if ('creator' === Auth::user()->role && $contract->creator_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if (!$milestone->canBeCompleted()) {
            return response()->json(['error' => 'Não é possível concluir este milestone'], 400);
        }

        $milestone->markAsCompleted();

        return response()->json([
            'success' => true,
            'data' => $milestone->fresh(),
            'message' => 'Milestone concluído com sucesso',
        ]);
    }

    public function justifyDelay(Request $request): JsonResponse
    {
        $request->validate([
            'milestone_id' => 'required|exists:campaign_timelines,id',
            'justification' => 'required|string|max:1000',
        ]);

        $milestone = CampaignTimeline::findOrFail($request->milestone_id);
        $contract = $milestone->contract;

        if ('brand' !== Auth::user()->role || $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if (!$milestone->canJustifyDelay()) {
            return response()->json(['error' => 'Não é possível justificar atraso para este milestone'], 400);
        }

        $milestone->justifyDelay($request->justification);

        return response()->json([
            'success' => true,
            'data' => $milestone->fresh(),
            'message' => 'Atraso justificado com sucesso',
        ]);
    }

    public function markAsDelayed(Request $request): JsonResponse
    {
        $request->validate([
            'milestone_id' => 'required|exists:campaign_timelines,id',
            'justification' => 'nullable|string|max:1000',
        ]);

        $milestone = CampaignTimeline::findOrFail($request->milestone_id);
        $contract = $milestone->contract;

        if ('brand' === Auth::user()->role && $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if ('creator' === Auth::user()->role && $contract->creator_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        $milestone->markAsDelayed($request->justification);

        return response()->json([
            'success' => true,
            'data' => $milestone->fresh(),
            'message' => 'Milestone marcado como atrasado',
        ]);
    }

    public function downloadFile(Request $request): JsonResponse
    {
        $request->validate([
            'milestone_id' => 'required|exists:campaign_timelines,id',
        ]);

        $milestone = CampaignTimeline::findOrFail($request->milestone_id);
        $contract = $milestone->contract;

        if ('brand' === Auth::user()->role && $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if ('creator' === Auth::user()->role && $contract->creator_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if (!$milestone->file_path) {
            return response()->json(['error' => 'Nenhum arquivo disponível para download'], 404);
        }

        $filePath = storage_path('app/public/'.$milestone->file_path);

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'Arquivo não encontrado'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'download_url' => Storage::url($milestone->file_path),
                'file_name' => $milestone->file_name,
                'file_size' => $milestone->file_size,
                'file_type' => $milestone->file_type,
            ],
        ]);
    }

    public function extendTimeline(Request $request): JsonResponse
    {
        $request->validate([
            'milestone_id' => 'required|exists:campaign_timelines,id',
            'extension_days' => 'required|integer|min:1|max:365',
            'extension_reason' => 'required|string|max:1000',
        ]);

        $milestone = CampaignTimeline::findOrFail($request->milestone_id);
        $contract = $milestone->contract;

        if ('brand' !== Auth::user()->role || $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $milestone->extendTimeline(
            $request->extension_days,
            $request->extension_reason,
            Auth::id()
        );

        return response()->json([
            'success' => true,
            'message' => 'Timeline estendida com sucesso',
        ]);
    }

    public function getStatistics(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => 'required|exists:contracts,id',
        ]);

        $contract = Contract::findOrFail($request->contract_id);

        if ('brand' === Auth::user()->role && $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if ('creator' === Auth::user()->role && $contract->creator_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        $timeline = $contract->timeline()->with('deliveryMaterials.creator')->get();

        $statistics = [
            'total_milestones' => $timeline->count(),
            'completed_milestones' => $timeline->where('status', 'completed')->count(),
            'pending_milestones' => $timeline->where('status', 'pending')->count(),
            'approved_milestones' => $timeline->where('status', 'approved')->count(),
            'delayed_milestones' => $timeline->where('is_delayed', true)->count(),
            'overdue_milestones' => $timeline->filter(fn ($milestone) => $milestone->isOverdue())->count(),
            'progress_percentage' => $timeline->count() > 0
                ? round(($timeline->filter(fn ($milestone) => in_array($milestone->status, ['approved', 'completed'], true))->count() / $timeline->count()) * 100) : 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $statistics,
        ]);
    }

    public function rejectMilestone(Request $request): JsonResponse
    {
        $request->validate([
            'milestone_id' => 'required|exists:campaign_timelines,id',
            'comment' => 'nullable|string|max:500',
        ]);

        $milestone = CampaignTimeline::findOrFail($request->milestone_id);
        $contract = $milestone->contract;

        if ('brand' !== Auth::user()->role || $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if (!$milestone->canBeRejected()) {
            return response()->json(['error' => 'Não é possível rejeitar este milestone'], 400);
        }

        try {
            $milestone->markAsRejected($request->comment);

            try {
                ContractNotificationService::notifyCreatorOfMilestoneRejection($milestone);
            } catch (Exception $notificationError) {
                Log::error('Failed to send milestone rejection notification', [
                    'milestone_id' => $milestone->id,
                    'error' => $notificationError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $milestone->fresh(),
                'message' => 'Milestone rejeitado com sucesso',
            ]);
        } catch (Exception $e) {
            Log::error('Failed to reject milestone', [
                'milestone_id' => $milestone->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao rejeitar milestone',
            ], 500);
        }
    }

    public function checkAndSendDelayWarnings(): JsonResponse
    {
        try {
            $overdueMilestones = CampaignTimeline::where('deadline', '<', now())
                ->where('status', 'pending')
                ->where('is_delayed', false)
                ->whereNull('delay_notified_at')
                ->with(['contract.creator', 'contract.brand'])
                ->get()
            ;

            $warningsSent = 0;
            foreach ($overdueMilestones as $milestone) {
                try {
                    ContractNotificationService::notifyCreatorOfMilestoneDelay($milestone);

                    $milestone->update([
                        'delay_notified_at' => now(),
                        'is_delayed' => true,
                    ]);

                    ++$warningsSent;
                } catch (Exception $e) {
                    Log::error('Failed to send delay warning for milestone', [
                        'milestone_id' => $milestone->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Delay warnings sent for {$warningsSent} milestones",
                'warnings_sent' => $warningsSent,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to check and send delay warnings', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check and send delay warnings',
            ], 500);
        }
    }
}

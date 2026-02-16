<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaign;

use App\Domain\Notification\Services\ContractNotificationService;
use App\Events\Chat\NewMessage;
use App\Events\Contract\ContractCompleted;
use App\Events\Contract\ContractUpdated;
use Exception;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Base\Controller;
use App\Helpers\FileUploadHelper;
use App\Models\Campaign\CampaignTimeline;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\Message;
use App\Models\Contract\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class CampaignTimelineController extends Controller
{
    public function index(Request $request): JsonResponse
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

        $timeline = $contract->timeline()->with(['deliveryMaterials.creator', 'contract'])->orderBy('deadline')->get();

        return response()->json([
            'success' => true,
            'data' => $timeline,
        ]);
    }

    public function completeContract(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => 'required|exists:contracts,id',
        ]);

        $contract = Contract::findOrFail($request->contract_id);

        if ('brand' !== Auth::user()->role || $contract->brand_id !== Auth::id()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        if (! $contract->canBeCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'Contrato não pode ser finalizado no estado atual.',
            ], 400);
        }

        try {
            $completed = $contract->complete();
            if (! $completed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato não pôde ser finalizado.',
                ], 400);
            }

            $contract = $contract->fresh();

            try {
                event(new ContractCompleted($contract, $contract->offer?->chatRoom, (int) Auth::id()));
            } catch (\Throwable $broadcastException) {
                Log::error('Failed to broadcast ContractCompleted event from timeline', [
                    'contract_id' => $contract->id,
                    'user_id' => Auth::id(),
                    'error' => $broadcastException->getMessage(),
                    'exception' => get_class($broadcastException),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Contrato finalizado com sucesso',
                'data' => $contract,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to complete contract from timeline', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao finalizar contrato: ' . $e->getMessage(),
            ], 500);
        }
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
                'milestone_type' => 'video_submission',
                'title' => 'Envio de Imagem e Vídeo',
                'description' => 'Enviar o conteúdo final de imagem e vídeo',
                'deadline' => $startDate->copy()->addDays(ceil($totalDays * 0.85)),
            ],
        ];

        try {
            $createdMilestones = [];
            foreach ($milestones as $milestone) {
                $createdMilestones[] = $contract->timeline()->create($milestone);
            }

            $this->broadcastContractUpdate($contract, 'milestones_created');

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
                'error' => 'Falha ao criar milestones da timeline: ' . $e->getMessage(),
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
            return response()->json(['error' => $milestone->getUploadBlockerReason() ?? 'Não é possível enviar arquivo para este milestone'], 400);
        }

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $fileSize = $file->getSize();
        $fileType = $file->getMimeType();

        try {
            $filePath = FileUploadHelper::upload($file, 'timeline-files');
        } catch (Exception $e) {
            Log::error('Timeline file upload exception', [
                'milestone_id' => $milestone->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Falha ao salvar arquivo no armazenamento',
            ], 500);
        }

        if (!is_string($filePath) || '' === trim($filePath)) {
            Log::error('Timeline file upload failed to return a valid path', [
                'milestone_id' => $milestone->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Falha ao salvar arquivo no armazenamento',
            ], 500);
        }

        $milestone->uploadFile($filePath, $fileName, $fileSize, $fileType);
        $milestone->refresh();
        $this->handleCreatorMilestoneSubmission($contract, $milestone);
        $this->broadcastContractUpdate($contract, 'milestone_file_uploaded', $milestone);

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

            $milestone->refresh();
            $this->broadcastContractUpdate($contract, 'milestone_approved', $milestone);

            return response()->json([
                'success' => true,
                'data' => $milestone->fresh(),
                'message' => 'Milestone aprovado com sucesso',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to approve milestone', [
                'milestone_id' => $milestone->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
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
        $milestone->refresh();
        $this->broadcastContractUpdate($contract, 'milestone_completed', $milestone);

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
        $milestone->refresh();
        $this->broadcastContractUpdate($contract, 'milestone_delay_justified', $milestone);

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
        $milestone->refresh();
        $this->broadcastContractUpdate($contract, 'milestone_marked_delayed', $milestone);

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

        [$disk, $resolvedPath] = $this->resolveFileDiskAndPath($milestone->file_path);
        if (!$disk || !$resolvedPath) {
            Log::warning('Timeline file not found for signed download', [
                'milestone_id' => $milestone->id,
                'file_path' => $milestone->file_path,
                'default_disk' => $this->getStorageDiskName(),
            ]);
            return response()->json(['error' => 'Arquivo não encontrado'], 404);
        }

        // Return the API download URL via signed route to bypass symlink/auth issues
        $downloadUrl = URL::temporarySignedRoute(
            'api.campaign-timeline.download-signed',
            now()->addMinutes(60),
            ['milestone' => $milestone->id]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'download_url' => $downloadUrl,
                'file_name' => $milestone->file_name,
                'file_size' => $milestone->file_size,
                'file_type' => $milestone->file_type,
            ],
        ]);
    }

    public function streamFileSigned(Request $request, $milestone_id)
    {
        // No auth check needed here as the route is signed and temporary
        // The signature ensures the URL was generated by the backend (which checks auth)

        $milestone = CampaignTimeline::findOrFail($milestone_id);
        [$disk, $resolvedPath] = $this->resolveFileDiskAndPath($milestone->file_path);

        if (!$disk || !$resolvedPath) {
            abort(404, 'Arquivo não encontrado');
        }

        $downloadName = $milestone->file_name
            ? basename($milestone->file_name)
            : basename((string) $milestone->file_path);

        return response()->streamDownload(function () use ($disk, $resolvedPath): void {
            $stream = $disk->readStream($resolvedPath);
            if (false === $stream) {
                throw new \RuntimeException('Falha ao abrir stream do arquivo');
            }

            try {
                fpassthru($stream);
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, $downloadName, [
            'Content-Type' => $milestone->file_type ?: 'application/octet-stream',
        ]);
    }

    private function getStorageDiskName(): string
    {
        $disk = config('filesystems.default', 'public');

        return is_string($disk) && '' !== trim($disk) ? $disk : 'public';
    }

    /**
     * @return array{0: \Illuminate\Contracts\Filesystem\Filesystem|\Illuminate\Filesystem\FilesystemAdapter|null, 1: string|null}
     */
    private function resolveFileDiskAndPath(?string $storedPath): array
    {
        if (!$storedPath) {
            return [null, null];
        }

        $normalizedPath = $this->normalizeStoredFilePath($storedPath);
        if ('' === $normalizedPath) {
            return [null, null];
        }

        $defaultDiskName = $this->getStorageDiskName();
        $defaultDisk = Storage::disk($defaultDiskName);

        if ($defaultDisk->exists($normalizedPath)) {
            return [$defaultDisk, $normalizedPath];
        }

        if ('public' !== $defaultDiskName) {
            $publicDisk = Storage::disk('public');
            if ($publicDisk->exists($normalizedPath)) {
                return [$publicDisk, $normalizedPath];
            }
        }

        return [null, $normalizedPath];
    }

    private function normalizeStoredFilePath(string $path): string
    {
        $cleanPath = trim($path);
        if ('' === $cleanPath) {
            return '';
        }

        if (str_starts_with($cleanPath, 'http://') || str_starts_with($cleanPath, 'https://')) {
            $parsedPath = parse_url($cleanPath, PHP_URL_PATH);
            $cleanPath = is_string($parsedPath) ? $parsedPath : $cleanPath;
        }

        $cleanPath = ltrim(rawurldecode($cleanPath), '/');

        $bucket = env('GOOGLE_CLOUD_STORAGE_BUCKET');
        if (is_string($bucket) && '' !== $bucket && str_starts_with($cleanPath, $bucket . '/')) {
            $cleanPath = substr($cleanPath, strlen($bucket) + 1);
        }

        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr($cleanPath, 8);
        }

        if (str_starts_with($cleanPath, 'public/')) {
            $cleanPath = substr($cleanPath, 7);
        }

        return ltrim($cleanPath, '/');
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
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        $milestone->extendTimeline(
            $request->extension_days,
            $request->extension_reason,
            Auth::id()
        );
        $milestone->refresh();
        $this->broadcastContractUpdate($contract, 'milestone_extended', $milestone);

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
            'overdue_milestones' => $timeline->filter(fn($milestone) => $milestone->isOverdue())->count(),
            'progress_percentage' => $timeline->count() > 0
                ? round(($timeline->filter(fn($milestone) => in_array($milestone->status, ['approved', 'completed'], true))->count() / $timeline->count()) * 100) : 0,
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

            $milestone->refresh();
            $this->broadcastContractUpdate($contract, 'milestone_rejected', $milestone);

            return response()->json([
                'success' => true,
                'data' => $milestone->fresh(),
                'message' => 'Milestone rejeitado com sucesso',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to reject milestone', [
                'milestone_id' => $milestone->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao rejeitar milestone',
            ], 500);
        }
    }

    private function handleCreatorMilestoneSubmission(Contract $contract, CampaignTimeline $milestone): void
    {
        if (
            'creator' !== Auth::user()->role
            || !in_array($milestone->milestone_type, ['script_submission', 'video_submission'], true)
        ) {
            return;
        }

        try {
            ContractNotificationService::notifyBrandOfMilestoneSubmission($milestone);
        } catch (Exception $notificationError) {
            Log::error('Failed to send milestone submission notification to brand', [
                'milestone_id' => $milestone->id,
                'contract_id' => $contract->id,
                'error' => $notificationError->getMessage(),
            ]);
        }

        $this->sendMilestoneSubmissionSystemMessage($contract, $milestone);
    }

    private function sendMilestoneSubmissionSystemMessage(Contract $contract, CampaignTimeline $milestone): void
    {
        try {
            $contract->loadMissing('offer.chatRoom');
            $chatRoom = $contract->offer?->chatRoom;

            if (!$chatRoom instanceof ChatRoom) {
                Log::warning('No chat room found when sending milestone submission system message', [
                    'contract_id' => $contract->id,
                    'milestone_id' => $milestone->id,
                ]);

                return;
            }

            $messageText = match ($milestone->milestone_type) {
                'script_submission' => 'Voce recebeu o roteiro da campanha para avaliar.',
                'video_submission' => 'Voce recebeu uma gravacao/conteudo da campanha para avaliar.',
                default => "Voce recebeu um novo envio para avaliar: {$milestone->title}.",
            };

            $payload = [
                'message_type' => 'milestone_submission',
                'contract_id' => $contract->id,
                'milestone_id' => $milestone->id,
                'milestone_type' => $milestone->milestone_type,
                'milestone_title' => $milestone->title,
                'file_name' => $milestone->file_name,
                'submitted_by' => 'creator',
                'submitted_at' => now()->toISOString(),
            ];

            $systemMessage = Message::create([
                'chat_room_id' => $chatRoom->id,
                'sender_id' => null,
                'message' => $messageText,
                'message_type' => 'system',
                'offer_data' => $payload,
                'is_system_message' => true,
            ]);

            $chatRoom->update(['last_message_at' => now()]);

            event(new NewMessage($systemMessage, $chatRoom, $payload));
        } catch (\Throwable $e) {
            Log::error('Failed to send milestone submission system message', [
                'contract_id' => $contract->id,
                'milestone_id' => $milestone->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }

    private function broadcastContractUpdate(
        Contract $contract,
        string $updateType,
        ?CampaignTimeline $milestone = null
    ): void {
        try {
            $contract->loadMissing('offer.chatRoom');

            event(new ContractUpdated(
                $contract->fresh(),
                $contract->offer?->chatRoom,
                (int) Auth::id(),
                [
                    'update_type' => $updateType,
                    'milestone_id' => $milestone?->id,
                    'milestone_type' => $milestone?->milestone_type,
                    'milestone_status' => $milestone?->status,
                ]
            ));
        } catch (\Throwable $broadcastException) {
            Log::error('Failed to broadcast ContractUpdated event from timeline action', [
                'contract_id' => $contract->id,
                'update_type' => $updateType,
                'milestone_id' => $milestone?->id,
                'user_id' => Auth::id(),
                'error' => $broadcastException->getMessage(),
                'exception' => get_class($broadcastException),
            ]);
        }
    }
}

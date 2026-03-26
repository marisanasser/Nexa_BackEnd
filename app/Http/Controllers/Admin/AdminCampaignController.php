<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\Services\CampaignAuditService;
use App\Domain\Notification\Services\AdminNotificationService;
use App\Domain\Notification\Services\CampaignNotificationService;
use Exception;
use Illuminate\Support\Facades\Log;

use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignTextSuggestion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * AdminCampaignController handles admin campaign management operations.
 *
 * Extracted from the monolithic AdminController for better separation of concerns.
 */
class AdminCampaignController extends Controller
{
    use HasAuthenticatedUser;

    /**
     * Get paginated list of campaigns with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:pending,approved,rejected,active,inactive',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $query = Campaign::with(['brand', 'applications', 'openTextSuggestion']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                ;
            });
        }

        $campaigns = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page)
        ;

        $transformedCampaigns = collect($campaigns->items())->map($this->transformCampaignData(...));

        return response()->json([
            'success' => true,
            'data' => $transformedCampaigns,
            'pagination' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    /**
     * Get single campaign details.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $campaign = Campaign::with(['brand', 'applications.creator', 'openTextSuggestion.admin'])
                ->findOrFail($id)
            ;

            $data = [
                'id' => $campaign->id,
                'title' => $campaign->title,
                'description' => $campaign->description,
                'budget' => $campaign->budget,
                'status' => $campaign->status,
                'is_active' => $campaign->is_active,
                'created_at' => $campaign->created_at->format('Y-m-d H:i:s'),
                'brand' => [
                    'id' => $campaign->brand->id,
                    'name' => $campaign->brand->name,
                    'company_name' => $campaign->brand->company_name,
                    'email' => $campaign->brand->email,
                ],
                'applications' => $campaign->applications->map(fn ($application) => [
                    'id' => $application->id,
                    'status' => $application->status,
                    'proposal' => $application->proposal,
                    'created_at' => $application->created_at->format('Y-m-d H:i:s'),
                    'creator' => [
                        'id' => $application->creator->id,
                        'name' => $application->creator->name,
                        'email' => $application->creator->email,
                    ],
                ]),
                'text_suggestion' => $this->transformTextSuggestionData($campaign->openTextSuggestion),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }
    }

    /**
     * Update a campaign.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        Log::info('Update campaign request:', ['request' => $request->all()]);

        try {
            $campaign = Campaign::findOrFail($id);

            $this->handleMultipartRequest($request);
            $this->validateUpdateRequest($request);

            $data = $this->prepareUpdateData($request);
            $uploadedFiles = $this->initializeUploadedFilesArray();
            $oldFilesToDelete = $this->initializeOldFilesArray();

            DB::beginTransaction();

            try {
                $this->processFileUploads($request, $campaign, $data, $uploadedFiles, $oldFilesToDelete);
                $campaign->update($data);
                DB::commit();

                Log::info('Campaign database update committed', ['id' => $campaign->id]);
            } catch (Exception $e) {
                DB::rollBack();
                $this->rollbackUploadedFiles($uploadedFiles);

                throw $e;
            }

            $this->cleanupOldFiles($oldFilesToDelete, $campaign->id);

            Log::info('Campaign updated successfully', ['id' => $campaign->id]);

            return response()->json([
                'success' => true,
                'message' => 'Campaign updated successfully',
                'data' => $campaign->fresh()->load(['brand', 'bids']),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to update campaign', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update campaign',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Approve a campaign.
     */
    public function approve(int $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            $campaign = Campaign::findOrFail($id);

            if (!$campaign->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending campaigns can be approved',
                ], 422);
            }

            $campaign->approve($user->id);

            AdminNotificationService::notifyAdminOfSystemActivity('campaign_approved', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'brand_name' => $campaign->brand->name,
                'approved_by' => $user->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Campaign approved successfully',
                'data' => $campaign->load(['brand', 'approvedBy']),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to approve campaign: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve campaign',
            ], 500);
        }
    }

    /**
     * Reject a campaign.
     */
    public function reject(int $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            $campaign = Campaign::findOrFail($id);

            if (!$campaign->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending campaigns can be rejected',
                ], 422);
            }

            $campaign->reject($user->id, 'Rejected by admin');

            return response()->json([
                'success' => true,
                'message' => 'Campaign rejected successfully',
                'data' => $campaign->load(['brand', 'approvedBy']),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to reject campaign: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject campaign',
            ], 500);
        }
    }

    /**
     * Request text changes from the brand before approval.
     */
    public function requestTextChanges(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'suggested_title' => 'nullable|string|max:255',
            'suggested_description' => 'nullable|string|max:5000',
            'note' => 'nullable|string|max:5000',
        ]);

        try {
            $user = $this->getAuthenticatedUser();
            $campaign = Campaign::with(['brand'])->findOrFail($id);

            if (!$campaign->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending campaigns can receive text suggestions',
                ], 422);
            }

            $suggestedTitle = isset($validated['suggested_title'])
                ? trim((string) $validated['suggested_title'])
                : null;
            $suggestedDescription = isset($validated['suggested_description'])
                ? trim((string) $validated['suggested_description'])
                : null;
            $note = isset($validated['note']) ? trim((string) $validated['note']) : null;

            $suggestedTitle = '' !== (string) $suggestedTitle ? $suggestedTitle : null;
            $suggestedDescription = '' !== (string) $suggestedDescription ? $suggestedDescription : null;
            $note = '' !== (string) $note ? $note : null;

            if ($suggestedTitle === $campaign->title) {
                $suggestedTitle = null;
            }

            if ($suggestedDescription === $campaign->description) {
                $suggestedDescription = null;
            }

            if (!$suggestedTitle && !$suggestedDescription && !$note) {
                return response()->json([
                    'success' => false,
                    'message' => 'Provide at least one suggested change or an observation',
                ], 422);
            }

            DB::beginTransaction();

            try {
                $campaign->textSuggestions()
                    ->open()
                    ->update([
                        'status' => CampaignTextSuggestion::STATUS_SUPERSEDED,
                        'resolved_at' => now(),
                        'resolved_by' => $user->id,
                    ])
                ;

                $suggestion = $campaign->textSuggestions()->create([
                    'admin_id' => $user->id,
                    'current_title' => $campaign->title,
                    'current_description' => $campaign->description,
                    'suggested_title' => $suggestedTitle,
                    'suggested_description' => $suggestedDescription,
                    'note' => $note,
                    'status' => CampaignTextSuggestion::STATUS_OPEN,
                ]);

                app(CampaignAuditService::class)->log($campaign, 'text_revision_requested', [
                    'suggestion_id' => $suggestion->id,
                    'suggested_title' => $suggestedTitle,
                    'suggested_description' => $suggestedDescription,
                    'note' => $note,
                    'brand_id' => $campaign->brand_id,
                ]);

                DB::commit();
            } catch (Exception $e) {
                DB::rollBack();

                throw $e;
            }

            $suggestion->load(['admin', 'campaign.brand']);

            CampaignNotificationService::notifyBrandOfTextSuggestion($suggestion);

            return response()->json([
                'success' => true,
                'message' => 'Text suggestion sent successfully',
                'data' => $this->transformTextSuggestionData($suggestion),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to request campaign text changes', [
                'campaign_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send text suggestion',
            ], 500);
        }
    }

    /**
     * Delete a campaign.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $campaign = Campaign::findOrFail($id);

            $deletionBlockers = $this->getCampaignDeletionBlockers($campaign);
            if (!empty($deletionBlockers)) {
                $campaign->update([
                    'status' => 'cancelled',
                    'is_active' => false,
                ]);

                Log::warning('Blocked admin campaign deletion due linked workflow data', [
                    'campaign_id' => $campaign->id,
                    'admin_user_id' => optional($this->getAuthenticatedUser())->id,
                    'blockers' => $deletionBlockers,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Campanha com vinculos operacionais foi arquivada em vez de excluida.',
                    'archived' => true,
                    'deleted' => false,
                    'blockers' => $deletionBlockers,
                    'data' => $campaign->fresh(),
                ]);
            }

            $this->deleteCampaignFiles($campaign);
            $campaign->delete();

            Log::warning('Admin deleted campaign', [
                'campaign_id' => $campaign->id,
                'admin_user_id' => optional($this->getAuthenticatedUser())->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Campaign deleted successfully',
                'archived' => false,
                'deleted' => true,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to delete campaign: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete campaign',
            ], 500);
        }
    }

    // ========================================
    // Private Helper Methods
    // ========================================

    private function transformCampaignData(Campaign $campaign): array
    {
        $brandData = null;
        if ($campaign->brand) {
            $brandData = [
                'id' => $campaign->brand->id,
                'name' => $campaign->brand->name,
                'company_name' => $campaign->brand->company_name,
                'email' => $campaign->brand->email,
            ];
        }

        return [
            'id' => $campaign->id,
            'title' => $campaign->title,
            'description' => $campaign->description,
            'budget' => $campaign->budget,
            'status' => $campaign->status,
            'is_active' => $campaign->is_active,
            'created_at' => $campaign->created_at->format('Y-m-d H:i:s'),
            'brand' => $brandData,
            'applications_count' => $campaign->applications->count(),
            'has_open_text_suggestion' => null !== $campaign->openTextSuggestion,
        ];
    }

    /**
     * Protect campaign deletion from removing operational history via FK cascade.
     *
     * @return array<string, int>
     */
    private function getCampaignDeletionBlockers(Campaign $campaign): array
    {
        $campaignId = (int) $campaign->id;

        $applicationsCount = DB::table('campaign_applications')
            ->where('campaign_id', $campaignId)
            ->count();

        $chatRoomsCount = DB::table('chat_rooms')
            ->where('campaign_id', $campaignId)
            ->count();

        $offersQuery = DB::table('offers')->where('campaign_id', $campaignId);
        $offersCount = (clone $offersQuery)->count();
        $offerIds = (clone $offersQuery)->pluck('id');

        $contractsCount = $offerIds->isEmpty()
            ? 0
            : DB::table('contracts')->whereIn('offer_id', $offerIds)->count();

        $contractIds = $offerIds->isEmpty()
            ? collect()
            : DB::table('contracts')->whereIn('offer_id', $offerIds)->pluck('id');

        $jobPaymentsCount = $contractIds->isEmpty()
            ? 0
            : DB::table('job_payments')->whereIn('contract_id', $contractIds)->count();

        $blockers = [
            'applications' => $applicationsCount,
            'chat_rooms' => $chatRoomsCount,
            'offers' => $offersCount,
            'contracts' => $contractsCount,
            'job_payments' => $jobPaymentsCount,
        ];

        return array_filter($blockers, static fn (int $count): bool => $count > 0);
    }

    private function transformTextSuggestionData(?CampaignTextSuggestion $suggestion): ?array
    {
        if (!$suggestion) {
            return null;
        }

        return [
            'id' => $suggestion->id,
            'status' => $suggestion->status,
            'current_title' => $suggestion->current_title,
            'current_description' => $suggestion->current_description,
            'suggested_title' => $suggestion->suggested_title,
            'suggested_description' => $suggestion->suggested_description,
            'note' => $suggestion->note,
            'created_at' => $suggestion->created_at?->toISOString(),
            'admin' => $suggestion->admin ? [
                'id' => $suggestion->admin->id,
                'name' => $suggestion->admin->name,
                'email' => $suggestion->admin->email,
            ] : null,
        ];
    }

    private function handleMultipartRequest(Request $request): void
    {
        $contentType = $request->header('Content-Type');
        $isMultipart = str_contains($contentType, 'multipart/form-data');

        if ($isMultipart && empty($request->all()) && !empty($request->getContent())) {
            Log::info('Multipart request detected but empty, attempting manual parsing');
            $parsedData = $this->parseMultipartData($request);

            foreach ($parsedData as $key => $value) {
                if (!($value instanceof UploadedFile) && !is_array($value)) {
                    $request->merge([$key => $value]);
                } elseif (is_array($value) && !empty($value) && !($value[0] instanceof UploadedFile)) {
                    $request->merge([$key => $value]);
                }
            }

            foreach ($parsedData as $key => $value) {
                if ($value instanceof UploadedFile) {
                    $request->files->set($key, $value);
                } elseif (is_array($value) && !empty($value) && ($value[0] instanceof UploadedFile)) {
                    $request->files->set($key, $value);
                }
            }
        }
    }

    private function validateUpdateRequest(Request $request): void
    {
        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:5000',
            'budget' => 'sometimes|nullable|numeric|min:0|max:999999.99',
            'requirements' => 'sometimes|nullable|string|max:5000',
            'remuneration_type' => 'sometimes|nullable|in:paga,permuta',
            'target_states' => 'sometimes|nullable|array',
            'target_states.*' => 'string|max:255',
            'target_genders' => 'sometimes|nullable|array',
            'target_genders.*' => 'string|max:255',
            'target_creator_types' => 'sometimes|nullable|array',
            'target_creator_types.*' => 'string|max:255',
            'min_age' => 'sometimes|nullable|integer|min:0|max:150',
            'max_age' => 'sometimes|nullable|integer|min:0|max:150',
            'category' => 'sometimes|nullable|string|max:255',
            'campaign_type' => 'sometimes|nullable|string|max:255',
            'deadline' => 'sometimes|nullable|date',
            'status' => 'sometimes|in:pending,approved,rejected,archived',
        ]);
    }

    private function prepareUpdateData(Request $request): array
    {
        $fields = [
            'title', 'description', 'budget', 'requirements', 'remuneration_type',
            'target_states', 'target_genders', 'target_creator_types',
            'min_age', 'max_age', 'category', 'campaign_type', 'deadline', 'status',
        ];

        $data = [];
        foreach ($fields as $field) {
            $value = $request->input($field);
            if (null !== $value) {
                $data[$field] = $value;
            }
        }

        $data = array_filter($data, fn ($v) => !is_null($v));

        if (isset($data['deadline']) && is_string($data['deadline'])) {
            try {
                $deadline = Carbon::createFromFormat('Y-m-d', $data['deadline'])->startOfDay();
                $data['deadline'] = $deadline->format('Y-m-d');
            } catch (Exception $e) {
                Log::warning('Invalid deadline format', ['deadline' => $data['deadline']]);
                unset($data['deadline']);
            }
        }

        return $data;
    }

    private function initializeUploadedFilesArray(): array
    {
        return ['image' => null, 'logo' => null, 'attachments' => []];
    }

    private function initializeOldFilesArray(): array
    {
        return ['image' => null, 'logo' => null, 'attachments' => []];
    }

    private function processFileUploads(Request $request, Campaign $campaign, array &$data, array &$uploadedFiles, array &$oldFilesToDelete): void
    {
        if ($request->hasFile('image')) {
            $newImageUrl = $this->uploadFile($request->file('image'), 'campaigns/images');
            if ($newImageUrl) {
                $uploadedFiles['image'] = $newImageUrl;
                $oldFilesToDelete['image'] = $campaign->image_url;
                $data['image_url'] = $newImageUrl;
            } else {
                throw new Exception('Failed to upload campaign image');
            }
        }

        if ($request->hasFile('logo')) {
            $newLogo = $this->uploadFile($request->file('logo'), 'campaigns/logos');
            if ($newLogo) {
                $uploadedFiles['logo'] = $newLogo;
                $oldFilesToDelete['logo'] = $campaign->logo;
                $data['logo'] = $newLogo;
            } else {
                throw new Exception('Failed to upload campaign logo');
            }
        }

        if ($request->hasFile('attach_file')) {
            $attachmentFiles = $request->file('attach_file');
            if (!is_array($attachmentFiles)) {
                $attachmentFiles = [$attachmentFiles];
            }

            $attachmentUrls = [];
            foreach ($attachmentFiles as $file) {
                $uploadedUrl = $this->uploadFile($file, 'campaigns/attachments');
                if ($uploadedUrl) {
                    $attachmentUrls[] = $uploadedUrl;
                    $uploadedFiles['attachments'][] = $uploadedUrl;
                } else {
                    foreach ($attachmentUrls as $url) {
                        $this->deleteFile($url);
                    }

                    throw new Exception('Failed to upload campaign attachments');
                }
            }

            if ($campaign->attach_file && !empty($attachmentUrls)) {
                $oldAttachments = is_array($campaign->attach_file)
                    ? $campaign->attach_file
                    : [$campaign->attach_file];
                $oldFilesToDelete['attachments'] = $oldAttachments;
            }

            $data['attach_file'] = $attachmentUrls;
        }
    }

    private function rollbackUploadedFiles(array $uploadedFiles): void
    {
        if ($uploadedFiles['image']) {
            $this->deleteFile($uploadedFiles['image']);
        }
        if ($uploadedFiles['logo']) {
            $this->deleteFile($uploadedFiles['logo']);
        }
        foreach ($uploadedFiles['attachments'] as $attachment) {
            $this->deleteFile($attachment);
        }
    }

    private function cleanupOldFiles(array $oldFilesToDelete, int $campaignId): void
    {
        if ($oldFilesToDelete['image']) {
            $this->deleteFile($oldFilesToDelete['image']);
            Log::info('Deleted old campaign image', ['campaign_id' => $campaignId]);
        }
        if ($oldFilesToDelete['logo']) {
            $this->deleteFile($oldFilesToDelete['logo']);
            Log::info('Deleted old campaign logo', ['campaign_id' => $campaignId]);
        }
        foreach ($oldFilesToDelete['attachments'] as $oldAttachment) {
            $this->deleteFile($oldAttachment);
        }
    }

    private function deleteCampaignFiles(Campaign $campaign): void
    {
        if ($campaign->image_url) {
            $this->deleteFile($campaign->image_url);
        }
        if ($campaign->logo) {
            $this->deleteFile($campaign->logo);
        }
        if ($campaign->attach_file) {
            $attachments = is_array($campaign->attach_file)
                ? $campaign->attach_file
                : [$campaign->attach_file];
            foreach ($attachments as $attachment) {
                $this->deleteFile($attachment);
            }
        }
    }

    private function uploadFile($file, string $path): string
    {
        $fileName = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
        $filePath = $file->storeAs($path, $fileName, config('filesystems.default'));

        return Storage::url($filePath);
    }

    private function deleteFile(?string $fileUrl): void
    {
        if (!$fileUrl) {
            return;
        }

        try {
            $path = str_replace('/storage/', '', $fileUrl);
            if (Storage::disk(config('filesystems.default'))->exists($path)) {
                Storage::disk(config('filesystems.default'))->delete($path);
            }
        } catch (Exception $e) {
            Log::warning('Failed to delete file: '.$fileUrl.' - '.$e->getMessage());
        }
    }

    private function parseMultipartData(Request $request): array
    {
        $rawContent = $request->getContent();
        $contentType = $request->header('Content-Type');

        if (!preg_match('/boundary=(.+)$/', $contentType, $matches)) {
            return [];
        }

        $boundary = '--'.trim($matches[1]);
        $parts = explode($boundary, $rawContent);
        $parsedData = [];

        foreach ($parts as $part) {
            if (empty(trim($part)) || '--' === $part) {
                continue;
            }

            $headerEnd = strpos($part, "\r\n\r\n");
            if (false === $headerEnd) {
                $headerEnd = strpos($part, "\n\n");
                if (false === $headerEnd) {
                    continue;
                }
                $content = substr($part, $headerEnd + 2);
            } else {
                $content = substr($part, $headerEnd + 4);
            }

            $headers = substr($part, 0, $headerEnd);
            $content = rtrim($content, "\r\n-");

            if (preg_match('/name="([^"]+)"/', $headers, $matches)) {
                $originalFieldName = $matches[1];

                if (preg_match('/filename="([^"]+)"/', $headers, $fileMatches)) {
                    $filename = $fileMatches[1];
                    $fieldName = preg_replace('/\[\d*\]$/', '', $originalFieldName);
                    $fieldName = str_replace('[]', '', $fieldName);

                    if (!empty($content)) {
                        $tempPath = tempnam(sys_get_temp_dir(), 'upload_');
                        file_put_contents($tempPath, $content);

                        if (str_contains($originalFieldName, '[]') || preg_match('/\[\d+\]$/', $originalFieldName)) {
                            if (!isset($parsedData[$fieldName])) {
                                $parsedData[$fieldName] = [];
                            }
                            $parsedData[$fieldName][] = new UploadedFile(
                                $tempPath,
                                $filename,
                                mime_content_type($tempPath) ?: 'application/octet-stream',
                                null,
                                true
                            );
                        } else {
                            $parsedData[$fieldName] = new UploadedFile(
                                $tempPath,
                                $filename,
                                mime_content_type($tempPath) ?: 'application/octet-stream',
                                null,
                                true
                            );
                        }
                    }
                } else {
                    if (str_contains($originalFieldName, '[]')) {
                        $baseFieldName = str_replace('[]', '', $originalFieldName);
                        if (!isset($parsedData[$baseFieldName])) {
                            $parsedData[$baseFieldName] = [];
                        }
                        $parsedData[$baseFieldName][] = $content;
                    } elseif (preg_match('/\[(\d+)\]$/', $originalFieldName, $arrayMatches)) {
                        $baseFieldName = preg_replace('/\[\d+\]$/', '', $originalFieldName);
                        if (!isset($parsedData[$baseFieldName])) {
                            $parsedData[$baseFieldName] = [];
                        }
                        $index = (int) $arrayMatches[1];
                        $parsedData[$baseFieldName][$index] = $content;
                    } else {
                        $parsedData[$originalFieldName] = $content;
                    }
                }
            }
        }

        return $parsedData;
    }
}

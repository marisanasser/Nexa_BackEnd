<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function getDashboardMetrics(): JsonResponse
    {
        try {
            $metrics = [
                'pendingCampaignsCount' => Campaign::where('status', 'pending')->count(),
                'allActiveCampaignCount' => Campaign::where('is_active', true)->count(),
                'allRejectCampaignCount' => Campaign::where('status', 'rejected')->count(),
                'allUserCount' => User::whereNotIn('role', ['admin'])->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $metrics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard metrics: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getPendingCampaigns(): JsonResponse
    {
        try {
            $campaigns = Campaign::with('brand')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($campaign) {
                    return [
                        'id' => $campaign->id,
                        'title' => $campaign->title,
                        'brand' => $campaign->brand->company_name ?: $campaign->brand->name,
                        'type' => $campaign->campaign_type ?: 'Vídeo',
                        'value' => $campaign->budget ? (float) $campaign->budget : 0,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $campaigns,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending campaigns: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getRecentUsers(): JsonResponse
    {
        try {
            $users = User::whereNotIn('role', ['admin'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($user) {
                    $daysAgo = $user->created_at->diffInDays(now());

                    $roleDisplay = match ($user->role) {
                        'brand' => 'Marca',
                        'creator' => 'Criador',
                        default => 'Usuário'
                    };

                    $tag = match ($user->role) {
                        'brand' => 'Marca',
                        'creator' => 'Criador',
                        default => 'Usuário'
                    };

                    if ($user->has_premium) {
                        $tag = 'Pagante';
                    }

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'role' => $roleDisplay,
                        'registeredDaysAgo' => $daysAgo,
                        'tag' => $tag,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $users,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent users: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getUsers(Request $request): JsonResponse
    {
        $request->validate([
            'role' => 'nullable|in:creator,brand',
            'status' => 'nullable|in:active,blocked,removed,pending',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $role = $request->input('role');
        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $query = User::query();

        if ($role) {
            $query->where('role', $role);
        }

        if ($status) {
            switch ($status) {
                case 'active':
                    $query->where('email_verified_at', '!=', null);
                    break;
                case 'blocked':
                    $query->where('email_verified_at', '=', null);
                    break;
                case 'removed':
                    $query->where('deleted_at', '!=', null);
                    break;
                case 'pending':
                    $query->where('email_verified_at', '=', null);
                    break;
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        $users = $query->withCount([
            'campaignApplications as applied_campaigns',
            'campaignApplications as approved_campaigns' => function ($q) {
                $q->where('status', 'approved');
            },
            'campaigns as created_campaigns',
        ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $transformedUsers = $users->getCollection()->map(function ($user) {
            return $this->transformUserData($user);
        });

        return response()->json([
            'success' => true,
            'data' => $transformedUsers,
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }

    public function getCreators(Request $request): JsonResponse
    {
        $request->merge(['role' => 'creator']);

        return $this->getUsers($request);
    }

    public function getBrands(Request $request): JsonResponse
    {
        $request->merge(['role' => 'brand']);

        return $this->getUsers($request);
    }

    public function getCampaigns(Request $request): JsonResponse
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

        $query = Campaign::with(['brand', 'applications']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $campaigns = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $transformedCampaigns = $campaigns->getCollection()->map(function ($campaign) {
            return [
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
                'applications_count' => $campaign->applications->count(),
            ];
        });

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

    public function getCampaign(int $id): JsonResponse
    {
        try {
            $campaign = Campaign::with(['brand', 'applications.creator'])
                ->findOrFail($id);

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
                'applications' => $campaign->applications->map(function ($application) {
                    return [
                        'id' => $application->id,
                        'status' => $application->status,
                        'proposal' => $application->proposal,
                        'created_at' => $application->created_at->format('Y-m-d H:i:s'),
                        'creator' => [
                            'id' => $application->creator->id,
                            'name' => $application->creator->name,
                            'email' => $application->creator->email,
                        ],
                    ];
                }),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }
    }

    public function updateCampaign(Request $request, int $id): JsonResponse
    {
        \Log::info('Update campaign request:', [
            'request' => $request->all(),
        ]);
        try {
            $campaign = Campaign::findOrFail($id);
            \Log::info('Campaign found:', [
                'campaign' => $campaign,
            ]);

            $contentType = $request->header('Content-Type');
            $isMultipart = strpos($contentType, 'multipart/form-data') !== false;

            if ($isMultipart && empty($request->all()) && ! empty($request->getContent())) {
                \Log::info('Multipart request detected but empty, attempting manual parsing');
                $parsedData = $this->parseMultipartData($request);
                \Log::info('Manually parsed data:', [
                    'fields_count' => count($parsedData),
                    'fields' => array_keys($parsedData),
                ]);

                foreach ($parsedData as $key => $value) {

                    if (! ($value instanceof \Illuminate\Http\UploadedFile) && ! is_array($value)) {
                        $request->merge([$key => $value]);
                    } elseif (is_array($value) && ! empty($value) && ! ($value[0] instanceof \Illuminate\Http\UploadedFile)) {

                        $request->merge([$key => $value]);
                    }
                }

                foreach ($parsedData as $key => $value) {
                    if ($value instanceof \Illuminate\Http\UploadedFile) {
                        $request->files->set($key, $value);
                    } elseif (is_array($value) && ! empty($value) && ($value[0] instanceof \Illuminate\Http\UploadedFile)) {
                        $request->files->set($key, $value);
                    }
                }

                \Log::info('After manual parsing:', [
                    'request_all_count' => count($request->all()),
                    'has_title' => $request->has('title'),
                    'title_value' => $request->input('title'),
                    'has_files' => $request->hasFile('logo') || $request->hasFile('image') || $request->hasFile('attach_file'),
                ]);
            }

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

            $fields = ['title', 'description', 'budget', 'requirements', 'remuneration_type',
                'target_states', 'target_genders', 'target_creator_types',
                'min_age', 'max_age', 'category', 'campaign_type', 'deadline', 'status'];
            \Log::info('Fields to process:', [
                'fields' => $fields,
            ]);
            $data = [];
            foreach ($fields as $field) {

                $value = $request->input($field);
                if ($value !== null) {
                    $data[$field] = $value;
                }
            }

            $allRequestData = $request->all();
            if (empty($data) && ! empty($allRequestData)) {

                $data = $request->only($fields);
            }

            \Log::info('Campaign update data:', [
                'data_from_input' => $data,
                'all_request' => $allRequestData,
                'content_type' => $request->header('Content-Type'),
                'method' => $request->method(),
                'has_title' => $request->has('title'),
                'title_value' => $request->input('title'),
                'has_files' => $request->hasFile('logo') || $request->hasFile('image') || $request->hasFile('attach_file'),
            ]);

            $data = array_filter($data, fn ($v) => ! is_null($v));

            if (isset($data['deadline']) && is_string($data['deadline'])) {
                try {

                    $deadline = \Carbon\Carbon::createFromFormat('Y-m-d', $data['deadline'])->startOfDay();
                    $data['deadline'] = $deadline->format('Y-m-d');
                } catch (\Exception $e) {
                    \Log::warning('Invalid deadline format', ['deadline' => $data['deadline']]);
                    unset($data['deadline']);
                }
            }

            $uploadedFiles = [
                'image' => null,
                'logo' => null,
                'attachments' => [],
            ];

            $oldFilesToDelete = [
                'image' => null,
                'logo' => null,
                'attachments' => [],
            ];

            DB::beginTransaction();
            try {

                if ($request->hasFile('image')) {

                    $newImageUrl = $this->uploadFile($request->file('image'), 'campaigns/images');
                    if ($newImageUrl) {
                        $uploadedFiles['image'] = $newImageUrl;
                        $oldFilesToDelete['image'] = $campaign->image_url;
                        $data['image_url'] = $newImageUrl;
                    } else {
                        throw new \Exception('Failed to upload campaign image');
                    }
                }

                if ($request->hasFile('logo')) {

                    $newLogo = $this->uploadFile($request->file('logo'), 'campaigns/logos');
                    if ($newLogo) {
                        $uploadedFiles['logo'] = $newLogo;
                        $oldFilesToDelete['logo'] = $campaign->logo;
                        $data['logo'] = $newLogo;
                    } else {
                        throw new \Exception('Failed to upload campaign logo');
                    }
                }

                if ($request->hasFile('attach_file')) {
                    $attachmentFiles = $request->file('attach_file');

                    if (! is_array($attachmentFiles)) {
                        $attachmentFiles = [$attachmentFiles];
                    }

                    $attachmentUrls = [];
                    foreach ($attachmentFiles as $file) {
                        $uploadedUrl = $this->uploadFile($file, 'campaigns/attachments');
                        if ($uploadedUrl) {
                            $attachmentUrls[] = $uploadedUrl;
                            $uploadedFiles['attachments'][] = $uploadedUrl;
                        } else {

                            DB::rollBack();
                            foreach ($attachmentUrls as $uploadedUrl) {
                                $this->deleteFile($uploadedUrl);
                            }
                            throw new \Exception('Failed to upload campaign attachments');
                        }
                    }

                    if ($campaign->attach_file && ! empty($attachmentUrls)) {
                        $oldAttachments = is_array($campaign->attach_file)
                            ? $campaign->attach_file
                            : [$campaign->attach_file];
                        $oldFilesToDelete['attachments'] = $oldAttachments;
                    }

                    $data['attach_file'] = $attachmentUrls;
                }

                $campaign->update($data);

                DB::commit();

                \Log::info('Campaign database update committed', ['id' => $campaign->id]);

            } catch (\Exception $e) {

                DB::rollBack();

                \Log::error('Campaign update transaction rolled back', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);

                if ($uploadedFiles['image']) {
                    $this->deleteFile($uploadedFiles['image']);
                    \Log::info('Rolled back: deleted uploaded image', [
                        'file' => $uploadedFiles['image'],
                    ]);
                }
                if ($uploadedFiles['logo']) {
                    $this->deleteFile($uploadedFiles['logo']);
                    \Log::info('Rolled back: deleted uploaded logo', [
                        'file' => $uploadedFiles['logo'],
                    ]);
                }
                foreach ($uploadedFiles['attachments'] as $uploadedAttachment) {
                    $this->deleteFile($uploadedAttachment);
                }
                if (! empty($uploadedFiles['attachments'])) {
                    \Log::info('Rolled back: deleted uploaded attachments', [
                        'count' => count($uploadedFiles['attachments']),
                    ]);
                }

                throw $e;
            }

            if ($oldFilesToDelete['image']) {
                $this->deleteFile($oldFilesToDelete['image']);
                \Log::info('Deleted old campaign image after successful update', [
                    'campaign_id' => $campaign->id,
                    'old_image_url' => $oldFilesToDelete['image'],
                ]);
            }
            if ($oldFilesToDelete['logo']) {
                $this->deleteFile($oldFilesToDelete['logo']);
                \Log::info('Deleted old campaign logo after successful update', [
                    'campaign_id' => $campaign->id,
                    'old_logo' => $oldFilesToDelete['logo'],
                ]);
            }
            foreach ($oldFilesToDelete['attachments'] as $oldAttachment) {
                $this->deleteFile($oldAttachment);
            }
            if (! empty($oldFilesToDelete['attachments'])) {
                \Log::info('Deleted old campaign attachments after successful update', [
                    'campaign_id' => $campaign->id,
                    'old_attachments_count' => count($oldFilesToDelete['attachments']),
                ]);
            }

            \Log::info('Campaign updated successfully', ['id' => $campaign->id]);

            return response()->json([
                'success' => true,
                'message' => 'Campaign updated successfully',
                'data' => $campaign->fresh()->load(['brand', 'bids']),
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to update campaign', [
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

    public function approveCampaign(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $campaign = Campaign::findOrFail($id);

            if (! $campaign->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending campaigns can be approved',
                ], 422);
            }

            $campaign->approve($user->id);

            \App\Services\NotificationService::notifyAdminOfSystemActivity('campaign_approved', [
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
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to approve campaign: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve campaign',
            ], 500);
        }
    }

    public function rejectCampaign(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $campaign = Campaign::findOrFail($id);

            if (! $campaign->isPending()) {
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
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to reject campaign: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject campaign',
            ], 500);
        }
    }

    public function deleteCampaign(int $id): JsonResponse
    {
        try {
            $campaign = Campaign::findOrFail($id);

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

            $campaign->delete();

            return response()->json([
                'success' => true,
                'message' => 'Campaign deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete campaign: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete campaign',
            ], 500);
        }
    }

    private function uploadFile($file, string $path): string
    {
        $fileName = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
        $filePath = $file->storeAs($path, $fileName, 'public');

        return \Illuminate\Support\Facades\Storage::url($filePath);
    }

    private function deleteFile(?string $fileUrl): void
    {
        if (! $fileUrl) {
            return;
        }

        try {
            $path = str_replace('/storage/', '', $fileUrl);
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete file: '.$fileUrl.' - '.$e->getMessage());
        }
    }

    private function parseMultipartData(Request $request): array
    {
        $rawContent = $request->getContent();
        $contentType = $request->header('Content-Type');

        if (! preg_match('/boundary=(.+)$/', $contentType, $matches)) {
            return [];
        }

        $boundary = '--'.trim($matches[1]);
        $parts = explode($boundary, $rawContent);
        $parsedData = [];

        foreach ($parts as $part) {
            if (empty(trim($part)) || $part === '--') {
                continue;
            }

            $headerEnd = strpos($part, "\r\n\r\n");
            if ($headerEnd === false) {

                $headerEnd = strpos($part, "\n\n");
                if ($headerEnd === false) {
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

                    if (! empty($content)) {

                        $tempPath = tempnam(sys_get_temp_dir(), 'upload_');
                        file_put_contents($tempPath, $content);

                        if (strpos($originalFieldName, '[]') !== false || preg_match('/\[\d+\]$/', $originalFieldName)) {
                            if (! isset($parsedData[$fieldName])) {
                                $parsedData[$fieldName] = [];
                            }
                            $parsedData[$fieldName][] = new \Illuminate\Http\UploadedFile(
                                $tempPath,
                                $filename,
                                mime_content_type($tempPath) ?: 'application/octet-stream',
                                null,
                                true
                            );
                        } else {
                            $parsedData[$fieldName] = new \Illuminate\Http\UploadedFile(
                                $tempPath,
                                $filename,
                                mime_content_type($tempPath) ?: 'application/octet-stream',
                                null,
                                true
                            );
                        }
                    }
                } else {

                    if (strpos($originalFieldName, '[]') !== false) {

                        $baseFieldName = str_replace('[]', '', $originalFieldName);
                        if (! isset($parsedData[$baseFieldName])) {
                            $parsedData[$baseFieldName] = [];
                        }
                        $parsedData[$baseFieldName][] = $content;
                    } elseif (preg_match('/\[(\d+)\]$/', $originalFieldName, $arrayMatches)) {

                        $baseFieldName = preg_replace('/\[\d+\]$/', '', $originalFieldName);
                        if (! isset($parsedData[$baseFieldName])) {
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

    public function getUserStatistics(): JsonResponse
    {
        $stats = [
            'total_users' => User::count(),
            'creators' => User::where('role', 'creator')->count(),
            'brands' => User::where('role', 'brand')->count(),
            'premium_users' => User::where('has_premium', true)->count(),
            'verified_students' => User::where('student_verified', true)->count(),
            'active_users' => User::where('email_verified_at', '!=', null)->count(),
            'pending_users' => User::where('email_verified_at', '=', null)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    public function updateUserStatus(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:activate,block,remove',
        ]);

        $action = $request->input('action');

        try {
            switch ($action) {
                case 'activate':
                    $user->update([
                        'email_verified_at' => now(),
                    ]);
                    $message = 'User activated successfully';
                    break;

                case 'block':
                    $user->update([
                        'email_verified_at' => null,
                    ]);
                    $message = 'User blocked successfully';
                    break;

                case 'remove':
                    $user->delete();
                    $message = 'User removed successfully';
                    break;

                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid action',
                    ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'user' => $this->transformUserData($user->fresh()),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status: '.$e->getMessage(),
            ], 500);
        }
    }

    private function transformUserData(User $user): array
    {
        $isCreator = $user->role === 'creator';

        if ($isCreator) {

            $status = 'Criador';
            $statusColor = 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-200';

            if ($user->has_premium) {
                $status = 'Pagante';
                $statusColor = 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-200';
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $status,
                'statusColor' => $statusColor,
                'time' => $this->getUserTimeStatus($user),
                'campaigns' => ($user->applied_campaigns ?? 0).' aplicadas / '.($user->approved_campaigns ?? 0).' aprovadas',
                'accountStatus' => $this->getAccountStatus($user),
                'created_at' => $user->created_at,
                'email_verified_at' => $user->email_verified_at,
                'has_premium' => $user->has_premium,
                'student_verified' => $user->student_verified,
                'premium_expires_at' => $user->premium_expires_at,
                'free_trial_expires_at' => $user->free_trial_expires_at,
            ];
        } else {

            $status = 'Marca';
            $statusColor = 'bg-purple-100 text-purple-600 dark:bg-purple-900 dark:text-purple-200';

            if ($user->has_premium) {
                $status = 'Pagante';
                $statusColor = 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-200';
            }

            return [
                'id' => $user->id,
                'company' => $user->company_name ?: $user->name,
                'brandName' => $user->company_name ?: $user->name,
                'email' => $user->email,
                'status' => $status,
                'statusColor' => $statusColor,
                'campaigns' => $user->created_campaigns,
                'accountStatus' => $this->getAccountStatus($user),
                'created_at' => $user->created_at,
                'email_verified_at' => $user->email_verified_at,
                'has_premium' => $user->has_premium,
                'premium_expires_at' => $user->premium_expires_at,
                'free_trial_expires_at' => $user->free_trial_expires_at,
            ];
        }
    }

    private function getUserTimeStatus(User $user): string
    {
        if ($user->has_premium && $user->premium_expires_at === null) {
            return 'Ilimitado';
        }

        if ($user->has_premium && $user->premium_expires_at) {

            $premiumExpiresAt = $user->premium_expires_at instanceof Carbon
                ? $user->premium_expires_at
                : Carbon::parse($user->premium_expires_at);
            $months = $premiumExpiresAt->diffInMonths(now());

            return $months.' meses';
        }

        if ($user->free_trial_expires_at) {

            $trialExpiresAt = $user->free_trial_expires_at instanceof Carbon
                ? $user->free_trial_expires_at
                : Carbon::parse($user->free_trial_expires_at);
            $months = $trialExpiresAt->diffInMonths(now());

            return $months.' meses';
        }

        $months = $user->created_at->diffInMonths(now());

        return $months.' meses';
    }

    private function getAccountStatus(User $user): string
    {
        if ($user->deleted_at) {
            return 'Removido';
        }

        if ($user->email_verified_at) {
            return 'Ativo';
        }

        if ($user->created_at->diffInDays(now()) > 30) {
            return 'Bloqueado';
        }

        return 'Pendente';
    }

    public function getStudents(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:active,expired,premium',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $query = User::where('student_verified', true);

        if ($status) {
            switch ($status) {
                case 'active':
                    $query->where('free_trial_expires_at', '>', now())
                        ->where('has_premium', false);
                    break;
                case 'expired':
                    $query->where('free_trial_expires_at', '<=', now())
                        ->where('has_premium', false);
                    break;
                case 'premium':
                    $query->where('has_premium', true);
                    break;
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $students = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $transformedStudents = $students->getCollection()->map(function ($student) {
            return $this->transformStudentData($student);
        });

        return response()->json([
            'success' => true,
            'data' => $transformedStudents,
            'pagination' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
                'from' => $students->firstItem(),
                'to' => $students->lastItem(),
            ],
        ]);
    }

    public function getStudentVerificationRequests(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = \App\Models\StudentVerificationRequest::query()
            ->with(['user' => function ($query) {

                $query->withTrashed();
            }])
            ->whereHas('user');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $requests = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 10);

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    public function approveStudentVerification(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'duration_months' => 'nullable|integer|min:1|max:24',
            'review_notes' => 'nullable|string|max:1000',
        ]);

        $svr = \App\Models\StudentVerificationRequest::findOrFail($id);

        if ($svr->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Request not pending'], 422);
        }

        $duration = $request->input('duration_months', 12);
        $user = $svr->user;

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User associated with this request not found',
            ], 404);
        }

        if ($user->student_verified) {
            $svr->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $request->review_notes ?? 'Auto-approved (already verified)',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User already verified, request marked as approved',
            ]);
        }

        DB::beginTransaction();
        try {
            $svr->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $request->review_notes,
            ]);

            $expiresAt = now()->addMonths($duration);
            $updateData = [
                'student_verified' => true,
                'student_expires_at' => $expiresAt,
                'free_trial_expires_at' => $expiresAt,
            ];

            if (! $user->has_premium) {
                $updateData['role'] = 'student';
            }

            $user->update($updateData);

            DB::commit();

            \App\Services\NotificationService::notifyUserOfStudentVerificationApproval($user, [
                'duration_months' => $duration,
                'expires_at' => $expiresAt,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Student verification approved successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Student verification approval failed', [
                'request_id' => $id,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Approval failed: '.$e->getMessage(),
            ], 500);
        }
    }

    public function rejectStudentVerification(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'review_notes' => 'nullable|string|max:1000',
        ]);

        $svr = \App\Models\StudentVerificationRequest::findOrFail($id);
        if ($svr->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Request not pending'], 422);
        }

        $user = $svr->user;

        $svr->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $request->review_notes,
        ]);

        if ($user) {
            try {
                \App\Services\NotificationService::notifyUserOfStudentVerificationRejection($user, [
                    'rejection_reason' => $request->review_notes,
                    'rejected_at' => now()->toISOString(),
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to notify user of rejection', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Student verification request rejected successfully',
        ]);
    }

    public function updateStudentTrial(Request $request, User $student): JsonResponse
    {
        $request->validate([
            'period' => 'required|in:1month,6months,1year',
        ]);

        if (! $student->student_verified) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a verified student',
            ], 422);
        }

        if (! $student->isStudent() && ! $student->has_premium) {
            return response()->json([
                'success' => false,
                'message' => 'User must be a student or have premium subscription',
            ], 422);
        }

        try {
            $period = $request->input('period');
            $expiresAt = match ($period) {
                '1month' => now()->addMonth(),
                '6months' => now()->addMonths(6),
                '1year' => now()->addYear(),
                default => now()->addMonth(),
            };

            $student->update([
                'free_trial_expires_at' => $expiresAt,
                'student_expires_at' => $expiresAt,
            ]);

            \Illuminate\Support\Facades\Log::info('Student trial period updated', [
                'student_id' => $student->id,
                'student_email' => $student->email,
                'period' => $period,
                'new_expires_at' => $expiresAt,
                'updated_by' => auth()->user()->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Student trial period updated successfully',
                'student' => $this->transformStudentData($student->fresh()),
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to update student trial period', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update student trial period',
            ], 500);
        }
    }

    public function updateStudentStatus(Request $request, User $student): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:activate,block,remove',
        ]);

        if (! $student->student_verified) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a verified student',
            ], 422);
        }

        $action = $request->input('action');

        try {
            switch ($action) {
                case 'activate':
                    $student->update([
                        'email_verified_at' => now(),
                    ]);
                    $message = 'Student activated successfully';
                    break;

                case 'block':
                    $student->update([
                        'email_verified_at' => null,
                    ]);
                    $message = 'Student blocked successfully';
                    break;

                case 'remove':
                    $student->delete();
                    $message = 'Student removed successfully';
                    break;

                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid action',
                    ], 400);
            }

            \Illuminate\Support\Facades\Log::info('Student status updated', [
                'student_id' => $student->id,
                'student_email' => $student->email,
                'action' => $action,
                'updated_by' => auth()->user()->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'student' => $this->transformStudentData($student->fresh()),
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to update student status', [
                'student_id' => $student->id,
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update student status: '.$e->getMessage(),
            ], 500);
        }
    }

    private function transformStudentData(User $student): array
    {
        $now = now();
        $trialExpiresAt = $student->free_trial_expires_at;

        $status = 'active';
        if ($student->has_premium) {
            $status = 'premium';
        } elseif ($trialExpiresAt && $trialExpiresAt->isPast()) {
            $status = 'expired';
        }

        $trialStatus = 'active';
        if ($student->has_premium) {
            $trialStatus = 'premium';
        } elseif ($trialExpiresAt && $trialExpiresAt->isPast()) {
            $trialStatus = 'expired';
        }

        $daysRemaining = 0;
        if ($trialExpiresAt && $trialExpiresAt->isFuture()) {
            $daysRemaining = $now->diffInDays($trialExpiresAt, false);
        }

        return [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'academic_email' => $student->academic_email ?? null,
            'institution' => $student->institution ?? null,
            'course_name' => $student->course_name ?? null,
            'student_verified' => $student->student_verified,
            'student_expires_at' => $student->student_expires_at,
            'free_trial_expires_at' => $student->free_trial_expires_at,
            'has_premium' => $student->has_premium,
            'created_at' => $student->created_at,
            'email_verified_at' => $student->email_verified_at,
            'status' => $status,
            'trial_status' => $trialStatus,
            'days_remaining' => $daysRemaining,
        ];
    }

    public function getGuides(): JsonResponse
    {
        try {
            $guides = \App\Models\Guide::with('steps')->latest()->get();

            return response()->json([
                'success' => true,
                'data' => $guides,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch guides: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getGuide($id): JsonResponse
    {
        try {
            $guide = \App\Models\Guide::with('steps')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $guide,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch guide: '.$e->getMessage(),
            ], 500);
        }
    }

    public function updateGuide($id, Request $request): JsonResponse
    {
        try {

            $validated = $request->validate([
                'title' => 'required|string|min:2|max:255',
                'audience' => 'required|string|in:Brand,Creator',
                'description' => 'required|string|min:10',
                'steps' => 'sometimes|array',
                'steps.*.title' => 'required_with:steps|string|min:2|max:255',
                'steps.*.description' => 'required_with:steps|string|min:10',
                'steps.*.videoFile' => 'sometimes|nullable|file|mimes:mp4,mov,avi,wmv,mpeg|max:81920',
            ]);

            $guide = \App\Models\Guide::findOrFail($id);

            $data = $request->only(['title', 'audience', 'description']);
            $data['video_path'] = null;
            $data['video_mime'] = null;

            \DB::beginTransaction();

            $guide->update($data);

            if ($request->has('steps') && is_array($request->steps)) {

                $guide->steps()->delete();

                foreach ($request->steps as $index => $stepData) {
                    $stepFields = [
                        'guide_id' => $guide->id,
                        'title' => $stepData['title'],
                        'description' => $stepData['description'],
                        'order' => $index,
                    ];

                    if (isset($stepData['videoFile']) && $stepData['videoFile'] instanceof \Illuminate\Http\UploadedFile) {
                        $file = $stepData['videoFile'];
                        $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
                        $path = $file->storeAs('videos/steps', $filename, 'public');

                        $stepFields['video_path'] = $path;
                        $stepFields['video_mime'] = $file->getMimeType();
                    }

                    if (isset($stepData['screenshots']) && is_array($stepData['screenshots'])) {
                        $screenshotPaths = [];
                        foreach ($stepData['screenshots'] as $screenshot) {
                            if ($screenshot instanceof \Illuminate\Http\UploadedFile) {
                                $filename = \Illuminate\Support\Str::uuid()->toString().'.'.$screenshot->getClientOriginalExtension();
                                $path = $screenshot->storeAs('screenshots/steps', $filename, 'public');
                                $screenshotPaths[] = $path;
                            }
                        }
                        $stepFields['screenshots'] = $screenshotPaths;
                    }

                    \App\Models\Step::create($stepFields);
                }
            }

            \DB::commit();

            $guide->load('steps');

            return response()->json([
                'success' => true,
                'data' => $guide,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Guide update failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update guide: '.$e->getMessage(),
            ], 500);
        }
    }
}

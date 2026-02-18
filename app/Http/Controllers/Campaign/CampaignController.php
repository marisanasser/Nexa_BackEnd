<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaign;

use App\Domain\Shared\Traits\HasAuthenticatedUser;
use FFI\Exception;
use Illuminate\Support\Facades\Log;

use App\Helpers\FileUploadHelper;
use App\Http\Controllers\Base\Controller;
use App\Http\Requests\Campaign\StoreCampaignRequest;
use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignFavorite;
use App\Models\Common\Notification;
use App\Models\Contract\Contract;
use App\Models\Payment\CreatorBalance;
use App\Models\Payment\JobPayment;
use App\Models\Payment\Transaction;
use App\Models\User\User;
use App\Domain\Notification\Services\AdminNotificationService;
use App\Domain\Notification\Services\CampaignNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\Stripe;

class CampaignController extends Controller
{
    use HasAuthenticatedUser;

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            // HOTFIX: Ensure all approved campaigns are active
            Campaign::where('status', 'approved')->where('is_active', false)->update(['is_active' => true]);

            $query = Campaign::with(['brand', 'bids'])->withCount('applications');

            error_log('Request' . json_encode($request));

            if ($user->isCreator() || $user->isStudent()) {
                Log::info('Creator/Student listing campaigns', ['user_id' => $user->id, 'role' => $user->role]);
                $query->approved()->active();
            } elseif ($user->isBrand()) {
                Log::info('Brand listing campaigns', ['user_id' => $user->id]);
                $query->where('brand_id', $user->id);
            } elseif ($user->isAdmin()) {
                Log::info('Admin listing campaigns');
            } else {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('category')) {
                $query->byCategory($request->category);
            }

            if ($request->has('campaign_type')) {
                $query->byType($request->campaign_type);
            }

            if ($request->has('state')) {
                $query->forState($request->state);
            }

            if ($request->has('budget_min')) {
                $query->where('budget', '>=', $request->budget_min);
            }

            if ($request->has('budget_max')) {
                $query->where('budget', '<=', $request->budget_max);
            }

            if ($request->has('deadline_from')) {
                $query->where('deadline', '>=', $request->deadline_from);
            }

            if ($request->has('deadline_to')) {
                $query->where('deadline', '<=', $request->deadline_to);
            }

            // Filters based on creator profile removed as per request to show all campaigns
            /*
            if ($user->isCreator()) {
                $creator = $user;

                if ($creator->creator_type) {
                    $query->where(function ($q) use ($creator): void {
                        $q->whereNull('target_creator_types')
                            ->orWhereJsonLength('target_creator_types', 0)
                        ;

                        if ('both' === $creator->creator_type) {
                            $q->orWhereJsonContains('target_creator_types', 'ugc')
                                ->orWhereJsonContains('target_creator_types', 'influencer')
                                ->orWhereJsonContains('target_creator_types', 'both')
                            ;
                        } else {
                            $q->orWhereJsonContains('target_creator_types', $creator->creator_type)
                                ->orWhereJsonContains('target_creator_types', 'both')
                            ;
                        }
                    });
                }

                if ($creator->birth_date) {
                    $age = $creator->age;
                    $query->where(function ($q) use ($age): void {
                        $q->whereNull('min_age')
                            ->orWhere('min_age', '<=', $age)
                        ;
                    })->where(function ($q) use ($age): void {
                        $q->whereNull('max_age')
                            ->orWhere('max_age', '>=', $age)
                        ;
                    });
                }

                if ($creator->gender) {
                    $query->where(function ($q) use ($creator): void {
                        $q->whereNull('target_genders')
                            ->orWhereJsonLength('target_genders', 0)
                            ->orWhereJsonContains('target_genders', $creator->gender)
                        ;
                    });
                }

            }
            */

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search): void {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('requirements', 'like', "%{$search}%")
                    ;
                });
            }

            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            $query->with(['brand:id,name,avatar_url']);

            $query->orderBy('is_featured', 'desc')
                ->orderBy($sortBy, $sortOrder)
            ;

            $perPage = min($request->get('per_page', 15), 100);

            $campaigns = $query->paginate($perPage);

            if ($user->isCreator()) {
            }

            return response()->json([
                'success' => true,
                'data' => $campaigns->items(),
                'pagination' => [
                    'current_page' => $campaigns->currentPage(),
                    'last_page' => $campaigns->lastPage(),
                    'per_page' => $campaigns->perPage(),
                    'total' => $campaigns->total(),
                    'from' => $campaigns->firstItem(),
                    'to' => $campaigns->lastItem(),
                ],
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0')
            ;
        } catch (Exception $e) {
            Log::error('Failed to retrieve campaigns: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve campaigns',
                'message' => 'An error occurred while retrieving campaigns',
            ], 500);
        }
    }

    public function getCampaigns(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    public function getAllCampaigns(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);
            $query = Campaign::with(['brand', 'approvedBy', 'bids'])->withCount('applications');

            if ($user->isCreator() || $user->isStudent()) {
                $query->approved()->active();
            } elseif ($user->isBrand()) {
                $query->where('brand_id', $user->id);
            } elseif ($user->isAdmin()) {
            } else {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('category')) {
                $query->byCategory($request->category);
            }

            if ($request->has('campaign_type')) {
                $query->byType($request->campaign_type);
            }

            $campaigns = $query->orderBy('is_featured', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $campaigns,
                'count' => $campaigns->count(),
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0')
            ;
        } catch (Exception $e) {
            Log::error('Failed to retrieve all campaigns: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve campaigns',
                'message' => 'An error occurred while retrieving campaigns',
            ], 500);
        }
    }

    public function getPendingCampaigns(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if (!$user->isAdmin()) {
                return response()->json(['error' => 'Unauthorized. Admin access required.'], 403);
            }

            $query = Campaign::with(['brand', 'bids'])->pending();

            if ($request->has('category')) {
                $query->byCategory($request->category);
            }

            if ($request->has('campaign_type')) {
                $query->byType($request->campaign_type);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search): void {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                    ;
                });
            }

            $perPage = min($request->get('per_page', 15), 100);
            $campaigns = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $campaigns->items(),
                'pagination' => [
                    'current_page' => $campaigns->currentPage(),
                    'last_page' => $campaigns->lastPage(),
                    'per_page' => $campaigns->perPage(),
                    'total' => $campaigns->total(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to retrieve pending campaigns: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve pending campaigns',
                'message' => 'An error occurred while retrieving pending campaigns',
            ], 500);
        }
    }

    public function getUserCampaigns(User $user, Request $request): JsonResponse
    {
        try {
            $authUser = auth()->user();
            assert($authUser instanceof User);

            if (!$authUser->isAdmin() && $authUser->id !== $user->id) {
                return response()->json(['error' => 'Unauthorized to view other user campaigns'], 403);
            }

            $query = Campaign::with(['brand', 'approvedBy', 'bids'])
                ->where('brand_id', $user->id);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('category')) {
                $query->byCategory($request->category);
            }

            $perPage = min($request->get('per_page', 15), 100);
            $campaigns = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $campaigns->items(),
                'pagination' => [
                    'current_page' => $campaigns->currentPage(),
                    'last_page' => $campaigns->lastPage(),
                    'per_page' => $campaigns->perPage(),
                    'total' => $campaigns->total(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to retrieve user campaigns: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve user campaigns',
                'message' => 'An error occurred while retrieving user campaigns',
            ], 500);
        }
    }

    public function getCampaignsByStatus(string $status, Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            $validStatuses = ['pending', 'approved', 'rejected', 'completed', 'cancelled'];
            if (!in_array($status, $validStatuses)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid status',
                    'message' => 'Status must be one of: ' . implode(', ', $validStatuses),
                ], 400);
            }

            $query = Campaign::with(['brand', 'bids']);

            if ($user->isCreator() || $user->isStudent()) {
                if ('approved' !== $status) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Unauthorized',
                        'message' => 'Creators and students can only view approved campaigns.',
                    ], 403);
                }

                $query->approved();
            } elseif ($user->isBrand()) {
                $query->where('brand_id', $user->id)
                    ->where('status', $status)
                ;
            } elseif ($user->isAdmin()) {
                $query->where('status', $status);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'User role not authorized.',
                ], 403);
            }

            if ($request->filled('category')) {
                $query->byCategory($request->category);
            }

            if ($request->filled('campaign_type')) {
                $query->byType($request->campaign_type);
            }

            if ($request->filled('state')) {
                $query->forState($request->state);
            }

            if ($request->filled('budget_min')) {
                $query->where('budget', '>=', $request->budget_min);
            }

            if ($request->filled('budget_max')) {
                $query->where('budget', '<=', $request->budget_max);
            }

            if ($request->filled('deadline_from')) {
                $query->where('deadline', '>=', $request->deadline_from);
            }

            if ($request->filled('deadline_to')) {
                $query->where('deadline', '<=', $request->deadline_to);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search): void {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('requirements', 'like', "%{$search}%")
                    ;
                });
            }

            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = min($request->get('per_page', 15), 100);
            $campaigns = $query->paginate($perPage);

            if ($user->isCreator()) {
            }

            Log::info('Campaigns retrieved', [
                'status' => $status,
                'user_role' => $user->role,
                'total_campaigns' => $campaigns->total(),
                'current_page' => $campaigns->currentPage(),
            ]);

            return response()->json([
                'success' => true,
                'data' => $campaigns->items(),
                'meta' => [
                    'status' => $status,
                    'user_role' => $user->role ?? 'unknown',
                    'total' => $campaigns->total(),
                    'current_page' => $campaigns->currentPage(),
                    'last_page' => $campaigns->lastPage(),
                    'per_page' => $campaigns->perPage(),
                    'from' => $campaigns->firstItem(),
                    'to' => $campaigns->lastItem(),
                ],
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0')
            ;
        } catch (Exception $e) {
            Log::error('Failed to retrieve campaigns: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if (!$user->isBrand()) {
                return response()->json(['error' => 'Only brands can create campaigns'], 403);
            }

            Log::info('Campaign creation request data:', $request->all());
            Log::info('Deadline received:', [
                'deadline_raw' => $request->input('deadline'),
                'deadline_type' => gettype($request->input('deadline')),
                'all_data' => $request->all(),
            ]);

            $data = $request->validated();
            $data['brand_id'] = $user->id;
            $data['status'] ??= 'pending';
            $data['is_active'] = true;

            if (!isset($data['target_states']) || !is_array($data['target_states'])) {
                $data['target_states'] = [];
            }

            if (isset($data['min_age'], $data['max_age']) && $data['min_age'] > $data['max_age']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid age range',
                    'message' => 'Minimum age cannot be greater than maximum age',
                ], 422);
            }

            if (!isset($data['target_genders']) || !is_array($data['target_genders'])) {
                $data['target_genders'] = [];
            }

            if (!isset($data['target_creator_types']) || !is_array($data['target_creator_types']) || empty($data['target_creator_types'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Creator type required',
                    'message' => 'At least one creator type must be selected',
                ], 422);
            }

            if ($request->hasFile('image')) {
                $data['image_url'] = $this->uploadFile($request->file('image'), 'campaigns/images');
            }

            if ($request->hasFile('logo')) {
                $data['logo'] = $this->uploadFile($request->file('logo'), 'campaigns/logos');
            }

            if ($request->hasFile('attach_file')) {
                $attachmentFiles = $request->file('attach_file');

                if (!is_array($attachmentFiles)) {
                    $attachmentFiles = [$attachmentFiles];
                }

                $attachmentUrls = [];
                foreach ($attachmentFiles as $file) {
                    $attachmentUrls[] = $this->uploadFile($file, 'campaigns/attachments');
                }

                $data['attach_file'] = $attachmentUrls;
            }

            $campaign = Campaign::create($data);

            AdminNotificationService::notifyAdminOfNewCampaign($campaign);

            CampaignNotificationService::notifyBrandOfCampaignCreated($campaign);

            return response()->json([
                'success' => true,
                'message' => 'Campaign created successfully and is pending approval',
                'data' => $campaign->load(['brand', 'bids']),
            ], 201);
        } catch (Exception $e) {
            Log::error('Failed to create campaign: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to create campaign',
                'message' => 'An error occurred while creating the campaign',
            ], 500);
        }
    }

    public function show(Campaign $campaign): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if ($user->isCreator() || $user->isStudent()) {
                if (!$campaign->isApproved() || !$campaign->is_active) {
                    return response()->json(['error' => 'Campaign not found or not available'], 404);
                }
            } elseif ($user->isBrand()) {
                if ($campaign->brand_id !== $user->id) {
                    return response()->json(['error' => 'Unauthorized to view this campaign'], 403);
                }
            } elseif (!$user->isAdmin()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $campaign->load(['brand', 'approvedBy', 'bids.user']);

            if ($user) {
                $campaign->setAttribute('has_applied', $campaign->applications()->where('creator_id', $user->id)->exists());
            }

            return response()->json([
                'success' => true,
                'data' => $campaign,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to retrieve campaign: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve campaign',
                'message' => 'An error occurred while retrieving the campaign',
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        Log::info('Update campaign request:', ['request' => $request->all()]);

        try {
            $campaign = Campaign::findOrFail($id);
            Log::info('Campaign found:', ['campaign' => $campaign]);

            // Handle multipart/form-data for PUT/PATCH manually if necessary
            $this->performMultipartParsing($request);

            // Extract and validate update data
            $data = $this->extractUpdateData($request);

            $uploadedFiles = ['image' => null, 'logo' => null, 'attachments' => []];
            $oldFilesToDelete = ['image' => null, 'logo' => null, 'attachments' => []];

            DB::beginTransaction();

            try {
                // Handle file uploads
                $fileResults = $this->handleFilesUpdate($request, $campaign, $data);
                $uploadedFiles = $fileResults['uploaded'];
                $oldFilesToDelete = $fileResults['old'];

                $campaign->update($data);
                DB::commit();

                Log::info('Campaign database update committed', ['id' => $campaign->id]);
            } catch (Exception $e) {
                DB::rollBack();
                Log::error('Campaign update transaction rolled back', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);

                $this->rollbackUploadedFiles($uploadedFiles);

                throw $e;
            }

            // Cleanup old files after successful update
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

    public function destroy(Campaign $campaign): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if (!$user->isAdmin() && (!$user->isBrand() || $campaign->brand_id !== $user->id)) {
                return response()->json(['error' => 'Unauthorized to delete this campaign'], 403);
            }

            if (!$user->isAdmin() && $campaign->isApproved() && $campaign->bids()->count() > 0) {
                return response()->json(['error' => 'Cannot delete approved campaigns with bids'], 422);
            }

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
        } catch (Exception $e) {
            Log::error('Failed to delete campaign: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to delete campaign',
                'message' => 'An error occurred while deleting the campaign',
            ], 500);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            // HOTFIX: Ensure all approved campaigns are active (Consistency with index method)
            Campaign::where('status', 'approved')->where('is_active', false)->update(['is_active' => true]);

            $query = Campaign::query();

            if ($user->isCreator()) {
                $query->approved()->active();
            } elseif ($user->isBrand()) {
                $query->where('brand_id', $user->id);
            } elseif ($user->isAdmin()) {
            } else {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $statistics = [
                'total_campaigns' => $query->count(),
                'pending_campaigns' => (clone $query)->where('status', 'pending')->count(),
                'approved_campaigns' => (clone $query)->where('status', 'approved')->count(),
                'rejected_campaigns' => (clone $query)->where('status', 'rejected')->count(),
                'completed_campaigns' => (clone $query)->where('status', 'completed')->count(),
                'cancelled_campaigns' => (clone $query)->where('status', 'cancelled')->count(),
                'active_campaigns' => (clone $query)->where('is_active', true)->count(),
                'total_bids' => (clone $query)->withCount('bids')->get()->sum('bids_count'),
                'accepted_bids' => (clone $query)->whereHas('bids', function ($q): void {
                    $q->where('status', 'accepted');
                })->count(),
            ];

            $budgetStats = (clone $query)->selectRaw('
                COUNT(*) as total,
                SUM(budget) as total_budget,
                AVG(budget) as avg_budget,
                MIN(budget) as min_budget,
                MAX(budget) as max_budget
            ')->first();

            $statistics['budget'] = [
                'total_budget' => $budgetStats->total_budget ?? 0,
                'average_budget' => $budgetStats->avg_budget ?? 0,
                'min_budget' => $budgetStats->min_budget ?? 0,
                'max_budget' => $budgetStats->max_budget ?? 0,
            ];

            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to retrieve statistics: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve statistics',
                'message' => 'An error occurred while retrieving statistics',
            ], 500);
        }
    }

    public function approve(Campaign $campaign): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if (!$user->isAdmin()) {
                return response()->json(['error' => 'Unauthorized. Admin access required.'], 403);
            }

            if (!$campaign->isPending()) {
                return response()->json(['error' => 'Only pending campaigns can be approved'], 422);
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
            Log::error('Failed to approve campaign: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to approve campaign',
                'message' => 'An error occurred while approving the campaign',
            ], 500);
        }
    }

    public function reject(Request $request, Campaign $campaign): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if (!$user->isAdmin()) {
                return response()->json(['error' => 'Unauthorized. Admin access required.'], 403);
            }

            if (!$campaign->isPending()) {
                return response()->json(['error' => 'Only pending campaigns can be rejected'], 422);
            }

            $request->validate([
                'rejection_reason' => 'nullable|string|max:1000',
            ]);

            $campaign->reject($user->id, $request->rejection_reason);

            AdminNotificationService::notifyAdminOfSystemActivity('campaign_rejected', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'brand_name' => $campaign->brand->name,
                'rejected_by' => $user->name,
                'rejection_reason' => $request->rejection_reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Campaign rejected successfully',
                'data' => $campaign->load(['brand', 'approvedBy']),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Failed to reject campaign: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to reject campaign',
                'message' => 'An error occurred while rejecting the campaign',
            ], 500);
        }
    }

    public function archive(Campaign $campaign): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if (!$user->isAdmin() && ($user->isBrand() && $campaign->brand_id !== $user->id)) {
                return response()->json(['error' => 'Unauthorized to archive this campaign'], 403);
            }

            if ($campaign->isCompleted() || $campaign->isCancelled()) {
                return response()->json(['error' => 'Campaign is already archived'], 422);
            }

            DB::beginTransaction();

            $contracts = Contract::whereHas('offer', function ($query) use ($campaign): void {
                $query->where('campaign_id', $campaign->id);
            })
                ->whereIn('status', ['pending', 'active'])
                ->get();

            $refundedAmount = 0;
            $refundedContracts = [];
            $refundErrors = [];

            foreach ($contracts as $contract) {
                $result = $this->processContractRefund($campaign, $contract);

                if ($result['success']) {
                    if ($result['refunded']) {
                        $refundedAmount += (float) $contract->budget;
                        $refundedContracts[] = $contract->id;
                    }
                } else {
                    $refundErrors[] = [
                        'contract_id' => $contract->id,
                        'error' => $result['error'],
                    ];
                }
            }

            $campaign->update([
                'is_active' => false,
                'status' => 'cancelled',
            ]);

            DB::commit();

            $this->sendArchiveNotifications($campaign, $user, $refundedAmount, $refundedContracts);

            $response = [
                'success' => true,
                'message' => 'Campaign archived successfully',
                'data' => $campaign->load(['brand']),
            ];

            if ($refundedAmount > 0) {
                $response['refund_info'] = [
                    'refunded_amount' => $refundedAmount,
                    'refunded_contracts' => $refundedContracts,
                ];
            }

            if (!empty($refundErrors)) {
                $response['refund_errors'] = $refundErrors;
                $response['message'] = 'Campaign archived with some refund errors. Please check the refund_errors field.';
            }

            return response()->json($response);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to archive campaign: ' . $e->getMessage(), [
                'campaign_id' => $campaign->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to archive campaign',
                'message' => 'An error occurred while archiving the campaign',
            ], 500);
        }
    }

    public function toggleActive(Campaign $campaign): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if (!$user->isBrand() || $campaign->brand_id !== $user->id) {
                return response()->json(['error' => 'Unauthorized to modify this campaign'], 403);
            }

            if (!$campaign->isApproved()) {
                return response()->json(['error' => 'Only approved campaigns can be toggled'], 422);
            }

            $campaign->update(['is_active' => !$campaign->is_active]);

            $status = $campaign->is_active ? 'activated' : 'deactivated';

            AdminNotificationService::notifyAdminOfSystemActivity('campaign_status_toggled', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'brand_name' => $campaign->brand->name,
                'new_status' => $status,
                'toggled_by' => $user->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Campaign {$status} successfully",
                'data' => $campaign->load(['brand']),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to toggle campaign active status: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to toggle campaign status',
                'message' => 'An error occurred while toggling the campaign status',
            ], 500);
        }
    }

    public function toggleFeatured(Campaign $campaign): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if (!$user->isAdmin()) {
                return response()->json(['error' => 'Unauthorized. Admin access required.'], 403);
            }

            $campaign->update(['is_featured' => !$campaign->is_featured]);

            return response()->json([
                'success' => true,
                'message' => $campaign->is_featured ? 'Campaign marked as featured successfully.' : 'Campaign unmarked as featured successfully.',
                'data' => $campaign,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to toggle campaign featured status: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to toggle campaign featured status',
                'message' => 'An error occurred while toggling campaign featured status',
            ], 500);
        }
    }

    public function toggleFavorite(Campaign $campaign): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if (!$user->isCreator() && !$user->isStudent()) {
                return response()->json(['error' => 'Unauthorized. Creator or student access required.'], 403);
            }

            $favorite = CampaignFavorite::where('creator_id', $user->id)
                ->where('campaign_id', $campaign->id)
                ->first();

            if ($favorite) {
                $favorite->delete();
                $isFavorited = false;
                $message = 'Campaign removed from favorites successfully.';
            } else {
                CampaignFavorite::create([
                    'creator_id' => $user->id,
                    'campaign_id' => $campaign->id,
                ]);
                $isFavorited = true;
                $message = 'Campaign added to favorites successfully.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'campaign_id' => $campaign->id,
                    'is_favorited' => $isFavorited,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to toggle campaign favorite status: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to toggle campaign favorite status',
                'message' => 'An error occurred while toggling campaign favorite status',
            ], 500);
        }
    }

    public function getFavorites(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            assert($user instanceof User);

            if (!$user->isCreator() && !$user->isStudent()) {
                return response()->json(['error' => 'Unauthorized. Creator or student access required.'], 403);
            }

            $favorites = CampaignFavorite::where('creator_id', $user->id)
                ->with(['campaign.brand', 'campaign.bids'])
                ->get()
                ->pluck('campaign');

            return response()->json([
                'success' => true,
                'data' => $favorites,
                'count' => $favorites->count(),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to fetch favorite campaigns: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch favorite campaigns',
                'message' => 'An error occurred while fetching favorite campaigns',
            ], 500);
        }
    }

    /**
     * Processes refund for a single contract during campaign archival.
     */
    private function processContractRefund(Campaign $campaign, Contract $contract): array
    {
        try {
            $transaction = Transaction::where('contract_id', $contract->id)
                ->where('status', 'paid')
                ->first();

            if (!$transaction) {
                $transaction = Transaction::where('status', 'paid')
                    ->whereJsonContains('payment_data->contract_id', (string) $contract->id)
                    ->first();
            }

            if ($transaction && $transaction->stripe_payment_intent_id) {
                return $this->processStripeRefund($campaign, $contract, $transaction);
            }

            // No transaction found or no payment intent, just cancel the contract
            $contract->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'Campaign cancelled',
            ]);

            return ['success' => true, 'refunded' => false];
        } catch (Exception $e) {
            Log::error('Error processing refund for contract', [
                'contract_id' => $contract->id,
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Handles Stripe refund and related balance adjustments.
     */
    private function processStripeRefund(Campaign $campaign, Contract $contract, Transaction $transaction): array
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $refund = Refund::create([
                'payment_intent' => $transaction->stripe_payment_intent_id,
                'reason' => 'requested_by_customer',
                'metadata' => [
                    'campaign_id' => (string) $campaign->id,
                    'contract_id' => (string) $contract->id,
                    'reason' => 'Campaign cancelled',
                ],
            ]);

            $transaction->update([
                'status' => 'refunded',
                'payment_data' => array_merge(
                    $transaction->payment_data ?? [],
                    [
                        'refund_id' => $refund->id,
                        'refunded_at' => now()->toISOString(),
                        'refund_amount' => $refund->amount / 100,
                    ]
                ),
            ]);

            $this->handleJobPaymentAndBalanceRefund($contract);

            $contract->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'Campaign cancelled',
            ]);

            Log::info('Contract refunded successfully', [
                'contract_id' => $contract->id,
                'campaign_id' => $campaign->id,
                'refund_id' => $refund->id,
                'amount' => $contract->budget,
            ]);

            return ['success' => true, 'refunded' => true];
        } catch (ApiErrorException $e) {
            Log::error('Failed to create Stripe refund', [
                'contract_id' => $contract->id,
                'payment_intent_id' => $transaction->stripe_payment_intent_id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Handles JobPayment refund and CreatorBalance adjustments.
     */
    private function handleJobPaymentAndBalanceRefund(Contract $contract): void
    {
        $jobPayment = JobPayment::where('contract_id', $contract->id)
            ->where('status', '!=', 'refunded')
            ->first();

        if ($jobPayment) {
            if ('completed' === $jobPayment->status) {
                $balance = CreatorBalance::where('creator_id', $jobPayment->creator_id)->first();
                if ($balance) {
                    $creatorAmount = (float) $jobPayment->creator_amount;
                    if ($balance->available_balance >= $creatorAmount) {
                        $balance->decrement('available_balance', $creatorAmount);
                    }
                    if ($balance->pending_balance >= $creatorAmount) {
                        $balance->decrement('pending_balance', $creatorAmount);
                    }
                    $balance->decrement('total_earned', $creatorAmount);
                }
            }
            $jobPayment->refund('Campaign cancelled');
        }
    }

    /**
     * Sends notifications after campaign archival.
     */
    private function sendArchiveNotifications(Campaign $campaign, User $user, float $refundedAmount, array $refundedContracts): void
    {
        AdminNotificationService::notifyAdminOfSystemActivity('campaign_archived', [
            'campaign_id' => $campaign->id,
            'campaign_title' => $campaign->title,
            'brand_name' => $campaign->brand->name,
            'archived_by' => $user->name,
            'archived_by_role' => $user->role,
            'refunded_amount' => $refundedAmount,
            'refunded_contracts_count' => count($refundedContracts),
        ]);

        if ($refundedAmount > 0) {
            try {
                $brand = $campaign->brand;
                if ($brand) {
                    Notification::create([
                        'user_id' => $brand->id,
                        'type' => 'campaign_cancelled',
                        'title' => 'Campanha Cancelada - Reembolso Processado',
                        'message' => "Sua campanha '{$campaign->title}' foi cancelada. Um reembolso de R$ " . number_format($refundedAmount, 2, ',', '.') . ' foi processado para ' . count($refundedContracts) . ' contrato(s).',
                        'data' => [
                            'campaign_id' => $campaign->id,
                            'campaign_title' => $campaign->title,
                            'refunded_amount' => $refundedAmount,
                            'refunded_contracts_count' => count($refundedContracts),
                        ],
                    ]);

                    Log::info('Brand notified of campaign cancellation and refund', [
                        'brand_id' => $brand->id,
                        'campaign_id' => $campaign->id,
                        'refunded_amount' => $refundedAmount,
                    ]);
                }
            } catch (Exception $e) {
                Log::error('Failed to notify brand of campaign cancellation', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function handleMultipartUpdate(Request $request): void
    {
        $contentType = $request->header('Content-Type');
        $isMultipart = str_contains($contentType, 'multipart/form-data');

        if ($isMultipart && empty($request->all()) && !empty($request->getContent())) {
            Log::info('Multipart request detected but empty, attempting manual parsing');
            $parsedData = $this->parseMultipartData($request);

            Log::info('Manually parsed data:', [
                'fields_count' => count($parsedData),
                'fields' => array_keys($parsedData),
            ]);

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

            Log::info('After manual parsing:', [
                'request_all_count' => count($request->all()),
                'has_title' => $request->has('title'),
                'has_files' => $request->hasFile('logo') || $request->hasFile('image') || $request->hasFile('attach_file'),
            ]);
        }
    }

    private function prepareCampaignUpdateData(Request $request): array
    {
        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:5000',
            'budget' => 'sometimes|nullable|numeric|min:0|max:999999.99',
            'requirements' => 'sometimes|nullable|string|max:5000',
            'remuneration_type' => 'sometimes|nullable|in:paga',
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

        $fields = [
            'title',
            'description',
            'budget',
            'requirements',
            'remuneration_type',
            'target_states',
            'target_genders',
            'target_creator_types',
            'min_age',
            'max_age',
            'category',
            'campaign_type',
            'deadline',
            'status',
        ];

        $data = [];
        foreach ($fields as $field) {
            $value = $request->input($field);
            if (null !== $value) {
                $data[$field] = $value;
            }
        }

        if (empty($data) && !empty($request->all())) {
            $data = $request->only($fields);
        }

        $data = array_filter($data, fn($v) => !is_null($v));

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

    private function processUpdateFiles(Request $request, Campaign $campaign, array &$data, array &$uploadedFiles): array
    {
        $oldFilesToDelete = [
            'image' => null,
            'logo' => null,
            'attachments' => [],
        ];

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
                $oldFilesToDelete['attachments'] = is_array($campaign->attach_file)
                    ? $campaign->attach_file
                    : [$campaign->attach_file];
            }
            $data['attach_file'] = $attachmentUrls;
        }

        return $oldFilesToDelete;
    }

    private function cleanupFailedUploads(array $uploadedFiles, int $campaignId): void
    {
        Log::error('Campaign update failed, cleaning up uploads', ['campaign_id' => $campaignId]);

        if ($uploadedFiles['image']) {
            $this->deleteFile($uploadedFiles['image']);
        }
        if ($uploadedFiles['logo']) {
            $this->deleteFile($uploadedFiles['logo']);
        }
        foreach ($uploadedFiles['attachments'] as $uploadedAttachment) {
            $this->deleteFile($uploadedAttachment);
        }
    }

    private function deleteOldFiles(array $oldFilesToDelete, int $campaignId): void
    {
        if ($oldFilesToDelete['image']) {
            $this->deleteFile($oldFilesToDelete['image']);
            Log::info('Deleted old image', ['campaign_id' => $campaignId]);
        }
        if ($oldFilesToDelete['logo']) {
            $this->deleteFile($oldFilesToDelete['logo']);
            Log::info('Deleted old logo', ['campaign_id' => $campaignId]);
        }
        foreach ($oldFilesToDelete['attachments'] as $oldAttachment) {
            $this->deleteFile($oldAttachment);
        }
    }

    private function uploadFile($file, string $path): string
    {
        return FileUploadHelper::upload($file, $path) ?? Storage::url($file->store($path, 'public'));
    }

    private function deleteFile(?string $fileUrl): void
    {
        if (!$fileUrl) {
            return;
        }
        FileUploadHelper::delete($fileUrl);
    }

    private function parseMultipartData(Request $request): array
    {
        $rawContent = $request->getContent();
        $contentType = $request->header('Content-Type');

        if (!preg_match('/boundary=(.+)$/', $contentType, $matches)) {
            return [];
        }

        $boundary = '--' . trim($matches[1]);
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

    /**
     * Parse multipart data for PUT/PATCH requests when Laravel fails to do so automatically.
     */
    private function performMultipartParsing(Request $request): void
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

    /**
     * Extract and validate update data from the request.
     */
    private function extractUpdateData(Request $request): array
    {
        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:5000',
            'budget' => 'sometimes|nullable|numeric|min:0|max:999999.99',
            'requirements' => 'sometimes|nullable|string|max:5000',
            'remuneration_type' => 'sometimes|nullable|in:paga',
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

        $fields = [
            'title',
            'description',
            'budget',
            'requirements',
            'remuneration_type',
            'target_states',
            'target_genders',
            'target_creator_types',
            'min_age',
            'max_age',
            'category',
            'campaign_type',
            'deadline',
            'status',
        ];

        $data = [];
        foreach ($fields as $field) {
            $value = $request->input($field);
            if (null !== $value) {
                $data[$field] = $value;
            }
        }

        if (empty($data) && !empty($request->all())) {
            $data = $request->only($fields);
        }

        $data = array_filter($data, fn($v) => !is_null($v));

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

    /**
     * Handle file uploads and keep track of files for cleanup or rollback.
     */
    private function handleFilesUpdate(Request $request, Campaign $campaign, array &$data): array
    {
        $uploadedFiles = ['image' => null, 'logo' => null, 'attachments' => []];
        $oldFilesToDelete = ['image' => null, 'logo' => null, 'attachments' => []];

        if ($request->hasFile('image')) {
            $newImageUrl = $this->uploadFile($request->file('image'), 'campaigns/images');
            if (!$newImageUrl) {
                throw new Exception('Failed to upload campaign image');
            }
            $uploadedFiles['image'] = $newImageUrl;
            $oldFilesToDelete['image'] = $campaign->image_url;
            $data['image_url'] = $newImageUrl;
        }

        if ($request->hasFile('logo')) {
            $newLogo = $this->uploadFile($request->file('logo'), 'campaigns/logos');
            if (!$newLogo) {
                throw new Exception('Failed to upload campaign logo');
            }
            $uploadedFiles['logo'] = $newLogo;
            $oldFilesToDelete['logo'] = $campaign->logo;
            $data['logo'] = $newLogo;
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
                $oldFilesToDelete['attachments'] = is_array($campaign->attach_file)
                    ? $campaign->attach_file
                    : [$campaign->attach_file];
            }
            $data['attach_file'] = $attachmentUrls;
        }

        return ['uploaded' => $uploadedFiles, 'old' => $oldFilesToDelete];
    }

    /**
     * Delete newly uploaded files if the transaction failed.
     */
    private function rollbackUploadedFiles(array $uploadedFiles): void
    {
        if ($uploadedFiles['image']) {
            $this->deleteFile($uploadedFiles['image']);
            Log::info('Rolled back: deleted uploaded image', ['file' => $uploadedFiles['image']]);
        }
        if ($uploadedFiles['logo']) {
            $this->deleteFile($uploadedFiles['logo']);
            Log::info('Rolled back: deleted uploaded logo', ['file' => $uploadedFiles['logo']]);
        }
        foreach ($uploadedFiles['attachments'] as $uploadedAttachment) {
            $this->deleteFile($uploadedAttachment);
        }
        if (!empty($uploadedFiles['attachments'])) {
            Log::info('Rolled back: deleted uploaded attachments', ['count' => count($uploadedFiles['attachments'])]);
        }
    }

    /**
     * Delete old files after a successful update.
     */
    private function cleanupOldFiles(array $oldFilesToDelete, int $campaignId): void
    {
        if ($oldFilesToDelete['image']) {
            $this->deleteFile($oldFilesToDelete['image']);
            Log::info('Deleted old campaign image after successful update', ['campaign_id' => $campaignId]);
        }
        if ($oldFilesToDelete['logo']) {
            $this->deleteFile($oldFilesToDelete['logo']);
            Log::info('Deleted old campaign logo after successful update', ['campaign_id' => $campaignId]);
        }
        foreach ($oldFilesToDelete['attachments'] as $oldAttachment) {
            $this->deleteFile($oldAttachment);
        }
        if (!empty($oldFilesToDelete['attachments'])) {
            Log::info('Deleted old campaign attachments after successful update', ['campaign_id' => $campaignId]);
        }
    }
}

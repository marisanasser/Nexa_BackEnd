<?php

namespace App\Http\Controllers\Campaign;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaign\StoreCampaignRequest;
use App\Http\Requests\Campaign\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\CampaignFavorite;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\error;

class CampaignController extends Controller
{
    /**
     * Display a listing of campaigns with role-based filtering.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Campaign::with(['brand', 'bids']);

            error_log("Request" . json_encode($request));

            // Apply role-based filtering
            if ($user->isCreator() || $user->isStudent()) {
                // Creators and students see only approved and active campaigns
                $query->approved()->active();
            } elseif ($user->isBrand()) {
                // Brands see only their own campaigns
                $query->where('brand_id', $user->id);
            } elseif ($user->isAdmin()) {
                // Admin sees all campaigns
                // No additional filters needed
            } else {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Apply additional filters
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

            // Apply creator filters for creators
            if ($user->isCreator()) {
                $creator = $user;
                
                // Filter by creator type
                if ($creator->creator_type) {
                    $query->whereJsonContains('target_creator_types', $creator->creator_type);
                }
                
                // Filter by age range
                if ($creator->birth_date) {
                    $age = $creator->age;
                    $query->where(function($q) use ($age) {
                        $q->whereNull('min_age')
                          ->orWhere('min_age', '<=', $age);
                    })->where(function($q) use ($age) {
                        $q->whereNull('max_age')
                          ->orWhere('max_age', '>=', $age);
                    });
                }
                
                // Filter by gender
                if ($creator->gender) {
                    $query->where(function($q) use ($creator) {
                        $q->whereNull('target_genders')
                          ->orWhereJsonLength('target_genders', 0)
                          ->orWhereJsonContains('target_genders', $creator->gender);
                    });
                }
                
                // Filter by social media requirements
                // Only show campaigns that creators can qualify for based on their social media presence
                if ($creator->creator_type === 'influencer' || $creator->creator_type === 'both') {
                    // For influencers and both types, they must have Instagram to see campaigns
                    if (!$creator->instagram_handle) {
                        // If influencer/both doesn't have Instagram, don't show any campaigns
                        $query->whereRaw('1 = 0'); // This will return no results
                    }
                }
                // UGC creators can see all campaigns regardless of social media presence
            }

            // Search functionality
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('requirements', 'like', "%{$search}%");
                });
            }

            // Sorting - Featured campaigns first, then by specified sort
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy('is_featured', 'desc') // Featured campaigns first
                  ->orderBy($sortBy, $sortOrder);

            $perPage = min($request->get('per_page', 15), 100); // Max 100 items per page
            $campaigns = $query->paginate($perPage);

            // Add favorite status for creators
            if ($user->isCreator()) {
                // The is_favorited attribute is now automatically handled by the model
                // No need to manually set it here
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
                ]
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
              ->header('Pragma', 'no-cache')
              ->header('Expires', '0');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve campaigns: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve campaigns',
                'message' => 'An error occurred while retrieving campaigns'
            ], 500);
        }
    }

    /**
     * Get campaigns with advanced filtering (alias for index).
     */
    public function getCampaigns(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    /**
     * Get all campaigns without pagination.
     */
    public function getAllCampaigns(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Campaign::with(['brand', 'approvedBy', 'bids']);

            // Apply role-based filtering
            if ($user->isCreator() || $user->isStudent()) {
                $query->approved()->active();
            } elseif ($user->isBrand()) {
                $query->where('brand_id', $user->id);
            } elseif ($user->isAdmin()) {
                // Admin sees all campaigns
            } else {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Apply filters
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
                'count' => $campaigns->count()
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
              ->header('Pragma', 'no-cache')
              ->header('Expires', '0');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve all campaigns: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve campaigns',
                'message' => 'An error occurred while retrieving campaigns'
            ], 500);
        }
    }

    /**
     * Get pending campaigns (Admin only).
     */
    public function getPendingCampaigns(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isAdmin()) {
                return response()->json(['error' => 'Unauthorized. Admin access required.'], 403);
            }

            $query = Campaign::with(['brand', 'bids'])->pending();

            // Apply additional filters
            if ($request->has('category')) {
                $query->byCategory($request->category);
            }

            if ($request->has('campaign_type')) {
                $query->byType($request->campaign_type);
            }

            // Search functionality
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
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
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve pending campaigns: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve pending campaigns',
                'message' => 'An error occurred while retrieving pending campaigns'
            ], 500);
        }
    }

    /**
     * Get campaigns by specific user.
     */
    public function getUserCampaigns(User $user, Request $request): JsonResponse
    {
        try {
            $authUser = auth()->user();

            // Check authorization
            if (!$authUser->isAdmin() && $authUser->id !== $user->id) {
                return response()->json(['error' => 'Unauthorized to view other user campaigns'], 403);
            }

            $query = Campaign::with(['brand', 'approvedBy', 'bids'])
                ->where('brand_id', $user->id);

            // Apply filters
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
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve user campaigns: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve user campaigns',
                'message' => 'An error occurred while retrieving user campaigns'
            ], 500);
        }
    }

    /**
     * Get campaigns by status.
     */

    public function getCampaignsByStatus(string $status, Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // Validate status
            $validStatuses = ['pending', 'approved', 'rejected', 'completed', 'cancelled'];
            if (!in_array($status, $validStatuses)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid status',
                    'message' => 'Status must be one of: ' . implode(', ', $validStatuses)
                ], 400);
            }

            // Eager load only valid relationships
            $query = Campaign::with(['brand', 'bids']);

            // Role-based access control
            if ($user->isCreator() || $user->isStudent()) {
                if ($status !== 'approved') {
                    return response()->json([
                        'success' => false,
                        'error' => 'Unauthorized',
                        'message' => 'Creators and students can only view approved campaigns.'
                    ], 403);
                }

                $query->approved();
            } elseif ($user->isBrand()) {
                $query->where('brand_id', $user->id)
                    ->where('status', $status);
            } elseif ($user->isAdmin()) {
                $query->where('status', $status);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'User role not authorized.'
                ], 403);
            }

            // Filtering
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

            // Search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('requirements', 'like', "%{$search}%");
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = min($request->get('per_page', 15), 100);
            $campaigns = $query->paginate($perPage);

            // Add favorite status for creators
            if ($user->isCreator()) {
                // The is_favorited attribute is now automatically handled by the model
                // No need to manually set it here
            }

            // Log campaigns data for debugging
            Log::info('Campaigns retrieved', [
                'status' => $status,
                'user_role' => $user->role,
                'total_campaigns' => $campaigns->total(),
                'current_page' => $campaigns->currentPage()
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
              ->header('Expires', '0');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve campaigns: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => $e->getMessage(), // Consider hiding this in production
            ], 500);
        }
    }

    /**
     * Store a newly created campaign.
     */
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isBrand()) {
                return response()->json(['error' => 'Only brands can create campaigns'], 403);
            }

            // Debug: Log the request data
            Log::info('Campaign creation request data:', $request->all());
            Log::info('Deadline received:', [
                'deadline_raw' => $request->input('deadline'),
                'deadline_type' => gettype($request->input('deadline')),
                'all_data' => $request->all()
            ]);
            
            $data = $request->validated();
            $data['brand_id'] = $user->id;
            $data['status'] = $data['status'] ?? 'pending';
            $data['is_active'] = true;
            
            // Ensure target_states is always an array
            if (!isset($data['target_states']) || !is_array($data['target_states'])) {
                $data['target_states'] = [];
            }

            // Validate age range
            if (isset($data['min_age']) && isset($data['max_age']) && $data['min_age'] > $data['max_age']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid age range',
                    'message' => 'Minimum age cannot be greater than maximum age'
                ], 422);
            }

            // Ensure target_genders is always an array
            if (!isset($data['target_genders']) || !is_array($data['target_genders'])) {
                $data['target_genders'] = [];
            }

            // Ensure target_creator_types is always an array and has at least one value
            if (!isset($data['target_creator_types']) || !is_array($data['target_creator_types']) || empty($data['target_creator_types'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Creator type required',
                    'message' => 'At least one creator type must be selected'
                ], 422);
            }

            // Handle file uploads
            if ($request->hasFile('image')) {
                $data['image_url'] = $this->uploadFile($request->file('image'), 'campaigns/images');
            }

            if ($request->hasFile('logo')) {
                $data['logo'] = $this->uploadFile($request->file('logo'), 'campaigns/logos');
            }

            // Handle multiple attachments
            if ($request->hasFile('attach_file')) {
                $attachmentFiles = $request->file('attach_file');
                // If single file, convert to array
                if (!is_array($attachmentFiles)) {
                    $attachmentFiles = [$attachmentFiles];
                }
                
                $attachmentUrls = [];
                foreach ($attachmentFiles as $file) {
                    $attachmentUrls[] = $this->uploadFile($file, 'campaigns/attachments');
                }
                
                // Store as array - Laravel will auto-encode to JSON due to cast
                $data['attach_file'] = $attachmentUrls;
            }
            
            $campaign = Campaign::create($data);

            // Notify admin of new campaign creation
            \App\Services\NotificationService::notifyAdminOfNewCampaign($campaign);

            // Send email notification to brand about successful campaign creation
            \App\Services\NotificationService::notifyBrandOfCampaignCreated($campaign);

            // Note: Creators will be notified when admin approves the campaign
            // This prevents confusion where creators get notifications for campaigns they can't see

            return response()->json([
                'success' => true,
                'message' => 'Campaign created successfully and is pending approval',
                'data' => $campaign->load(['brand', 'bids'])
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create campaign: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to create campaign',
                'message' => 'An error occurred while creating the campaign'
            ], 500);
        }
    }

    /**
     * Display the specified campaign.
     */
    public function show(Campaign $campaign): JsonResponse
    {
        try {
            $user = auth()->user();

            // Check authorization
            if ($user->isCreator()) {
                // Creators can only see approved and active campaigns
                if (!$campaign->isApproved() || !$campaign->is_active) {
                    return response()->json(['error' => 'Campaign not found or not available'], 404);
                }
            } elseif ($user->isBrand()) {
                // Brands can only see their own campaigns
                if ($campaign->brand_id !== $user->id) {
                    return response()->json(['error' => 'Unauthorized to view this campaign'], 403);
                }
            } elseif (!$user->isAdmin()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $campaign->load(['brand', 'approvedBy', 'bids.user']);

            return response()->json([
                'success' => true,
                'data' => $campaign
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve campaign: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve campaign',
                'message' => 'An error occurred while retrieving the campaign'
            ], 500);
        }
    }

    /**
     * Update the specified campaign.
     */
    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        try {
            $user = auth()->user();

            // Check authorization - allow admin or brand owner
            if (!$user->isAdmin() && (!$user->isBrand() || $campaign->brand_id !== $user->id)) {
                return response()->json(['error' => 'Unauthorized to update this campaign'], 403);
            }

            // Check if campaign can be updated
            if ($campaign->isApproved()) {
                return response()->json(['error' => 'Cannot update approved campaigns'], 422);
            }

            $data = $request->validated();

            // Handle file uploads
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($campaign->image_url) {
                    $this->deleteFile($campaign->image_url);
                }
                $data['image_url'] = $this->uploadFile($request->file('image'), 'campaigns/images');
            }

            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                if ($campaign->logo) {
                    $this->deleteFile($campaign->logo);
                }
                $data['logo'] = $this->uploadFile($request->file('logo'), 'campaigns/logos');
            }

            // Handle multiple attachments
            if ($request->hasFile('attach_file')) {
                // Delete old attachments if they exist
                if ($campaign->attach_file) {
                    $oldAttachments = is_array($campaign->attach_file) 
                        ? $campaign->attach_file 
                        : [$campaign->attach_file];
                    foreach ($oldAttachments as $oldAttachment) {
                        $this->deleteFile($oldAttachment);
                    }
                }
                
                $attachmentFiles = $request->file('attach_file');
                // If single file, convert to array
                if (!is_array($attachmentFiles)) {
                    $attachmentFiles = [$attachmentFiles];
                }
                
                $attachmentUrls = [];
                foreach ($attachmentFiles as $file) {
                    $attachmentUrls[] = $this->uploadFile($file, 'campaigns/attachments');
                }
                
                // Store as array - Laravel will auto-encode to JSON due to cast
                $data['attach_file'] = $attachmentUrls;
            }

            $campaign->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Campaign updated successfully',
                'data' => $campaign->load(['brand', 'bids'])
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update campaign: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to update campaign',
                'message' => 'An error occurred while updating the campaign'
            ], 500);
        }
    }

    /**
     * Remove the specified campaign.
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        try {
            $user = auth()->user();

            // Check authorization - allow admin or brand owner
            if (!$user->isAdmin() && (!$user->isBrand() || $campaign->brand_id !== $user->id)) {
                return response()->json(['error' => 'Unauthorized to delete this campaign'], 403);
            }

            // Check if campaign can be deleted (only for non-admin users)
            if (!$user->isAdmin() && $campaign->isApproved() && $campaign->bids()->count() > 0) {
                return response()->json(['error' => 'Cannot delete approved campaigns with bids'], 422);
            }

            // Delete associated files
            if ($campaign->image_url) {
                $this->deleteFile($campaign->image_url);
            }
            if ($campaign->logo) {
                $this->deleteFile($campaign->logo);
            }
            // Delete all attachments if they exist
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
                'message' => 'Campaign deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete campaign: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete campaign',
                'message' => 'An error occurred while deleting the campaign'
            ], 500);
        }
    }

    /**
     * Get campaign statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Campaign::query();

            // Apply role-based filtering
            if ($user->isCreator()) {
                // Creators see statistics for approved campaigns they can bid on
                $query->approved()->active();
            } elseif ($user->isBrand()) {
                // Brands see statistics for their own campaigns
                $query->where('brand_id', $user->id);
            } elseif ($user->isAdmin()) {
                // Admin sees all statistics
                // No additional filters needed
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
                'accepted_bids' => (clone $query)->whereHas('bids', function ($q) {
                    $q->where('status', 'accepted');
                })->count(),
            ];

            // Add budget statistics
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
                'data' => $statistics
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve statistics',
                'message' => 'An error occurred while retrieving statistics'
            ], 500);
        }
    }

    /**
     * Approve a campaign (Admin only).
     */
    public function approve(Campaign $campaign): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isAdmin()) {
                return response()->json(['error' => 'Unauthorized. Admin access required.'], 403);
            }

            if (!$campaign->isPending()) {
                return response()->json(['error' => 'Only pending campaigns can be approved'], 422);
            }

            $campaign->approve($user->id);

            // Notify admin of campaign approval
            \App\Services\NotificationService::notifyAdminOfSystemActivity('campaign_approved', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'brand_name' => $campaign->brand->name,
                'approved_by' => $user->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Campaign approved successfully',
                'data' => $campaign->load(['brand', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to approve campaign: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to approve campaign',
                'message' => 'An error occurred while approving the campaign'
            ], 500);
        }
    }

    /**
     * Reject a campaign (Admin only).
     */
    public function reject(Request $request, Campaign $campaign): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isAdmin()) {
                return response()->json(['error' => 'Unauthorized. Admin access required.'], 403);
            }

            if (!$campaign->isPending()) {
                return response()->json(['error' => 'Only pending campaigns can be rejected'], 422);
            }

            $request->validate([
                'rejection_reason' => 'nullable|string|max:1000'
            ]);

            $campaign->reject($user->id, $request->rejection_reason);

            // Notify admin of campaign rejection
            \App\Services\NotificationService::notifyAdminOfSystemActivity('campaign_rejected', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'brand_name' => $campaign->brand->name,
                'rejected_by' => $user->name,
                'rejection_reason' => $request->rejection_reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Campaign rejected successfully',
                'data' => $campaign->load(['brand', 'approvedBy'])
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to reject campaign: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to reject campaign',
                'message' => 'An error occurred while rejecting the campaign'
            ], 500);
        }
    }

    /**
     * Archive a campaign.
     */
    public function archive(Campaign $campaign): JsonResponse
    {
        try {
            $user = auth()->user();

            // Check authorization
            if (!$user->isAdmin() && ($user->isBrand() && $campaign->brand_id !== $user->id)) {
                return response()->json(['error' => 'Unauthorized to archive this campaign'], 403);
            }

            if ($campaign->isCompleted() || $campaign->isCancelled()) {
                return response()->json(['error' => 'Campaign is already archived'], 422);
            }

            $campaign->update([
                'is_active' => false,
                'status' => 'cancelled'
            ]);

            // Notify admin of campaign archiving
            \App\Services\NotificationService::notifyAdminOfSystemActivity('campaign_archived', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'brand_name' => $campaign->brand->name,
                'archived_by' => $user->name,
                'archived_by_role' => $user->role,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Campaign archived successfully',
                'data' => $campaign->load(['brand'])
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to archive campaign: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to archive campaign',
                'message' => 'An error occurred while archiving the campaign'
            ], 500);
        }
    }

    /**
     * Toggle active status of a campaign (Brand only).
     */
    public function toggleActive(Campaign $campaign): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isBrand() || $campaign->brand_id !== $user->id) {
                return response()->json(['error' => 'Unauthorized to modify this campaign'], 403);
            }

            if (!$campaign->isApproved()) {
                return response()->json(['error' => 'Only approved campaigns can be toggled'], 422);
            }

            $campaign->update(['is_active' => !$campaign->is_active]);

            $status = $campaign->is_active ? 'activated' : 'deactivated';

            // Notify admin of campaign status toggle
            \App\Services\NotificationService::notifyAdminOfSystemActivity('campaign_status_toggled', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'brand_name' => $campaign->brand->name,
                'new_status' => $status,
                'toggled_by' => $user->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Campaign {$status} successfully",
                'data' => $campaign->load(['brand'])
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to toggle campaign active status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to toggle campaign status',
                'message' => 'An error occurred while toggling the campaign status'
            ], 500);
        }
    }

    /**
     * Toggle featured status of a campaign (Admin only).
     */
    public function toggleFeatured(Campaign $campaign): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isAdmin()) {
                return response()->json(['error' => 'Unauthorized. Admin access required.'], 403);
            }

            $campaign->update(['is_featured' => !$campaign->is_featured]);

            return response()->json([
                'success' => true,
                'message' => $campaign->is_featured ? 'Campaign marked as featured successfully.' : 'Campaign unmarked as featured successfully.',
                'data' => $campaign
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to toggle campaign featured status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to toggle campaign featured status',
                'message' => 'An error occurred while toggling campaign featured status'
            ], 500);
        }
    }

    /**
     * Toggle favorite status of a campaign (Creator and Student).
     */
    public function toggleFavorite(Campaign $campaign): JsonResponse
    {
        try {
            $user = auth()->user();

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
                    'is_favorited' => $isFavorited
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to toggle campaign favorite status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to toggle campaign favorite status',
                'message' => 'An error occurred while toggling campaign favorite status'
            ], 500);
        }
    }

    /**
     * Get user's favorite campaigns (Creator and Student).
     */
    public function getFavorites(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

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
                'count' => $favorites->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch favorite campaigns: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch favorite campaigns',
                'message' => 'An error occurred while fetching favorite campaigns'
            ], 500);
        }
    }

    /**
     * Upload a file and return the path.
     */
    private function uploadFile($file, string $path): string
    {
        $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $filePath = $file->storeAs($path, $fileName, 'public');
        return Storage::url($filePath);
    }

    /**
     * Delete a file from storage.
     */
    private function deleteFile(?string $fileUrl): void
    {
        if (!$fileUrl) return;

        try {
            $path = str_replace('/storage/', '', $fileUrl);
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete file: ' . $fileUrl . ' - ' . $e->getMessage());
        }
    }
}

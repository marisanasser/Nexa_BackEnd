<?php

namespace App\Http\Controllers\Campaign;

use App\Domain\Notification\Services\AdminNotificationService;
use App\Http\Controllers\Base\Controller;
use App\Http\Requests\Campaign\StoreCampaignRequest;
use App\Models\Campaign\Bid;
use App\Models\Campaign\Campaign;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BidController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $query = Bid::with(['campaign', 'user']);

        if ($user->isCreator()) {

            $query->where('user_id', $user->id);
        } elseif ($user->isBrand()) {

            $query->whereHas('campaign', function ($q) use ($user) {
                $q->where('brand_id', $user->id);
            });
        } elseif ($user->isAdmin()) {

        } else {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('campaign_id')) {
            $query->where('campaign_id', $request->campaign_id);
        }

        if ($request->has('bid_amount_min')) {
            $query->where('bid_amount', '>=', $request->bid_amount_min);
        }

        if ($request->has('bid_amount_max')) {
            $query->where('bid_amount', '<=', $request->bid_amount_max);
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $bids = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $bids,
            'message' => 'Bids retrieved successfully',
        ]);
    }

    public function create() {}

    public function store(StoreCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $validated = $request->validated();
        $validated['campaign_id'] = $campaign->id;
        $validated['user_id'] = auth()->id();

        $bid = Bid::create($validated);

        AdminNotificationService::notifyAdminOfNewBid($bid);

        return response()->json([
            'success' => true,
            'data' => $bid->load(['campaign', 'user']),
            'message' => 'Bid submitted successfully',
        ], 201);
    }

    public function show(Bid $bid): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if ($user->isCreator() && $bid->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($user->isBrand() && $bid->campaign->brand_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $bid->load(['campaign', 'user']);

        return response()->json([
            'success' => true,
            'data' => $bid,
            'message' => 'Bid retrieved successfully',
        ]);
    }

    public function edit(string $id) {}

    public function update(Request $request, Bid $bid): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user->isCreator() || $bid->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $bid->isPending()) {
            return response()->json(['error' => 'Only pending bids can be updated'], 400);
        }

        if (! $bid->campaign->canReceiveBids()) {
            return response()->json(['error' => 'Campaign no longer accepts bids'], 400);
        }

        $validated = $request->validate([
            'bid_amount' => ['sometimes', 'numeric', 'min:1', 'max:999999.99'],
            'proposal' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'portfolio_links' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'estimated_delivery_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $bid->update($validated);

        return response()->json([
            'success' => true,
            'data' => $bid->load(['campaign', 'user']),
            'message' => 'Bid updated successfully',
        ]);
    }

    public function destroy(Bid $bid): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user->isCreator() || $bid->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $bid->isPending()) {
            return response()->json(['error' => 'Only pending bids can be deleted'], 400);
        }

        $bid->delete();

        return response()->json([
            'success' => true,
            'message' => 'Bid deleted successfully',
        ]);
    }

    public function accept(Bid $bid): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user->isBrand() || $bid->campaign->brand_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $bid->isPending()) {
            return response()->json(['error' => 'Only pending bids can be accepted'], 400);
        }

        if ($bid->campaign->hasAcceptedBid()) {
            return response()->json(['error' => 'Campaign already has an accepted bid'], 400);
        }

        $bid->accept();

        AdminNotificationService::notifyAdminOfSystemActivity('bid_accepted', [
            'bid_id' => $bid->id,
            'campaign_id' => $bid->campaign_id,
            'campaign_title' => $bid->campaign->title,
            'creator_name' => $bid->user->name,
            'brand_name' => $bid->campaign->brand->name,
            'bid_amount' => $bid->bid_amount,
            'accepted_by' => $user->name,
        ]);

        return response()->json([
            'success' => true,
            'data' => $bid->load(['campaign', 'user']),
            'message' => 'Bid accepted successfully',
        ]);
    }

    public function reject(Request $request, Bid $bid): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user->isBrand() || $bid->campaign->brand_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $bid->isPending()) {
            return response()->json(['error' => 'Only pending bids can be rejected'], 400);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $bid->reject($validated['reason'] ?? null);

        AdminNotificationService::notifyAdminOfSystemActivity('bid_rejected', [
            'bid_id' => $bid->id,
            'campaign_id' => $bid->campaign_id,
            'campaign_title' => $bid->campaign->title,
            'creator_name' => $bid->user->name,
            'brand_name' => $bid->campaign->brand->name,
            'bid_amount' => $bid->bid_amount,
            'rejected_by' => $user->name,
            'rejection_reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $bid->load(['campaign', 'user']),
            'message' => 'Bid rejected successfully',
        ]);
    }

    public function withdraw(Bid $bid): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user->isCreator() || $bid->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $bid->isPending()) {
            return response()->json(['error' => 'Only pending bids can be withdrawn'], 400);
        }

        $bid->withdraw();

        AdminNotificationService::notifyAdminOfSystemActivity('bid_withdrawn', [
            'bid_id' => $bid->id,
            'campaign_id' => $bid->campaign_id,
            'campaign_title' => $bid->campaign->title,
            'creator_name' => $bid->user->name,
            'brand_name' => $bid->campaign->brand->name,
            'bid_amount' => $bid->bid_amount,
            'withdrawn_by' => $user->name,
        ]);

        return response()->json([
            'success' => true,
            'data' => $bid->load(['campaign', 'user']),
            'message' => 'Bid withdrawn successfully',
        ]);
    }

    public function campaignBids(Request $request, Campaign $campaign): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if ($user->isBrand() && $campaign->brand_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($user->isCreator() && ! $campaign->isApproved()) {
            return response()->json(['error' => 'Campaign not found'], 404);
        }

        $query = $campaign->bids()->with(['user']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('bid_amount_min')) {
            $query->where('bid_amount', '>=', $request->bid_amount_min);
        }

        if ($request->has('bid_amount_max')) {
            $query->where('bid_amount', '<=', $request->bid_amount_max);
        }

        $sortBy = $request->get('sort_by', 'bid_amount');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $bids = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $bids,
            'message' => 'Campaign bids retrieved successfully',
        ]);
    }
}

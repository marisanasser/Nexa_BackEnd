<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Services;

use App\Models\Campaign\Campaign;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * CampaignSearchService handles campaign searching and filtering.
 *
 * Responsibilities:
 * - Searching campaigns with filters
 * - Featured campaigns
 * - Trending campaigns
 * - Campaign recommendations
 */
class CampaignSearchService
{
    /**
     * Search campaigns with filters.
     */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Campaign::query()
            ->with(['user', 'applications'])
            ->where('status', 'active')
            ->where('is_active', true)
        ;

        // Apply filters
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['budget_min'])) {
            $query->where('budget', '>=', $filters['budget_min']);
        }

        if (!empty($filters['budget_max'])) {
            $query->where('budget', '<=', $filters['budget_max']);
        }

        if (!empty($filters['location'])) {
            $query->where('location', 'like', "%{$filters['location']}%");
        }

        if (!empty($filters['platform'])) {
            $query->where('platform', $filters['platform']);
        }

        if (!empty($filters['content_type'])) {
            $query->where('content_type', $filters['content_type']);
        }

        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function ($q) use ($searchTerm): void {
                $q->where('title', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%")
                    ->orWhere('requirements', 'like', "%{$searchTerm}%")
                ;
            });
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * Get featured campaigns.
     */
    public function getFeatured(int $limit = 10): Collection
    {
        return Campaign::where('is_featured', true)
            ->where('status', 'active')
            ->where('is_active', true)
            ->with(['user'])
            ->orderBy('featured_at', 'desc')
            ->limit($limit)
            ->get()
        ;
    }

    /**
     * Get trending campaigns (most applications in last 7 days).
     */
    public function getTrending(int $limit = 10): Collection
    {
        return Campaign::query()
            ->where('status', 'active')
            ->where('is_active', true)
            ->withCount(['applications' => function ($query): void {
                $query->where('created_at', '>=', now()->subDays(7));
            }])
            ->having('applications_count', '>', 0)
            ->orderBy('applications_count', 'desc')
            ->limit($limit)
            ->get()
        ;
    }

    /**
     * Get recommended campaigns for a creator.
     */
    public function getRecommendedForCreator(User $creator, int $limit = 10): Collection
    {
        // Get creator's categories from completed campaigns
        $preferredCategories = DB::table('contracts')
            ->join('campaigns', 'contracts.campaign_id', '=', 'campaigns.id')
            ->where('contracts.creator_id', $creator->id)
            ->where('contracts.status', 'completed')
            ->select('campaigns.category')
            ->distinct()
            ->pluck('category')
            ->toArray()
        ;

        return Campaign::where('status', 'active')
            ->where('is_active', true)
            ->whereDoesntHave('applications', function ($query) use ($creator): void {
                $query->where('user_id', $creator->id);
            })
            ->when(!empty($preferredCategories), function ($query) use ($preferredCategories): void {
                $query->whereIn('category', $preferredCategories);
            })
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
        ;
    }

    /**
     * Get similar campaigns.
     */
    public function getSimilar(Campaign $campaign, int $limit = 5): Collection
    {
        return Campaign::where('id', '!=', $campaign->id)
            ->where('status', 'active')
            ->where('is_active', true)
            ->where(function ($query) use ($campaign): void {
                $query->where('category', $campaign->category)
                    ->orWhere('platform', $campaign->platform)
                ;
            })
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
        ;
    }

    /**
     * Get campaigns by brand.
     */
    public function getByBrand(User $brand, ?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = Campaign::where('user_id', $brand->id)
            ->with(['applications', 'contracts'])
        ;

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get campaign statistics.
     */
    public function getStatistics(Campaign $campaign): array
    {
        return [
            'total_applications' => $campaign->applications()->count(),
            'pending_applications' => $campaign->applications()->where('status', 'pending')->count(),
            'approved_applications' => $campaign->applications()->where('status', 'approved')->count(),
            'rejected_applications' => $campaign->applications()->where('status', 'rejected')->count(),
            'active_contracts' => $campaign->contracts()->whereIn('status', ['active', 'pending_delivery'])->count(),
            'completed_contracts' => $campaign->contracts()->where('status', 'completed')->count(),
            'total_budget_used' => $campaign->contracts()->where('status', 'completed')->sum('amount'),
            'views' => $campaign->view_count ?? 0,
            'favorites' => $campaign->favorites()->count(),
        ];
    }

    /**
     * Increment view count.
     */
    public function incrementViewCount(Campaign $campaign): void
    {
        $campaign->increment('view_count');
    }
}

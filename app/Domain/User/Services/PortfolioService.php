<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Helpers\FileUploadHelper;
use App\Models\User\Portfolio;
use App\Models\User\PortfolioItem;
use App\Models\User\User;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Log;

/**
 * PortfolioService handles portfolio management for creators.
 *
 * Responsibilities:
 * - Adding/removing portfolio items
 * - Ordering portfolio
 * - Portfolio statistics
 * - Managing portfolio profile (bio, title, cover)
 */
class PortfolioService
{
    private const STORAGE_PATH = 'portfolios';

    /**
     * Get or create user portfolio.
     */
    public function getPortfolio(User $user): Portfolio
    {
        return $user->portfolio()->firstOrCreate([], [
            'title' => $user->name."'s Portfolio",
        ]);
    }

    /**
     * Get portfolio items.
     */
    public function getItems(User $user): Collection
    {
        return PortfolioItem::where('user_id', $user->id)
            ->orderBy('order', 'asc')
            ->orderBy('created_at', 'desc')
            ->get()
        ;
    }

    /**
     * Update portfolio details (bio, title, picture).
     */
    public function updatePortfolioDetails(User $user, array $data, ?UploadedFile $profilePicture = null): Portfolio
    {
        $portfolio = $this->getPortfolio($user);

        $updateData = [];
        if (isset($data['title'])) {
            $updateData['title'] = $data['title'];
        }
        if (isset($data['bio'])) {
            $updateData['bio'] = $data['bio'];
        }
        if (isset($data['project_links'])) {
            $updateData['project_links'] = $data['project_links'];
        }

        if ($profilePicture) {
            if ($portfolio->profile_picture) {
                FileUploadHelper::delete($portfolio->profile_picture);
            }
            $updateData['profile_picture'] = FileUploadHelper::upload($profilePicture, 'portfolio/'.$user->id);

            // Sync with user avatar if needed
            $user->update(['avatar' => $updateData['profile_picture']]);
        }

        $portfolio->update($updateData);

        Log::info('Portfolio details updated', ['user_id' => $user->id]);

        return $portfolio->fresh();
    }

    /**
     * Add a portfolio item.
     */
    public function addItem(
        User $user,
        array $itemData,
        ?UploadedFile $file = null,
        ?UploadedFile $thumbnail = null
    ): PortfolioItem {
        // Get next order number
        $maxOrder = PortfolioItem::where('user_id', $user->id)->max('order') ?? 0;

        $data = [
            'user_id' => $user->id,
            'title' => $itemData['title'] ?? null,
            'description' => $itemData['description'] ?? null,
            'type' => $itemData['type'] ?? 'image',
            'platform' => $itemData['platform'] ?? null,
            'external_url' => $itemData['external_url'] ?? null,
            'metrics' => $itemData['metrics'] ?? [],
            'tags' => $itemData['tags'] ?? [],
            'order' => $maxOrder + 1,
        ];

        // Upload file if provided
        if ($file) {
            $data['file_url'] = $this->uploadFile($file, $user->id);
        }

        // Upload thumbnail if provided
        if ($thumbnail) {
            $data['thumbnail_url'] = $this->uploadFile($thumbnail, $user->id, 'thumbnails');
        }

        $item = PortfolioItem::create($data);

        Log::info('Portfolio item added', [
            'item_id' => $item->id,
            'user_id' => $user->id,
        ]);

        return $item;
    }

    /**
     * Update a portfolio item.
     */
    public function updateItem(
        PortfolioItem $item,
        array $itemData,
        ?UploadedFile $file = null,
        ?UploadedFile $thumbnail = null
    ): PortfolioItem {
        $data = array_filter([
            'title' => $itemData['title'] ?? null,
            'description' => $itemData['description'] ?? null,
            'type' => $itemData['type'] ?? null,
            'platform' => $itemData['platform'] ?? null,
            'external_url' => $itemData['external_url'] ?? null,
            'metrics' => $itemData['metrics'] ?? null,
            'tags' => $itemData['tags'] ?? null,
        ], fn ($v) => null !== $v);

        // Upload new file if provided
        if ($file) {
            // Delete old file
            if ($item->file_url) {
                $this->deleteFile($item->file_url);
            }
            $data['file_url'] = $this->uploadFile($file, $item->user_id);
        }

        // Upload new thumbnail if provided
        if ($thumbnail) {
            // Delete old thumbnail
            if ($item->thumbnail_url) {
                $this->deleteFile($item->thumbnail_url);
            }
            $data['thumbnail_url'] = $this->uploadFile($thumbnail, $item->user_id, 'thumbnails');
        }

        $item->update($data);

        Log::info('Portfolio item updated', [
            'item_id' => $item->id,
        ]);

        return $item->fresh();
    }

    /**
     * Delete a portfolio item.
     */
    public function deleteItem(PortfolioItem $item): bool
    {
        // Delete associated files
        if ($item->file_url) {
            $this->deleteFile($item->file_url);
        }
        if ($item->thumbnail_url) {
            $this->deleteFile($item->thumbnail_url);
        }

        $itemId = $item->id;
        $item->delete();

        Log::info('Portfolio item deleted', [
            'item_id' => $itemId,
        ]);

        return true;
    }

    /**
     * Reorder portfolio items.
     */
    public function reorder(User $user, array $itemIds): void
    {
        foreach ($itemIds as $order => $itemId) {
            PortfolioItem::where('id', $itemId)
                ->where('user_id', $user->id)
                ->update(['order' => $order + 1])
            ;
        }

        Log::info('Portfolio reordered', [
            'user_id' => $user->id,
            'item_count' => count($itemIds),
        ]);
    }

    /**
     * Get portfolio statistics.
     */
    public function getStatistics(User $user): array
    {
        $items = PortfolioItem::where('user_id', $user->id)->get();

        return [
            'total_items' => $items->count(),
            'items_by_type' => $items->groupBy('type')->map(fn ($group) => $group->count())->toArray(),
            'items_by_platform' => $items->groupBy('platform')->map(fn ($group) => $group->count())->toArray(),
            'total_views' => $items->sum('view_count') ?? 0,
        ];
    }

    /**
     * Increment view count.
     */
    public function incrementViewCount(PortfolioItem $item): void
    {
        $item->increment('view_count');
    }

    /**
     * Upload a file.
     */
    private function uploadFile(UploadedFile $file, int $userId, string $subPath = ''): string
    {
        $path = self::STORAGE_PATH."/{$userId}";
        if ($subPath) {
            $path .= "/{$subPath}";
        }

        $fileName = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
        $filePath = $file->storeAs($path, $fileName, config('filesystems.default'));

        return Storage::url($filePath);
    }

    /**
     * Delete a file.
     */
    private function deleteFile(string $fileUrl): void
    {
        try {
            $path = str_replace('/storage/', '', $fileUrl);
            Storage::disk(config('filesystems.default'))->delete($path);
        } catch (Exception $e) {
            Log::warning('Failed to delete portfolio file', [
                'url' => $fileUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

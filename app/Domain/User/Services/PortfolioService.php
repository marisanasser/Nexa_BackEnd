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
use Illuminate\Support\Facades\Log;

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
        $portfolio = $this->getPortfolio($user);
        
        return PortfolioItem::where('portfolio_id', $portfolio->id)
            ->orderBy('order', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Update portfolio details (bio, title, picture).
     */
    public function updatePortfolioDetails(User $user, array $data, ?UploadedFile $profilePicture = null): Portfolio
    {
        $portfolio = $this->getPortfolio($user);

        $updateData = [];
        $userUpdateData = []; // Data to sync with User model

        if (isset($data['title'])) {
            $updateData['title'] = $data['title'];
        }
        if (isset($data['bio'])) {
            $updateData['bio'] = $data['bio'];
            $userUpdateData['bio'] = $data['bio']; // Sync bio with User
        }
        if (isset($data['whatsapp'])) {
            $userUpdateData['whatsapp'] = $data['whatsapp'];
        }
        if (isset($data['project_links'])) {
            $updateData['project_links'] = $data['project_links'];
        }

        if ($profilePicture) {
            if ($portfolio->profile_picture) {
                FileUploadHelper::delete($portfolio->profile_picture);
            }
            // Use 'avatars' path to be consistent with ProfileController
            $url = FileUploadHelper::upload($profilePicture, 'avatars');
            $updateData['profile_picture'] = $url;
            
            // Sync with user avatar_url
            $userUpdateData['avatar_url'] = $url;

            Log::info('Portfolio profile picture updated and synced with user avatar', [
                'user_id' => $user->id,
                'url' => $url
            ]);
        }

        $portfolio->update($updateData);

        // Perform sync update on User model if there are changes
        if (!empty($userUpdateData)) {
            $user->update($userUpdateData);
            Log::info('Synced Portfolio changes to User profile', [
                'user_id' => $user->id,
                'fields' => array_keys($userUpdateData)
            ]);
        }

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
        $portfolio = $this->getPortfolio($user);
        
        // Get next order number - fixed to check by portfolio_id not user_id
        $maxOrder = PortfolioItem::where('portfolio_id', $portfolio->id)->max('order') ?? 0;

        $data = [
            'portfolio_id' => $portfolio->id,
            'title' => $itemData['title'] ?? null,
            'description' => $itemData['description'] ?? null,
            'media_type' => $itemData['type'] ?? 'image', 
            'order' => $maxOrder + 1,
        ];

        // Upload file if provided
        if ($file) {
            $path = $this->uploadFile($file, $user->id);
            
            if (!$path) {
                throw new Exception("Falha ao fazer upload do arquivo. Por favor, tente novamente.");
            }
            
            // FileUploadHelper::upload returns the path relative to bucket or local storage/
            // We store this relative path in DB. FileUploadHelper::resolveUrl handles both.
            
            $data['file_path'] = $path; 
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_type'] = $file->getMimeType();
            $data['file_size'] = $file->getSize();
        }

        $item = PortfolioItem::create($data);

        Log::info('Portfolio item added', [
            'item_id' => $item->id,
            'portfolio_id' => $portfolio->id,
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
            'media_type' => $itemData['type'] ?? null,
        ], fn ($v) => null !== $v);

        // Upload new file if provided
        if ($file) {
            // Delete old file
            if ($item->file_path) {
                FileUploadHelper::delete($item->file_path);
            }
            
            $path = $this->uploadFile($file, $item->portfolio->user_id);
            if (!$path) {
                throw new Exception("Falha ao fazer upload do arquivo. Por favor, tente novamente.");
            }
            $data['file_path'] = $path;
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_type'] = $file->getMimeType();
            $data['file_size'] = $file->getSize();
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
        $portfolio = $this->getPortfolio($user);

        foreach ($itemIds as $order => $itemId) {
            PortfolioItem::where('id', $itemId)
                ->where('portfolio_id', $portfolio->id)
                ->update(['order' => $order + 1]);
        }

        Log::info('Portfolio reordered', [
            'user_id' => $user->id,
            'portfolio_id' => $portfolio->id,
            'item_count' => count($itemIds),
        ]);
    }

    /**
     * Get portfolio statistics.
     */
    public function getStatistics(User $user): array
    {
        $portfolio = $this->getPortfolio($user);
        $items = PortfolioItem::where('portfolio_id', $portfolio->id)->get();

        return [
            'total_items' => $items->count(),
            'items_by_type' => $items->groupBy('media_type')->map(fn ($group) => $group->count())->toArray(),
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

        $url = FileUploadHelper::upload($file, $path);

        if (!$url) {
            throw new Exception('Failed to upload file');
        }

        return $url;
    }

    /**
     * Delete a file.
     */
    private function deleteFile(string $fileUrl): void
    {
        FileUploadHelper::delete($fileUrl);
    }
}
